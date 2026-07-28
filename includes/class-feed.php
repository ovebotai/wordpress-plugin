<?php
defined( 'ABSPATH' ) || exit;

class Ovebotai_Feed {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_routes() {
		add_action( 'rest_api_init', function () {
			register_rest_route( 'ovebotai/v1', '/feed', array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'serve_feed' ),
				'permission_callback' => array( $this, 'check_hash' ),
			) );
		} );
	}

	public function check_hash( WP_REST_Request $request ) {
		// Task 3: the on-site chat is the master switch. With it off, the product
		// feed has nothing to feed, so it's served as forbidden instead of handing
		// Ovebot.ai a live catalog it would keep polling for no reason.
		if ( ! Ovebotai::chat_enabled() ) {
			return new WP_Error(
				'ovebotai_feed_chat_disabled',
				__( 'The product feed is disabled while the chat is turned off.', 'ovebot-ai-chatbot-sales-agent' ),
				array( 'status' => 403 )
			);
		}

		// The merchant chose to manage products directly on Ovebot.ai instead of
		// this module's automatic feed — so this endpoint is intentionally not
		// the feed Ovebot.ai reads anymore. Respond forbidden rather than serving
		// data, so it isn't confusing leftover surface area (item 10).
		if ( Ovebotai::products_use_own_feed() ) {
			return new WP_Error(
				'ovebotai_feed_disabled',
				__( 'The automatic product feed is disabled — products are managed directly on Ovebot.ai.', 'ovebot-ai-chatbot-sales-agent' ),
				array( 'status' => 403 )
			);
		}

		// "Recommend products" master switch off - the agent isn't supposed to
		// recommend anything right now, so there's no reason to keep serving it
		// a live catalog either.
		if ( ! Ovebotai::products_enabled() ) {
			return new WP_Error(
				'ovebotai_feed_products_disabled',
				__( 'The product feed is disabled while product recommendations are turned off.', 'ovebot-ai-chatbot-sales-agent' ),
				array( 'status' => 403 )
			);
		}

		$stored = (string) get_option( 'ovebotai_feed_hash', '' );
		$given  = sanitize_text_field( (string) $request->get_param( 'hash' ) );

		return $stored !== '' && $given !== '' && hash_equals( $stored, $given );
	}

	public function serve_feed( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Ovebotai::woocommerce_active() ) {
			return new WP_REST_Response( array(), 200 );
		}

		return new WP_REST_Response( $this->build_feed(), 200 );
	}

	// Only products that are actually purchasable go on the feed. For managed-stock
	// products, availability/quantity are derived from real stock qty + _backorders
	// (backorders allowed => "in_stock" even at qty 0). For unmanaged-stock products,
	// availability just relays _stock_status ("onbackorder" => "preorder"). Products
	// that end up "out_of_stock" either way are never included.
	private function build_feed(): array {
		$currency = Ovebotai::store_currency();

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			// Filtering by stock status + price has no non-meta_query equivalent
			// in WooCommerce's product schema.
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array( 'key' => '_stock_status', 'value' => array( 'instock', 'onbackorder' ), 'compare' => 'IN' ),
				// Ovebot rejects rows with a non-positive price ("missing required
				// field(s)") — exclude them here instead of after the fact.
				array( 'key' => '_price', 'value' => 0, 'compare' => '>', 'type' => 'NUMERIC' ),
			),
		);

		$product_ids = get_posts( $args );
		$data        = array();

		foreach ( $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product || ! $product->is_visible() ) continue;

			$manage_stock = $product->get_manage_stock();

			if ( $manage_stock ) {
				// Managed stock: trust our own quantity/backorder math over the
				// (possibly stale) _stock_status meta. Net out stock already held
				// by unpaid/pending orders (WooCommerce's checkout hold window) so
				// we don't advertise quantity that's already spoken for.
				$held       = (int) wc_get_held_stock_quantity( $product );
				$quantity   = max( 0, (int) $product->get_stock_quantity() - $held );
				$backorders = $product->get_backorders(); // 'no' | 'notify' | 'yes'

				if ( $quantity <= 0 ) {
					$availability = ( 'no' === $backorders ) ? 'out_of_stock' : 'in_stock';
				} else {
					$availability = 'in_stock';
				}
			} else {
				// Unmanaged stock: no quantity to report, just relay _stock_status.
				$quantity = null;
				switch ( $product->get_stock_status() ) {
					case 'instock':
						$availability = 'in_stock';
						break;
					case 'onbackorder':
						$availability = 'preorder';
						break;
					default:
						$availability = 'out_of_stock';
				}
			}

			if ( 'out_of_stock' === $availability ) continue;

			$price = (float) $product->get_price();

			// Defense in depth: the meta_query above already excludes non-positive
			// _price at the SQL level, but that meta can lag the live computed
			// price (e.g. a scheduled sale that just ended) — re-check here too.
			if ( $price <= 0 ) continue;

			$regular = (float) $product->get_regular_price();
			$special = $product->is_on_sale() ? $price : null;
			$display = $product->is_on_sale() ? $regular : $price;

			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_url( $image_id ) : null;

			$category = $this->get_category_path( $pid );
			$brand    = $this->get_brand( $pid );

			$attributes = array();
			foreach ( $product->get_attributes() as $attr ) {
				if ( $attr->is_taxonomy() ) {
					$terms = get_the_terms( $pid, $attr->get_name() );
					if ( $terms ) {
						$attributes[ wc_attribute_label( $attr->get_name() ) ] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
					}
				} else {
					$attributes[ $attr->get_name() ] = implode( ', ', $attr->get_options() );
				}
			}

			// strip_shortcodes() first: shortcode brackets like [gallery] or
			// [contact-form-7] aren't HTML tags, so wp_strip_all_tags() alone
			// would leave the raw "[shortcode attr=...]" text in the feed.
			$description = strip_shortcodes( $product->get_short_description() ?: $product->get_description() );
			$description = wp_strip_all_tags( $description );
			$description = html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$description = trim( (string) preg_replace( '/\s+/', ' ', $description ) );

			$row = array(
				'ref'          => (string) $pid,
				'name'         => $product->get_name(),
				'description'  => $description,
				'category'     => $category,
				'manufacturer' => $brand,
				'availability' => $availability,
				'price'        => round( $display, 2 ),
				'currency'     => $currency,
				'image'        => $image_url ?: null,
				'url'          => get_permalink( $pid ),
				'attributes'   => $attributes,
			);

			// Quantity and special are optional: omit them entirely rather than
			// sending null.
			if ( null !== $quantity ) {
				$row['quantity'] = $quantity;
			}
			if ( null !== $special ) {
				$row['special'] = round( $special, 2 );
			}

			$data[] = $row;
		}

		return $data;
	}

	private function get_brand( int $product_id ): ?string {
		$brands = wp_get_post_terms($product_id, 'product_brand');

		return $brands ? implode( ' | ', wp_list_pluck( $brands, 'name' ) ) : null;
	}

	private function get_category_path( int $product_id ): ?string {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) return null;

		$paths = array();

		foreach ( $terms as $term ) {
			$ancestors = get_ancestors( $term->term_id, 'product_cat' );
			$parts     = array_reverse( array_map(
				function( $id ) { $t = get_term( $id, 'product_cat' ); return $t ? $t->name : ''; },
				$ancestors
			) );
			$parts[] = $term->name;
			$paths[] = implode( ' > ', $parts );
		}

		return $paths ? implode( ' | ', $paths ) : null;
	}
}

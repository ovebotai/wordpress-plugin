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

			// Variable products are published one row per variation ("white
			// T-shirt, size M" is its own product, with its own image, price and
			// stock), so the agent recommends - and adds to the cart - the exact
			// combination. The parent itself is never a row: it can't be added
			// to the cart without choosing a variation. Variations that aren't
			// visible/purchasable (unpublished, no price) are skipped.
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation || ! $variation->is_type( 'variation' ) ) continue;
					if ( ! $variation->variation_is_visible() || ! $variation->is_purchasable() ) continue;

					$row = $this->build_row( $variation, $product, $currency );
					if ( $row ) {
						$data[] = $row;
					}
				}
				continue;
			}

			$row = $this->build_row( $product, null, $currency );
			if ( $row ) {
				$data[] = $row;
			}
		}

		return $data;
	}

	// One feed row for a simple product ($parent null) or for a single variation
	// ($parent = its variable product). Returns null when the product is out of
	// stock or unpriced. 'ref' is the WooCommerce product/variation id - the same
	// id the native add-to-cart endpoint (?wc-ajax=add_to_cart) accepts as
	// product_id, so for a variation the exact combination gets added.
	private function build_row( WC_Product $product, ?WC_Product $parent, string $currency ): ?array {
		$pid       = $product->get_id();
		$parent_id = $parent ? $parent->get_id() : $pid;

		// For a variation, get_manage_stock() returns 'parent' when stock is
		// tracked on the variable product; get_stock_quantity()/get_backorders()
		// then resolve to the parent's values, so the same math applies.
		$manage_stock = $product->get_manage_stock();

		if ( $manage_stock ) {
			// Managed stock: trust our own quantity/backorder math over the
			// (possibly stale) _stock_status meta. Net out stock already held
			// by unpaid/pending orders (WooCommerce's checkout hold window) so
			// we don't advertise quantity that's already spoken for.
			// wc_get_held_stock_quantity() only exists since WooCommerce 4.3 -
			// on older WC versions no hold window is netted out.
			$held       = function_exists( 'wc_get_held_stock_quantity' ) ? (int) wc_get_held_stock_quantity( $product ) : 0;
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

		if ( 'out_of_stock' === $availability ) return null;

		$price = (float) $product->get_price();

		// Defense in depth: the meta_query above already excludes non-positive
		// _price at the SQL level, but that meta can lag the live computed
		// price (e.g. a scheduled sale that just ended) — re-check here too.
		if ( $price <= 0 ) return null;

		$regular = (float) $product->get_regular_price();
		$special = $product->is_on_sale() ? $price : null;
		$display = $product->is_on_sale() ? $regular : $price;

		// A variation without its own image inherits the parent's (WC does this
		// in WC_Product_Variation::get_image_id()).
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_url( $image_id ) : null;

		// Categories and brands are taxonomies of the parent product.
		$category = $this->get_category_path( $parent_id );
		$brand    = $this->get_brand( $parent_id );

		// Parent-level attributes (for a simple product: all of them; for a
		// variation: the non-variation ones, e.g. "Material: Cotton"), then the
		// variation's own chosen values ("Color: White", "Size: M") on top.
		$attributes = array();
		foreach ( ( $parent ?: $product )->get_attributes() as $attr ) {
			if ( $parent && $attr->get_variation() ) continue;

			if ( $attr->is_taxonomy() ) {
				$terms = get_the_terms( $parent_id, $attr->get_name() );
				if ( $terms ) {
					$attributes[ wc_attribute_label( $attr->get_name() ) ] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
				}
			} else {
				$attributes[ $attr->get_name() ] = implode( ', ', $attr->get_options() );
			}
		}

		if ( $parent ) {
			foreach ( $product->get_attributes() as $taxonomy => $value ) {
				// '' means "Any ..." - the variation doesn't pin this attribute.
				if ( '' === (string) $value ) continue;

				if ( taxonomy_exists( $taxonomy ) ) {
					$term  = get_term_by( 'slug', $value, $taxonomy );
					$value = $term ? $term->name : $value;
				}

				$attributes[ wc_attribute_label( $taxonomy, $parent ) ] = $value;
			}
		}

		// strip_shortcodes() first: shortcode brackets like [gallery] or
		// [contact-form-7] aren't HTML tags, so wp_strip_all_tags() alone
		// would leave the raw "[shortcode attr=...]" text in the feed.
		// A variation's own description comes first, then the parent's.
		$description = $product->get_description();
		if ( '' === $description && $parent ) {
			$description = $parent->get_short_description() ?: $parent->get_description();
		} elseif ( ! $parent ) {
			$description = $product->get_short_description() ?: $description;
		}
		$description = strip_shortcodes( $description );
		$description = wp_strip_all_tags( $description );
		$description = html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$description = trim( (string) preg_replace( '/\s+/', ' ', $description ) );

		$row = array(
			'ref'          => (string) $pid,
			// For a variation WC's generated title already reads
			// "Parent name - White, M".
			'name'         => $product->get_name(),
			'description'  => $description,
			'category'     => $category,
			'manufacturer' => $brand,
			'availability' => $availability,
			'price'        => round( $display, 2 ),
			'currency'     => $currency,
			'image'        => $image_url ?: null,
			// For a variation this is the parent's permalink with the variation's
			// attributes as query args, so the product page opens pre-selected.
			'url'          => $product->get_permalink(),
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

		// Optional columns - only present when the product has a value. A
		// variation's SKU falls back to the parent's (WC does that itself).
		$sku = trim( (string) $product->get_sku() );
		if ( '' !== $sku ) {
			$row['sku'] = $sku;
		}

		// WooCommerce's native "GTIN, UPC, EAN, or ISBN" field (WC 9.1+); the
		// method doesn't exist on older WC, in which case the column is omitted.
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = trim( (string) $product->get_global_unique_id() );
			if ( '' !== $gtin ) {
				$row['gtin'] = $gtin;
			}
		}

		// Gallery images as absolute URLs, in gallery order. The gallery lives
		// on the parent product only, so a variation gets its parent's gallery
		// (minus the image already sent as the main one).
		$gallery = array();
		foreach ( ( $parent ?: $product )->get_gallery_image_ids() as $gallery_id ) {
			if ( (int) $gallery_id === (int) $image_id ) continue;

			$gallery_url = wp_get_attachment_url( $gallery_id );
			if ( $gallery_url ) {
				$gallery[] = $gallery_url;
			}
		}
		if ( $gallery ) {
			$row['additional_image_link'] = $gallery;
		}

		return $row;
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

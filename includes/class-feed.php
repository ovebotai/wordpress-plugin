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

	public function check_hash( WP_REST_Request $request ): bool {
		$stored = (string) get_option( 'ovebotai_feed_hash', '' );
		$given  = sanitize_text_field( (string) $request->get_param( 'hash' ) );

		return $stored !== '' && $given !== '' && hash_equals( $stored, $given );
	}

	public function serve_feed( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Ovebotai::woocommerce_active() ) {
			return new WP_REST_Response( array(), 200 );
		}

		$cache_version = (int) get_option( 'ovebotai_cache_version', 1 );
		$cache_key     = 'ovebotai_feed_v' . $cache_version;

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return new WP_REST_Response( $cached, 200 );
		}

		$data = $this->build_feed();
		set_transient( $cache_key, $data, 15 * MINUTE_IN_SECONDS );

		return new WP_REST_Response( $data, 200 );
	}

	// Only products that are actually purchasable go on the feed: in stock, or
	// on backorder (sent with availability "preorder"). Plain out-of-stock
	// products are never included.
	private function build_feed(): array {
		$lang     = get_bloginfo( 'language' );
		$currency = Ovebotai::store_currency();

		$args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => array(
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

			$stock_status = $product->get_stock_status();
			if ( 'outofstock' === $stock_status ) continue;
			$availability = 'onbackorder' === $stock_status ? 'preorder' : 'in_stock';

			$price   = (float) $product->get_price();
			$regular = (float) $product->get_regular_price();
			$special = $product->is_on_sale() ? $price : null;
			$display = $product->is_on_sale() ? $regular : $price;

			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_url( $image_id ) : null;

			$category = $this->get_category_path( $pid );

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

			$description = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
			$description = html_entity_decode( $description, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$description = trim( (string) preg_replace( '/\s+/', ' ', $description ) );

			$data[] = array(
				'ref'          => $product->get_sku() ?: (string) $pid,
				'name'         => $product->get_name(),
				'description'  => $description,
				'category'     => $category,
				'manufacturer' => $product->get_attribute( 'pa_brand' ) ?: null,
				'availability' => $availability,
				'quantity'     => $product->get_stock_quantity() ?? 0,
				'price'        => round( $display, 2 ),
				'special'      => $special !== null ? round( $special, 2 ) : null,
				'currency'     => $currency,
				'image'        => $image_url ?: null,
				'url'          => get_permalink( $pid ),
				'attributes'   => $attributes,
				'lang'         => $lang,
			);
		}

		return $data;
	}

	private function get_category_path( int $product_id ): ?string {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( ! $terms || is_wp_error( $terms ) ) return null;

		$best_path  = '';
		$best_depth = -1;

		foreach ( $terms as $term ) {
			$ancestors = get_ancestors( $term->term_id, 'product_cat' );
			$depth     = count( $ancestors );
			if ( $depth > $best_depth ) {
				$parts = array_reverse( array_map(
					function( $id ) { $t = get_term( $id, 'product_cat' ); return $t ? $t->name : ''; },
					$ancestors
				) );
				$parts[]    = $term->name;
				$best_depth = $depth;
				$best_path  = implode( ' > ', $parts );
			}
		}

		return $best_path ?: null;
	}
}

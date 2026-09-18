<?php
/**
 * Product search for the point of sale.
 *
 * YITH POS searches products by exact title only, caps the results to 9/10
 * items, hides anything the REST API does not report as purchasable and blocks
 * the click on out-of-stock results. For a print shop that means half of the
 * catalogue is impossible to reach from the search box. This module replaces
 * that search with a multi-term one (title, description, SKU and variation
 * SKU) and makes every result selectable.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IO_POS_Search
 */
class IO_POS_Search {

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! IO_POS_Settings::is_enabled( 'search_enabled' ) ) {
			return;
		}

		// Run after YITH POS (priority 10) so we can replace its query.
		add_filter( 'woocommerce_rest_product_object_query', array( $this, 'filter_product_query' ), 20, 2 );
		add_filter( 'woocommerce_rest_prepare_product_object', array( $this, 'make_product_selectable' ), 20, 3 );
		add_filter( 'woocommerce_rest_prepare_product_variation_object', array( $this, 'make_product_selectable' ), 20, 3 );

		// Let YITH POS ask the database for more rows than its default of 9.
		add_filter( 'yith_pos_search_products_per_page', array( $this, 'get_limit' ) );

		// Raise the limit used by the POS app itself.
		add_filter( 'yith_pos_components_frontend_settings', array( $this, 'filter_frontend_settings' ) );
	}

	/**
	 * Maximum number of results returned by the search box.
	 *
	 * @return int
	 */
	public function get_limit() {
		return IO_POS_Settings::get_int( 'search_max_results', 1, 100 );
	}

	/**
	 * Raise the result limit used by the POS React app.
	 *
	 * @param array $settings Frontend settings.
	 *
	 * @return array
	 */
	public function filter_frontend_settings( $settings ) {
		$settings['maxProductSearchResults'] = $this->get_limit();

		return $settings;
	}

	/**
	 * Replace the POS product search with our own one.
	 *
	 * @param array           $args    Query args.
	 * @param WP_REST_Request $request The REST request.
	 *
	 * @return array
	 */
	public function filter_product_query( $args, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $args;
		}

		$pos_request = (string) ( $request['yith_pos_request'] ?? '' );

		if ( ! in_array( $pos_request, array( 'search-products', 'get-products' ), true ) ) {
			return $args;
		}

		if ( IO_POS_Settings::is_enabled( 'search_force_selectable' ) ) {
			$args = $this->remove_price_restriction( $args );
		}

		if ( 'search-products' !== $pos_request ) {
			return $args;
		}

		// Barcode scanning is handled by YITH POS; do not get in the way.
		$scan = $request['yith_pos_scan'] ?? null;
		if ( is_null( $scan ) || 'no' !== $scan ) {
			return $args;
		}

		$search = trim( (string) ( $args['s'] ?? $request['search'] ?? '' ) );

		if ( '' === $search ) {
			return $args;
		}

		$ids = $this->search_ids( $search );

		unset( $args['s'] );

		// An empty result set must return nothing, not the whole catalogue.
		$args['post__in'] = $ids ? $ids : array( 0 );
		$args['orderby']  = 'post__in';

		unset( $args['order'] );

		if ( $this->has_variations( $ids ) ) {
			$this->allow_variations_in_query();
		}

		return $args;
	}

	/**
	 * Drop the "only products with a price" restriction added by YITH POS.
	 *
	 * A print shop quotes most of its work by hand, so products without a
	 * price still need to be reachable from the register.
	 *
	 * @param array $args Query args.
	 *
	 * @return array
	 */
	protected function remove_price_restriction( $args ) {
		if ( empty( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			return $args;
		}

		foreach ( $args['meta_query'] as $index => $clause ) {
			if ( is_array( $clause ) && '_price' === ( $clause['key'] ?? '' ) && '!=' === ( $clause['compare'] ?? '' ) ) {
				unset( $args['meta_query'][ $index ] );
			}
		}

		$args['meta_query'] = array_values( $args['meta_query'] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

		if ( ! $args['meta_query'] ) {
			unset( $args['meta_query'] );
		}

		return $args;
	}

	/**
	 * Search product (and variation) IDs matching every term of the search.
	 *
	 * @param string $search The raw search string.
	 * @param int    $limit  Maximum number of rows; zero uses the setting.
	 *
	 * @return int[]
	 */
	public function search_ids( $search, $limit = 0 ) {
		global $wpdb;

		$limit = $limit > 0 ? (int) $limit : $this->get_limit();
		$terms = $this->split_terms( $search );

		if ( ! $terms ) {
			return array();
		}

		$barcode_meta = function_exists( 'yith_pos_get_barcode_meta' ) ? yith_pos_get_barcode_meta() : '_sku';
		$meta_keys    = array_values( array_unique( array_filter( array( '_sku', $barcode_meta ) ) ) );

		$joins      = array();
		$sku_fields = array();

		foreach ( $meta_keys as $index => $meta_key ) {
			$alias = 'iosku' . $index;

			$joins[]      = $wpdb->prepare( "LEFT JOIN {$wpdb->postmeta} {$alias} ON ( {$alias}.post_id = p.ID AND {$alias}.meta_key = %s )", $meta_key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sku_fields[] = "{$alias}.meta_value";
		}

		$where = array( "p.post_type = 'product'", "p.post_status = 'publish'" );

		foreach ( $terms as $term ) {
			$like       = '%' . $wpdb->esc_like( $term ) . '%';
			$conditions = array( $wpdb->prepare( 'p.post_title LIKE %s', $like ) );

			if ( IO_POS_Settings::is_enabled( 'search_include_description' ) ) {
				$conditions[] = $wpdb->prepare( 'p.post_excerpt LIKE %s', $like );
			}

			if ( IO_POS_Settings::is_enabled( 'search_include_sku' ) ) {
				foreach ( $sku_fields as $sku_field ) {
					$conditions[] = $wpdb->prepare( "{$sku_field} LIKE %s", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}

			$where[] = '( ' . implode( ' OR ', $conditions ) . ' )';
		}

		$starts_with = $wpdb->esc_like( $terms[0] ) . '%';

		$sql = sprintf(
			'SELECT DISTINCT p.ID FROM %1$s p %2$s WHERE %3$s ORDER BY ( CASE WHEN p.post_title LIKE %4$s THEN 0 ELSE 1 END ), p.post_title ASC LIMIT %5$d',
			$wpdb->posts,
			implode( ' ', $joins ),
			implode( ' AND ', $where ),
			$wpdb->prepare( '%s', $starts_with ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_map( 'absint', (array) $wpdb->get_col( $sql ) );

		if ( count( $ids ) < $limit && IO_POS_Settings::is_enabled( 'search_include_variations' ) && IO_POS_Settings::is_enabled( 'search_include_sku' ) ) {
			$ids = array_merge( $ids, $this->search_variation_ids( $terms, $meta_keys, $limit - count( $ids ) ) );
		}

		/**
		 * Filter the IDs found by the POS search.
		 *
		 * @param int[]  $ids    Found IDs.
		 * @param string $search The search string.
		 */
		return apply_filters( 'io_pos_search_ids', array_values( array_unique( $ids ) ), $search );
	}

	/**
	 * Search variations by SKU or barcode.
	 *
	 * Variations are not matched by title on purpose: their titles repeat the
	 * parent name, so matching them would flood the results with duplicates.
	 *
	 * @param string[] $terms     Search terms.
	 * @param string[] $meta_keys Meta keys holding the SKU/barcode.
	 * @param int      $limit     Maximum number of rows.
	 *
	 * @return int[]
	 */
	protected function search_variation_ids( $terms, $meta_keys, $limit ) {
		global $wpdb;

		if ( $limit < 1 ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $meta_keys ), '%s' ) );
		$where        = array(
			"p.post_type = 'product_variation'",
			"p.post_status IN ( 'publish', 'private' )",
			$wpdb->prepare( "sku.meta_key IN ( {$placeholders} )", $meta_keys ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		foreach ( $terms as $term ) {
			$where[] = $wpdb->prepare( 'sku.meta_value LIKE %s', '%' . $wpdb->esc_like( $term ) . '%' );
		}

		$sql = sprintf(
			'SELECT DISTINCT p.ID FROM %1$s p INNER JOIN %2$s sku ON ( sku.post_id = p.ID ) WHERE %3$s LIMIT %4$d',
			$wpdb->posts,
			$wpdb->postmeta,
			implode( ' AND ', $where ),
			$limit
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'absint', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * Split a search string into the terms that must all match.
	 *
	 * Quoted chunks are kept together, so "tarjeta 9x5" can be searched as a
	 * single phrase when needed.
	 *
	 * @param string $search The search string.
	 *
	 * @return string[]
	 */
	protected function split_terms( $search ) {
		$terms = array();

		if ( preg_match_all( '/"([^"]+)"|(\S+)/u', $search, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$term = trim( '' !== $match[1] ? $match[1] : ( $match[2] ?? '' ) );

				if ( '' !== $term ) {
					$terms[] = $term;
				}
			}
		}

		// More than a handful of terms means an unusable query; keep the first ones.
		return array_slice( $terms, 0, 6 );
	}

	/**
	 * Whether the list of IDs contains at least one variation.
	 *
	 * @param int[] $ids Post IDs.
	 *
	 * @return bool
	 */
	protected function has_variations( $ids ) {
		foreach ( $ids as $id ) {
			if ( 'product_variation' === get_post_type( $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Let the next WP_Query return variations too.
	 *
	 * The callback removes itself right after the first query, so it cannot
	 * leak into anything else running in the same request.
	 */
	protected function allow_variations_in_query() {
		$callback = function ( $query ) use ( &$callback ) {
			$query->query_vars['post_type'] = array( 'product', 'product_variation' );

			remove_action( 'pre_get_posts', $callback, 999 );
		};

		add_action( 'pre_get_posts', $callback, 999 );
	}

	/**
	 * Make POS products selectable from the search box.
	 *
	 * The POS app drops every product whose REST response is not purchasable
	 * and refuses to add products it considers out of stock. In a print shop
	 * most items are made to order, so both checks hide perfectly sellable
	 * products.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param WC_Product       $product  The product.
	 * @param WP_REST_Request  $request  The request.
	 *
	 * @return WP_REST_Response
	 */
	public function make_product_selectable( $response, $product, $request ) {
		if ( ! IO_POS_Settings::is_enabled( 'search_force_selectable' ) ) {
			return $response;
		}

		if ( ! $request instanceof WP_REST_Request || ! isset( $request['yith_pos_request'] ) ) {
			return $response;
		}

		if ( ! is_object( $response ) || ! isset( $response->data ) || ! is_array( $response->data ) ) {
			return $response;
		}

		if ( isset( $response->data['purchasable'] ) ) {
			$response->data['purchasable'] = true;
		}

		if ( array_key_exists( 'backorders_allowed', $response->data ) ) {
			$response->data['backorders_allowed'] = true;
		}

		return $response;
	}
}

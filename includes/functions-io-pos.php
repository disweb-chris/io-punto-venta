<?php
/**
 * Helper functions.
 *
 * @package IO\POS
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'io_pos' ) ) {
	/**
	 * Main plugin instance.
	 *
	 * @return IO_POS_Plugin
	 */
	function io_pos() {
		return IO_POS_Plugin::instance();
	}
}

if ( ! function_exists( 'io_pos_normalize_date' ) ) {
	/**
	 * Normalize a date to the Y-m-d format.
	 *
	 * Accepts the ISO format used by the POS and the usual local formats, so a
	 * value typed by hand in the admin is stored the same way as one coming
	 * from the register.
	 *
	 * @param mixed $value Raw date.
	 *
	 * @return string The normalized date, or an empty string when invalid.
	 */
	function io_pos_normalize_date( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $matches ) ) {
			return wp_checkdate( (int) $matches[2], (int) $matches[3], (int) $matches[1], $value ) ? "{$matches[1]}-{$matches[2]}-{$matches[3]}" : '';
		}

		if ( preg_match( '#^(\d{1,2})[/-](\d{1,2})[/-](\d{2,4})$#', $value, $matches ) ) {
			$day   = (int) $matches[1];
			$month = (int) $matches[2];
			$year  = (int) $matches[3];
			$year  = $year < 100 ? 2000 + $year : $year;

			return wp_checkdate( $month, $day, $year, $value ) ? sprintf( '%04d-%02d-%02d', $year, $month, $day ) : '';
		}

		$timestamp = strtotime( $value );

		return $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';
	}
}

if ( ! function_exists( 'io_pos_format_date' ) ) {
	/**
	 * Format a Y-m-d date using the date format configured in WordPress.
	 *
	 * @param string $date Date in Y-m-d format.
	 *
	 * @return string
	 */
	function io_pos_format_date( $date ) {
		$date = io_pos_normalize_date( $date );

		if ( ! $date ) {
			return '';
		}

		$timestamp = strtotime( $date . ' 12:00:00' );

		return wp_date( (string) get_option( 'date_format', 'd/m/Y' ), $timestamp );
	}
}

if ( ! function_exists( 'io_pos_today' ) ) {
	/**
	 * Today's date in the site timezone, as Y-m-d.
	 *
	 * @return string
	 */
	function io_pos_today() {
		return current_datetime()->format( 'Y-m-d' );
	}
}

if ( ! function_exists( 'io_pos_days_until' ) ) {
	/**
	 * Number of days between today and a date. Negative when already past.
	 *
	 * @param string $date Date in Y-m-d format.
	 *
	 * @return int|null Null when the date is invalid.
	 */
	function io_pos_days_until( $date ) {
		$date = io_pos_normalize_date( $date );

		if ( ! $date ) {
			return null;
		}

		$today  = new DateTimeImmutable( io_pos_today(), wp_timezone() );
		$target = new DateTimeImmutable( $date, wp_timezone() );

		return (int) $today->diff( $target )->format( '%r%a' );
	}
}

if ( ! function_exists( 'io_pos_delivery_state' ) ) {
	/**
	 * Classify a delivery date so the UI can highlight it.
	 *
	 * @param string $date Date in Y-m-d format.
	 *
	 * @return string One of: overdue, today, soon, scheduled, none.
	 */
	function io_pos_delivery_state( $date ) {
		$days = io_pos_days_until( $date );

		if ( is_null( $days ) ) {
			return 'none';
		}

		if ( $days < 0 ) {
			return 'overdue';
		}

		if ( 0 === $days ) {
			return 'today';
		}

		return $days <= 2 ? 'soon' : 'scheduled';
	}
}

if ( ! function_exists( 'io_pos_get_order' ) ) {
	/**
	 * Get an order object from an ID, a post or an order.
	 *
	 * @param mixed $order Order, order ID or post.
	 *
	 * @return WC_Order|false
	 */
	function io_pos_get_order( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		return $order instanceof WC_Order ? $order : false;
	}
}

if ( ! function_exists( 'io_pos_is_pos_order' ) ) {
	/**
	 * Whether an order was created from the point of sale.
	 *
	 * @param mixed $order Order, order ID or post.
	 *
	 * @return bool
	 */
	function io_pos_is_pos_order( $order ) {
		$order = io_pos_get_order( $order );

		if ( ! $order ) {
			return false;
		}

		if ( function_exists( 'yith_pos_is_pos_order' ) ) {
			return (bool) yith_pos_is_pos_order( $order );
		}

		return (bool) absint( $order->get_meta( '_yith_pos_order' ) );
	}
}

if ( ! function_exists( 'io_pos_is_job_order' ) ) {
	/**
	 * Whether an order should be treated as a print job.
	 *
	 * @param mixed $order Order, order ID or post.
	 *
	 * @return bool
	 */
	function io_pos_is_job_order( $order ) {
		$order = io_pos_get_order( $order );

		return $order && IO_POS_Job::has_job_data( $order );
	}
}

if ( ! function_exists( 'io_pos_get_balance_due' ) ) {
	/**
	 * Pending balance of an order.
	 *
	 * @param mixed $order Order, order ID or post.
	 *
	 * @return float
	 */
	function io_pos_get_balance_due( $order ) {
		$order = io_pos_get_order( $order );

		if ( ! $order ) {
			return 0.0;
		}

		return (float) wc_format_decimal( $order->get_meta( IO_POS_Job::META_BALANCE ), wc_get_price_decimals() );
	}
}

if ( ! function_exists( 'io_pos_is_pos_search_request' ) ) {
	/**
	 * Whether the current REST request is a product search coming from the POS.
	 *
	 * @param WP_REST_Request|null $request The request.
	 *
	 * @return bool
	 */
	function io_pos_is_pos_search_request( $request = null ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		return 'search-products' === ( $request['yith_pos_request'] ?? '' );
	}
}

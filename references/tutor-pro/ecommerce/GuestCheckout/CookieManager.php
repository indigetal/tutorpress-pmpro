<?php
/**
 * Handle guest cookies
 *
 * @package TutorPro\GuestCheckout
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

 namespace TutorPro\Ecommerce\GuestCheckout;

use TUTOR\Input;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage guest cookies
 */
class CookieManager {

	const COOKIE_BILLING_COUNTRY = 'tutor_guest_billing_country';
	const COOKIE_BILLING_STATE   = 'tutor_guest_billing_state';
	const COOKIE_GUEST_CART      = 'tutor_guest_cart';

	/**
	 * Cookie expiry time in seconds (30 days)
	 *
	 * @since 3.3.0
	 *
	 * @var int
	 */
	const COOKIE_EXPIRY = DAY_IN_SECONDS * 30;

	/**
	 * Set cart item
	 *
	 * @since 3.3.0
	 *
	 * @param int $item_id Item id to set.
	 *
	 * @throws \Exception If the course is already in the cart.
	 *
	 * @return void
	 */
	public static function set_cart_item( $item_id ) {
		$current_cart = self::get_cart_items();

		if ( ! in_array( $item_id, $current_cart ) ) {
			$current_cart[] = $item_id;
			setcookie(
				self::COOKIE_GUEST_CART,
				json_encode( $current_cart ),
				time() + self::COOKIE_EXPIRY,
				'/',
				'',
				false,
				true
			);
			$_COOKIE[ self::COOKIE_GUEST_CART ] = json_encode( $current_cart );
		} else {
			throw new \Exception( __( 'The course is already in the cart.', 'tutor-pro' ) );
		}
	}

	/**
	 * Get all course IDs from the cookie
	 *
	 * @since 3.3.0
	 *
	 * @return array
	 */
	public static function get_cart_items() {
		if ( isset( $_COOKIE[ self::COOKIE_GUEST_CART ] ) ) {
			$cart = json_decode( wp_unslash( $_COOKIE[ self::COOKIE_GUEST_CART ] ), true );

			// Ensure the cart is always an array.
			return is_array( $cart ) ? $cart : array();
		}

		return array();
	}

	/**
	 * Update cart as per data provided
	 *
	 * @since 3.3.0
	 *
	 * @param array $cart_data Cart data to update.
	 *
	 * @return void
	 */
	public static function update_cart( array $cart_data ) {
		$cart_data = wp_json_encode( $cart_data );
		setcookie(
			self::COOKIE_GUEST_CART,
			$cart_data,
			time() + self::COOKIE_EXPIRY,
			'/',
			'',
			false,
			true
		);
		$_COOKIE[ self::COOKIE_GUEST_CART ] = $cart_data;
	}

	/**
	 * Set the billing country in a cookie.
	 *
	 * @since 3.3.0
	 *
	 * @param string $country_name The country code to set.
	 * @param int    $expiry Expiry time in seconds (default: 1 day).
	 */
	public static function set_billing_country( $country_name, $expiry = DAY_IN_SECONDS ) {
		if ( ! empty( $country_name ) ) {
			setcookie( self::COOKIE_BILLING_COUNTRY, $country_name, time() + $expiry, '/', '', false, true );
			$_COOKIE[ self::COOKIE_BILLING_COUNTRY ] = $country_name;
		}
	}

	/**
	 * Set the billing state in a cookie.
	 *
	 * @since 3.3.0
	 *
	 * @param string $state_name The state code to set.
	 * @param int    $expiry Expiry time in seconds (default: 1 day).
	 */
	public static function set_billing_state( $state_name, $expiry = DAY_IN_SECONDS ) {
		if ( ! empty( $state_name ) ) {
			setcookie( self::COOKIE_BILLING_STATE, $state_name, time() + $expiry, '/', '', false, true );
			$_COOKIE[ self::COOKIE_BILLING_STATE ] = $state_name;
		}
	}

	/**
	 * Get the billing country in a cookie.
	 *
	 * @since 3.3.0
	 *
	 * @return string
	 */
	public static function get_billing_country() {
		return isset( $_COOKIE[ self::COOKIE_BILLING_COUNTRY ] ) ? Input::sanitize( $_COOKIE[ self::COOKIE_BILLING_COUNTRY ] ) : '';
	}

	/**
	 * Get the billing state in a cookie.
	 *
	 * @since 3.3.0
	 *
	 * @return string
	 */
	public static function get_billing_state() {
		return isset( $_COOKIE[ self::COOKIE_BILLING_STATE ] ) ? Input::sanitize( $_COOKIE[ self::COOKIE_BILLING_STATE ] ) : '';
	}

	/**
	 * Clear the billing country cookie.
	 *
	 * @since 3.3.0
	 */
	public static function clear_billing_country() {
		setcookie( self::COOKIE_BILLING_COUNTRY, '', time() - 3600, '/', '', false, true );
		unset( $_COOKIE[ self::COOKIE_BILLING_COUNTRY ] );
	}

	/**
	 * Clear the billing state cookie.
	 *
	 * @since 3.3.0
	 */
	public static function clear_billing_state() {
		setcookie( self::COOKIE_BILLING_STATE, '', time() - 3600, '/', '', false, true );
		unset( $_COOKIE[ self::COOKIE_BILLING_STATE ] );
	}

	/**
	 * Clear the billing state cookie.
	 *
	 * @since 3.3.0
	 */
	public static function clear_cart() {
		setcookie( self::COOKIE_GUEST_CART, '', time() - 3600, '/', '', false, true );
		unset( $_COOKIE[ self::COOKIE_GUEST_CART ] );
	}

	/**
	 * Clear all the cookies related with guest checkout.
	 *
	 * @since 3.3.0
	 */
	public static function clear_all() {
		self::clear_billing_country();
		self::clear_billing_state();
		self::clear_cart();
	}
}

<?php
/**
 * Handle guest checkout functionalities
 *
 * @package TutorPro\GuestCheckout
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

namespace TutorPro\Ecommerce\GuestCheckout;

use Tutor\Models\CartModel;
use Tutor\Models\OrderModel;
use TutorPro\Ecommerce\Settings;

/**
 * Handle guest checkout logics
 *
 * @since 3.3.0
 */
class GuestCheckout {

	/**
	 * Register hooks
	 *
	 * @since 3.3.0
	 */
	public function __construct() {
		if ( ! self::is_enable() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			new GuestCart();
			new HooksHandler();
		}

		// This hooks will be triggered after user login.
		// That's way we need register these hooks.
		add_filter( 'tutor_order_placement_success', array( $this, 'clear_cookies' ) );
		add_filter( 'tutor_order_placement_success_message', array( $this, 'update_message' ), 10, 2 );
		add_action( 'wp_login', array( $this, 'sync_guest_cart' ), 10, 2 );
	}

	/**
	 * Check if guest checkout is enabled
	 *
	 * @since 3.3.0
	 *
	 * @return bool
	 */
	public static function is_enable() {
		return tutor_utils()->get_option( Settings::ENABLE_GUEST_CHECKOUT_OPT, false );
	}

	/**
	 * Clear guest checkout cookies after successful order
	 *
	 * @since 3.3.0
	 *
	 * @return void
	 */
	public function clear_cookies() {
		CookieManager::clear_all();
	}

	/**
	 * Update success message for guest checkout orders
	 *
	 * @since 3.3.0
	 *
	 * @param string $message Default success message.
	 * @param int    $order_id Order ID.
	 *
	 * @return string Modified success message
	 */
	public function update_message( string $message, int $order_id ): string {
		$order_data = ( new OrderModel() )->get_order_by_id( $order_id );
		if ( $order_data && ! empty( $order_data->payment_payloads ) ) {
			$payloads = json_decode( $order_data->payment_payloads );
			if ( is_object( $payloads ) && property_exists( $payloads, 'is_guest_checkout' ) && $payloads->is_guest_checkout ) {
				$message = __( 'Thank you for your order. A password reset email has been sent to your billing email. Please reset your password to access your account.', 'tutor-pro' );
			}
		}

		return $message;
	}

	/**
	 * Sync guest cart with user cart after login
	 *
	 * @since 3.4.0
	 *
	 * @param string   $user_login user login.
	 * @param \WP_User $user user object.
	 *
	 * @return void
	 */
	public function sync_guest_cart( $user_login, \WP_User $user ) {
		$guest_cart = CookieManager::get_cart_items();
		if ( ! $guest_cart ) {
			return;
		}

		$cart_model = new CartModel();

		foreach ( $guest_cart as $item_id ) {
			remove_all_filters( 'tutor_is_course_exists_in_cart' );
			$is_course_in_user_cart = $cart_model->is_course_in_user_cart( $user->ID, $item_id );
			if ( ! $is_course_in_user_cart ) {
				$cart_model->add_course_to_cart( $user->ID, $item_id );
			}

			CookieManager::clear_cart();
		}
	}

}

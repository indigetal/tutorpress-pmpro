<?php
/**
 * Handle hooks to alter the default behavior to
 * incorporate guest checkout logics
 *
 * @package TutorPro\GuestCheckout
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

namespace TutorPro\Ecommerce\GuestCheckout;

use Tutor\Ecommerce\CouponController;
use Tutor\Ecommerce\Settings;
use TUTOR\Input;
use Tutor\Models\BillingModel;
use Tutor\Traits\JsonResponse;
use TUTOR_EMAIL\EmailNotification;
use TUTOR_PRO\EmailVerification;
use TUTOR_PRO\GuestEmail;
use WP_User;

/**
 * Handle guest checkout logics
 *
 * @since 3.3.0
 */
class HooksHandler {

	use JsonResponse;

	/**
	 * Register hooks
	 *
	 * @since 3.3.0
	 */
	public function __construct() {
		add_filter( 'tutor_native_add_to_cart_btn_class', array( $this, 'filter_the_btn_class' ) );
		add_filter( 'tutor_enroll_required_login_class', array( $this, 'filter_the_btn_class' ) );

		add_filter( 'tutor_is_course_exists_in_cart', array( $this, 'is_course_exists_in_cart' ), 10, 2 );
		add_filter( 'tutor_cart_items', array( $this, 'filter_cart_items' ) );
		add_filter( 'tutor_is_cart_empty', array( $this, 'filter_is_cart_empty' ) );
		add_filter( 'tutor_guest_user_id', array( $this, 'register_user' ), 10, 3 );
		add_action( 'wp_ajax_nopriv_tutor_get_checkout_html', array( $this, 'ajax_get_checkout_html' ) );
		add_filter( 'tutor_ecommerce_tax_country', array( $this, 'filter_tax_country' ) );
		add_filter( 'tutor_ecommerce_tax_state', array( $this, 'filter_tax_state' ) );
		add_filter( 'tutor_order_create_args', array( $this, 'update_args' ) );
		add_action( 'wp_ajax_nopriv_tutor_apply_coupon', array( new CouponController( false ), 'ajax_apply_coupon' ) );
	}

	/**
	 * Filter the add to cart button class
	 *
	 * @since 3.3.0
	 *
	 * @param string $class CSS class to be added.
	 *
	 * @return string
	 */
	public function filter_the_btn_class( $class ) {
		$class = Settings::is_buy_now_enabled() ? '' : ' tutor-add-to-guest-cart ';
		return $class;
	}

	/**
	 * Alter the default value of is_course_exists_in_cart
	 * using filter hook
	 *
	 * @param bool $is_exists Default value.
	 * @param int  $course_id Course ID.
	 *
	 * @since 3.3.0
	 *
	 * @return boolean
	 */
	public function is_course_exists_in_cart( bool $is_exists, int $course_id ): bool {
		return GuestCart::is_item_exists( $course_id ) ? true : $is_exists;
	}

	/**
	 * Filter cart items for guest checkout
	 *
	 * @since 3.3.0
	 *
	 * @param array $default_items Cart items array.
	 *
	 * @return array Modified cart items
	 */
	public function filter_cart_items( $default_items ) {
		$items = GuestCart::get_cart_items();
		if ( is_array( $items ) && count( $items ) ) {
			foreach ( $items as $course_id ) {
				$default_items['courses']['results'][] = get_post( $course_id );
			}

			$default_items['courses']['total_count'] = count( $items );
		}

		return $default_items;
	}

	/**
	 * Filter is cart empty for guest checkout
	 *
	 * @since 3.3.0
	 *
	 * @param bool $is_empty Default value.
	 *
	 * @return array bool
	 */
	public function filter_is_cart_empty( bool $is_empty ): bool {
		$items = GuestCart::get_cart_items();
		if ( is_array( $items ) && count( $items ) ) {
			$is_empty = (bool) count( $items );
		}

		return $is_empty;
	}

	/**
	 * Register guest user and send password reset email.
	 *
	 * @since 3.3.0
	 *
	 * @param int   $tmp_user_id     Temporary user ID for guest checkout.
	 * @param array $order_data      Order data array.
	 * @param array $billing_fields  Billing fields containing user information.
	 *
	 * @return int|WP_Error User ID if successful, WP_Error object on failure.
	 */
	public function register_user( $tmp_user_id, $order_data, $billing_fields ) {
		$user_email = $billing_fields['billing_email'];
		$user_name  = tutor_utils()->create_unique_username( $user_email );
		$register   = wp_create_user( $user_name, md5( rand() ), $user_email );
		if ( is_wp_error( $register ) ) {
			return $register;
		} else {
			wp_update_user(
				array(
					'ID'         => $register,
					'first_name' => $billing_fields['billing_first_name'],
					'last_name'  => $billing_fields['billing_last_name'],
				)
			);
			$userdata = get_userdata( $register );
			wp_set_current_user( $userdata->ID, $userdata->user_login );
			wp_set_auth_cookie( $userdata->ID );
			do_action( 'wp_login', $userdata->user_login, $userdata );

			// User no need to verify since they will reset pass from email.
			if ( tutor_utils()->get_option( 'enable_email_verification' ) ) {
				update_user_meta( $userdata->ID, EmailVerification::VERIFICATION_REQ_META_KEY, EmailVerification::VERIFIED_IDENTIFIER );
			}

			// Update temp user id.
			( new BillingModel() )->update(
				array( 'user_id' => $userdata->ID ),
				array( 'user_id' => $tmp_user_id ),
			);

			// Fire actions so that Tutor can send reset email.
			( new GuestEmail() )->send_password_reset_email( $userdata );

			return $userdata->ID;
		}
	}

	/**
	 * Get checkout HTML
	 *
	 * @since 3.3.0
	 *
	 * @return void
	 */
	public function ajax_get_checkout_html() {
		tutor_utils()->check_nonce();

		$billing_country = Input::post( 'billing_country' );
		$billing_state   = Input::post( 'billing_state' );

		if ( $billing_country ) {
			CookieManager::set_billing_country( $billing_country );
		}

		if ( $billing_state ) {
			CookieManager::set_billing_state( $billing_state );
		}

		ob_start();
		tutor_load_template( 'ecommerce/checkout-details' );
		$content = ob_get_clean();

		$this->json_response(
			__( 'Success', 'tutor-pro' ),
			$content
		);
	}

	/**
	 * Filter tax country for guest checkout
	 *
	 * @since 3.3.0
	 *
	 * @param string $country Default country name.
	 *
	 * @return string Modified country name
	 */
	public function filter_tax_country( $country ) {
		if ( is_user_logged_in() ) {
			return $country;
		}

		$has_in_cookie = CookieManager::get_billing_country();
		if ( $has_in_cookie ) {
			$country = $has_in_cookie;
		}

		return $country;
	}

	/**
	 * Filter tax state for guest checkout
	 *
	 * @since 3.3.0
	 *
	 * @param string $state Default state name.
	 *
	 * @return string Modified state name
	 */
	public function filter_tax_state( $state ) {
		if ( is_user_logged_in() ) {
			return $state;
		}

		$has_in_cookie = CookieManager::get_billing_state();
		if ( $has_in_cookie ) {
			$state = $has_in_cookie;
		}

		return $state;
	}

	/**
	 * Update order args for guest checkout
	 *
	 * @since 3.3.0
	 *
	 * @param array $args Order arguments.
	 *
	 * @return array Modified order arguments
	 */
	public function update_args( $args ) {
		$payloads = isset( $args['payment_payloads'] ) ? $args['payment_payloads'] : $args['payment_payloads'] = array();

		$payloads['is_guest_checkout'] = true;

		$args['payment_payloads'] = wp_json_encode( $payloads );

		return $args;
	}
}

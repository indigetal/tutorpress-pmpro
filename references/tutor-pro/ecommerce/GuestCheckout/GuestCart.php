<?php
/**
 * Handle guest cart functionalities to set, get, etc
 *
 * @package TutorPro\GuestCheckout
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

namespace TutorPro\Ecommerce\GuestCheckout;

use Tutor\Ecommerce\CartController;
use Tutor\Helpers\HttpHelper;
use TUTOR\Input;
use Tutor\Traits\JsonResponse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guest cart management
 */
class GuestCart {

	use JsonResponse;

	/**
	 * Register hooks
	 *
	 * @since 3.3.0
	 */
	public function __construct() {
		add_action( 'wp_ajax_nopriv_tutor_guest_add_course_to_cart', array( $this, 'add_course_to_cart' ) );
		add_action( 'wp_ajax_nopriv_tutor_delete_course_from_cart', array( $this, 'delete_course_from_cart' ) );
	}

	/**
	 * Add course to cart
	 *
	 * @since 3.3.0
	 *
	 * @return void JSON response
	 */
	public function add_course_to_cart() {
		if ( ! tutor_utils()->is_nonce_verified() ) {
			$this->json_response(
				tutor_utils()->error_message( 'nonce' ),
				null,
				HttpHelper::STATUS_BAD_REQUEST
			);
		}

		$course_id = Input::post( 'course_id', 0, Input::TYPE_INT );

		if ( ! $course_id ) {
			$this->json_response(
				__( 'Invalid course id.', 'tutor-pro' ),
				null,
				HttpHelper::STATUS_BAD_REQUEST
			);
		}

		// Check if the course already exists in the cart or not.
		$is_course_exists = self::is_item_exists( $course_id );
		if ( $is_course_exists ) {
			$this->json_response(
				__( 'The course is already in the cart.', 'tutor-pro' ),
				null,
				HttpHelper::STATUS_BAD_REQUEST
			);
		}

		// Add item in the cart.
		try {
			self::add_cart_item( $course_id );
			$this->json_response(
				__( 'The course was added to the cart successfully.', 'tutor-pro' ),
				array(
					'cart_page_url' => CartController::get_page_url(),
					'cart_count'    => count( self::get_cart_items() ),
				),
				HttpHelper::STATUS_CREATED
			);
		} catch ( \Throwable $th ) {
			$this->json_response(
				$th->getMessage(),
				'',
				HttpHelper::STATUS_BAD_REQUEST
			);
		}

	}

	/**
	 * Remove course from cart
	 *
	 * @since 3.3.0
	 *
	 * @return void JSON response
	 */
	public function delete_course_from_cart() {
		if ( ! tutor_utils()->is_nonce_verified() ) {
			$this->json_response(
				tutor_utils()->error_message( 'nonce' ),
				null,
				HttpHelper::STATUS_BAD_REQUEST
			);
		}

		$course_id = Input::post( 'course_id', 0, Input::TYPE_INT );

		if ( ! $course_id ) {
			$this->json_response(
				__( 'Invalid course id.', 'tutor-pro' ),
				null,
				HttpHelper::STATUS_BAD_REQUEST
			);
		}

		try {
			self::delete_cart_item( $course_id );

			ob_start();
			tutor_load_template( 'ecommerce.cart' );

			$cart_template = ob_get_clean();
			$data          = array(
				'cart_template' => $cart_template,
				'cart_count'    => count( self::get_cart_items() ),
			);

			$this->json_response(
				__( 'The course was removed successfully.', 'tutor-pro' ),
				$data,
				HttpHelper::STATUS_OK
			);
		} catch ( \Throwable $th ) {
			$this->json_response(
				$th->getMessage(),
				null,
				HttpHelper::STATUS_BAD_REQUEST
			);
		}
	}

	/**
	 * Set a course ID in the cookie
	 *
	 * @param int $item_id Item id to be set.
	 *
	 * @throws \Throwable If course id already in the cart.
	 *
	 * @return void
	 */
	public static function add_cart_item( $item_id ) {
		try {
			CookieManager::set_cart_item( $item_id );
		} catch ( \Throwable $th ) {
			throw $th;
		}
	}

	/**
	 * Get all course IDs from the cookie
	 *
	 * @return array
	 */
	public static function get_cart_items() {
		return CookieManager::get_cart_items();
	}

	/**
	 * Delete a course ID from the cookie
	 *
	 * @param int $item_id Item id to be deleted.
	 *
	 * @throws \Exception If course id not in the cart.
	 *
	 * @return void
	 */
	public static function delete_cart_item( $item_id ) {
		$current_cart = self::get_cart_items();

		if ( in_array( $item_id, $current_cart ) ) {
			$key = array_search( $item_id, $current_cart );
			unset( $current_cart[ $key ] );
			if ( empty( $current_cart ) ) {
				// Delete cookie.
				CookieManager::clear_cart();
			} else {
				CookieManager::update_cart( $current_cart );
			}
		} else {
			throw new \Exception( __( 'The course is not in the cart.', 'tutor-pro' ) );
		}
	}

	/**
	 * Check if a given item id exists in the cookie
	 *
	 * @param int $item_id The item id to check.
	 *
	 * @return bool
	 */
	public static function is_item_exists( $item_id ) {
		return in_array( $item_id, self::get_cart_items() );
	}
}

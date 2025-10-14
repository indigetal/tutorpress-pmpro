<?php
/**
 * Handle Gift Course
 *
 * @package TutorPro\GiftCourse
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

use TUTOR\Course;
use Tutor\Helpers\DateTimeHelper;
use Tutor\Helpers\HttpHelper;
use TUTOR\Icon;
use TUTOR\Input;
use Tutor\Models\CourseModel;
use Tutor\Traits\JsonResponse;
use TUTOR_ENROLLMENTS\Enrollments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Course Coming Soon
 */
class GiftCourse {

	use JsonResponse;

	/**
	 * Store gift course details in this meta key.
	 *
	 * @since 3.8.0
	 */
	const GIFT_FLAG_META   = 'tutor_gift';
	const GIFT_DATA_META   = 'tutor_gift_payload';
	const GIFT_STATUS_META = 'tutor_gift_status';

	/**
	 * Gift status
	 *
	 * @since 3.8.0
	 */
	const GIFT_STATUS_PROCESSING = 'processing';
	const GIFT_STATUS_SCHEDULED  = 'scheduled';
	const GIFT_STATUS_SENT       = 'sent';
	const GIFT_STATUS_RECEIVED   = 'received';

	const GIFT_SENT_AT_META     = 'tutor_gift_sent_at';
	const GIFT_RECEIVED_AT_META = 'tutor_gift_received_at';

	/**
	 * Register hooks.
	 *
	 * @since 3.3.0
	 */
	public function __construct() {
		add_action( 'tutor_course/single/entry/after', array( $this, 'add_gift_this_course_button' ) );
		add_action( 'tutor_before_dashboard_content', array( $this, 'gift_course_banner_template' ) );
		add_action( 'tutor_cart_item_badge', array( $this, 'add_cart_item_gift_badge' ) );
		add_action( 'wp_ajax_tutor_pro_gift_proceed_to_checkout', array( $this, 'ajax_gift_proceed_to_checkout' ) );
	}

	/**
	 * Add gift this course button to the single course entry box.
	 *
	 * @since 3.8.0
	 *
	 * @param int $course_id the course id.
	 *
	 * @return void
	 */
	public function add_gift_this_course_button( $course_id ) {
		if ( ! $this->can_gift_course( $course_id ) ) {
			return;
		}

		?>
		<button class="tutor-btn tutor-btn-primary tutor-btn-gift tutor-btn-lg tutor-btn-block tutor-my-12 <?php echo is_user_logged_in() ? '' : 'tutor-open-login-modal'; ?>" <?php echo is_user_logged_in() ? 'data-tutor-modal-target="tutor-gift-this-course-modal"' : ''; ?>>
			<?php tutor_utils()->render_svg_icon( Icon::GIFT, 24, 24 ); ?>
			<?php esc_html_e( 'Gift this Course', 'tutor-pro' ); ?>
		</button>
		<?php

		if ( is_user_logged_in() ) {
			tutor_load_template(
				'single.course.gift-this-course-modal',
				array( 'course_id' => $course_id ),
				true
			);
		}
	}

	/**
	 * Add gift this course banner to the dashboard.
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function gift_course_banner_template() {
		$user_id = get_current_user_id();
		$gifts   = $this->get_gift_courses( $user_id );

		if ( tutor_utils()->count( $gifts ) ) {
			foreach ( $gifts as $gift ) {
				$reference_id = $gift->reference_id;
				$payload      = json_decode( $gift->payload );
				$course_id    = $payload->course_id;
				if ( $this::can_open_gift( $gift ) ) {
					tutor_load_template(
						'dashboard.gift-course',
						compact( 'reference_id', 'course_id' ),
						true
					);
				}
			}
		}
	}

	/**
	 * Add gift badge to checkout course item
	 *
	 * @since 3.8.0
	 *
	 * @param object $course the course.
	 *
	 * @return void
	 */
	public function add_cart_item_gift_badge( $course ) {
		if ( ! $this->is_gift_course_in_cart( $course->ID ) ) {
			return;
		}

		?>
		<span class="tutor-checkout-gift-course-badge">
			<?php tutor_utils()->render_svg_icon( Icon::GIFT, 16, 16 ); ?>
			<?php esc_html_e( 'Gift', 'tutor-pro' ); ?>
		</span>
		<?php
	}

	/**
	 * Process gift course form to proceed to checkout
	 *
	 * @since 3.8.0
	 *
	 * @return void send wp_json response
	 */
	public function ajax_gift_proceed_to_checkout() {
		tutor_utils()->checking_nonce();

		$gift_form_data = Input::sanitize_array( $_POST, array( 'message' => 'esc_textarea' ) );
		$course_id      = (int) $gift_form_data['course_id'];

		if ( tutor_is_item_in_cart( $course_id ) ) {
			$this->response_bad_request(
				__( 'This course is already in your cart and cannot be gifted.', 'tutor-pro' )
			);
		}

		$user = get_user_by( 'email', $gift_form_data['recipient_email'] );
		if ( $user && tutor_utils()->is_enrolled( $course_id, $user->ID ) ) {
			$this->response_bad_request(
				__( 'The recipient is already enrolled in this course.', 'tutor-pro' )
			);
		}

		$gift_data = (object) array(
			'reference_id'     => 'gift_' . bin2hex( random_bytes( 8 ) ),
			'recipient_name'   => $gift_form_data['recipient_name'],
			'recipient_email'  => $gift_form_data['recipient_email'],
			'course_id'        => $course_id,
			'purchaser_id'     => get_current_user_id(),
			'course_title'     => get_the_title( $course_id ),
			'scheduled_at_gmt' => $gift_form_data['datetime'],
			'message'          => $gift_form_data['message'],
			'notify_me'        => (int) $gift_form_data['notify_me'],
		);

		$monetization = tutor_utils()->get_option( 'monetize_by' );
		try {
			$proceeder    = GiftProceedFactory::get_proceeder( $monetization );
			$checkout_url = $proceeder->proceed_to_checkout( $gift_data );

			$this->json_response(
				__( 'Success', 'tutor-pro' ),
				array( 'url' => $checkout_url )
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
	 * Check whether this course is gift-able
	 *
	 * @since 3.8.0
	 *
	 * @param integer $course_id Course to check against a course.
	 *
	 * @return boolean
	 */
	public static function can_gift_course( int $course_id ): bool {
		$course = get_post( $course_id );

		// Show gift course button only for published courses.
		if ( CourseModel::STATUS_PUBLISH !== $course->post_status ) {
			return false;
		}

		// Handle free courses.
		if ( ! tutor_utils()->is_course_purchasable( $course_id ) ) {
			return false;
		}

		// Handle enrollment periods settings.
		if ( tutor_utils()->is_addon_enabled( 'enrollments' ) ) {
			$enrollment = new Enrollments();
			list( $pause_enrollment, $course_enrollment_period, $enrollment_starts_at, $enrollment_ends_at ) = array_values( $enrollment->get_course_enrollment_settings( $course_id ) );

			if ( 'yes' === $pause_enrollment ) {
				return false;
			}

			if ( 'yes' === $course_enrollment_period ) {
				if ( $enrollment_starts_at && time() < strtotime( $enrollment_starts_at ) ) {
					return false;
				}

				if ( $enrollment_ends_at && time() > strtotime( $enrollment_ends_at ) ) {
					return false;
				}
			}
		}

		// Handle enrollment booked coursed.
		if ( tutor_utils()->is_course_fully_booked( $course_id ) ) {
			return false;
		}

		// Handle course selling options.
		$course_selling_option = Course::get_selling_option( $course_id );
		if ( in_array( $course_selling_option, array( Course::SELLING_OPTION_SUBSCRIPTION, Course::SELLING_OPTION_MEMBERSHIP ), true ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if the items is for gift
	 *
	 * @since 3.8.0
	 *
	 * @param object|integer $item_id Order Item id or item object.
	 *
	 * @return object|boolean Item object if exist or false
	 */
	public static function is_gift_item( $item_id ) {
		try {
			$proceeder = self::get_gift_proceeder();
			return $proceeder->is_gift_item( $item_id );
		} catch ( \Throwable $th ) {
			return false;
		}
	}

	/**
	 * Get gift data by course id
	 *
	 * @since 3.8.0
	 *
	 * @param mixed $reference_id Gift reference id.
	 * @param bool  $details Whether to return gift details or not.
	 *
	 * @return mixed false|object
	 */
	public static function get_gift_data_by_reference_id( $reference_id, $details = false ) {
		$where = array(
			'reference_id' => $reference_id,
		);

		$gift_data = ( new GiftScheduler() )->get( $where );
		if ( ! $gift_data ) {
			return false;
		}

		return $details ? $gift_data : json_decode( $gift_data->payload );
	}

	/**
	 * Get gift proceeder
	 *
	 * @since 3.8.0
	 *
	 * @throws \Throwable If failed to get proceeder.
	 *
	 * @return GiftProceeder
	 */
	public static function get_gift_proceeder() {
		$monetization = tutor_utils()->get_option( 'monetize_by' );
		try {
			return GiftProceedFactory::get_proceeder( $monetization );
		} catch ( \Throwable $th ) {
			throw $th;
		}
	}

	/**
	 * Can user open a gift
	 *
	 * @since 3.8.0
	 *
	 * @param object|int $gift_details Gift details or gift reference id.
	 *
	 * @return bool
	 */
	public static function can_open_gift( object $gift_details ): bool {
		if ( is_int( $gift_details ) ) {
			$gift_details = self::get_gift_data_by_reference_id( $gift_details, true );
		}

		if ( ! $gift_details ) {
			return false;
		}

		$gift_data = json_decode( $gift_details->payload );
		if ( ! $gift_data ) {
			$error = json_last_error();
			return false;
		}

		$user = wp_get_current_user();
		if ( $gift_data->recipient_email !== $user->user_email ) {
			return false;
		}

		$course_id = $gift_data->course_id;
		$course    = get_post( $course_id );
		if ( ! $course || CourseModel::STATUS_PUBLISH !== $course->post_status ) {
			return false;
		}

		if ( GiftScheduler::STATUS_PROCESSED !== $gift_details->status ) {
			return false;
		}

		return true;
	}

	/**
	 * Get all gift courses of a user that is ready to claim
	 *
	 * @since 3.8.0
	 *
	 * @param integer $user_id User id to get gift courses.
	 *
	 * @return mixed
	 */
	public static function get_gift_courses( int $user_id ) {
		return ( new GiftScheduler() )->get_all( array( 'scheduled_for' => $user_id ) );
	}

	/**
	 * Check if the course in cart is for gift
	 *
	 * This method will work only if the monetization is by tutor native
	 *
	 * @since 3.8.0
	 *
	 * @param integer $course_id The course id.
	 *
	 * @return bool true|false
	 */
	public static function is_gift_course_in_cart( int $course_id ) {
		if ( ! tutor_utils()->is_monetize_by_tutor() ) {
			return false;
		}

		return ( new NativeGiftProceeder() )->is_gift_course_in_cart( $course_id );
	}
}

<?php
/**
 * Handle Gift Enrollments
 *
 * @package TutorPro\GiftCourse
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

use AllowDynamicProperties;
use TUTOR\Input;
use Tutor\Models\CourseModel;
use Tutor\Traits\JsonResponse;
use TUTOR_PRO\GuestEmail;
use TutorPro\CourseBundle\CustomPosts\CourseBundle;
use TutorPro\CourseBundle\Models\BundleModel;

/**
 * Manage gift enrollments
 *
 * @since 3.8.0
 */
#[AllowDynamicProperties]
class GiftEnrollment {

	use JsonResponse;

	/**
	 * Resolve dependencies and register hooks
	 *
	 * @since 3.8.0
	 *
	 * @param GiftScheduler $scheduler GiftScheduler.
	 */
	public function __construct( GiftScheduler $scheduler ) {
		$this->scheduler = $scheduler;
		add_action( 'tutor_action_claim_gift', array( $this, 'claim_gift' ) );
		add_action( 'wp_ajax_tutor_pro_gift_enrollment', array( $this, 'ajax_gift_enrollment' ) );
	}

	/**
	 * Ajax handler to enroll the gift receiver to a course
	 *
	 * @since 3.8.0
	 *
	 * @return void send wp_json response
	 */
	public function ajax_gift_enrollment() {
		tutor_utils()->checking_nonce();
		$post         = Input::sanitize_array( $_POST );
		$course_id    = $post['course_id'];
		$reference_id = $post['reference_id'];
		$user_id      = get_current_user_id();

		if ( ! $course_id || ! $reference_id ) {
			$this->response_bad_request( __( 'Invalid gift request', 'tutor-pro' ) );
		}

		$can_claim = $this->can_claim_gift( $reference_id );
		if ( ! $can_claim->success ) {
			// If user can not claim then delete the scheduled record.
			$this->scheduler->delete( array( 'reference_id' => $reference_id ) );

			// Send response.
			$this->response_bad_request( $can_claim->message );
		}

		add_filter(
			'tutor_enroll_data',
			function ( $data ) {
				$data['post_status'] = 'completed';
				return $data;
			}
		);

		$enroll = tutor_utils()->do_enroll( $course_id, 0, $user_id );
		if ( $enroll ) {
			// Enroll user to the bundle courses.
			if ( tutor_utils()->is_addon_enabled( 'course-bundle' ) ) {
				if ( CourseBundle::POST_TYPE === get_post_type( $course_id ) ) {
					BundleModel::enroll_to_bundle_courses( $course_id, $user_id );
				}
			}

			// Notify the gift sender.
			$payload = $can_claim->payload;
			if ( $payload->notify_me ) {
				( new GiftMailer( $payload ) )->send_notification_email_to_purchaser();
			}

			// Update items status as received.
			try {
				$proceeder = GiftCourse::get_gift_proceeder();
				$proceeder->update_item_meta( $payload->order_item_id, GiftCourse::GIFT_STATUS_META, GiftCourse::GIFT_STATUS_RECEIVED );
				$proceeder->update_item_meta( $payload->order_item_id, GiftCourse::GIFT_RECEIVED_AT_META, current_time( 'mysql', 'U' ) );
			} catch ( \Throwable $th ) {
				tutor_log( $th );
			}

			// Delete scheduled record.
			( new GiftScheduler() )->delete( array( 'reference_id' => $reference_id ) );

			$this->response_success( __( 'You have successfully claimed this gift.', 'tutor-pro' ) );
		} else {
			$this->response_bad_request( __( 'Could not enroll', 'tutor-pro' ) );
		}
	}

	/**
	 * Handle gift course enrollment.
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function claim_gift() {
		$reference_id = Input::get( 'reference' );

		if ( empty( $reference_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'tutor-pro' ) );
		}

		$can_claim = $this->can_claim_gift( $reference_id );
		if ( ! $can_claim->success ) {
			wp_die( esc_html( $can_claim->message ) );
		}

		$payload         = $can_claim->payload;
		$recipient_email = $payload->recipient_email;
		$recipient_name  = $payload->recipient_name;
		$course_id       = (int) $payload->course_id;

		// Check if user exists.
		$user = get_user_by( 'email', $recipient_email );

		if ( ! $user ) {
			// Create new user.
			$username = sanitize_user( tutor_utils()->create_unique_username( $recipient_email ), true );
			$password = wp_generate_password( 12, true );
			$user_id  = wp_create_user( $username, $password, $recipient_email );

			if ( is_wp_error( $user_id ) ) {
				wp_die( esc_html__( 'Could not create user account.', 'tutor-pro' ) );
			}

			// Update display name.
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $recipient_name,
					'first_name'   => $recipient_name,
				)
			);

			$user = get_user_by( 'id', $user_id );

			// Send password reset email to the recipient.
			( new GuestEmail() )->send_password_reset_email( $user );
		}

		// Log the user in.
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );

		// Update scheduled, this is required when gift sent to a guest user.
		$gift_details = $this->scheduler->get( array( 'reference_id' => $reference_id ) );
		if ( $gift_details ) {
			$this->scheduler->update(
				array( 'scheduled_for' => $user->ID ),
				array( 'reference_id' => $reference_id )
			);
		}

		// Redirect to Tutor LMS Dashboard.
		wp_safe_redirect( tutor_utils()->tutor_dashboard_url() );
		exit;
	}

	/**
	 * Check whether current user can claim this gift
	 *
	 * @since 3.8.0
	 *
	 * @param mixed $reference_id Gift reference id.
	 *
	 * @return object {success, message, payload}
	 */
	public function can_claim_gift( $reference_id ): object {
		$res = (object) array(
			'success' => true,
			'message' => esc_html__( 'You have successfully claimed this gift', 'tutor-pro' ),
			'payload' => null,
		);

		$gift_data = $this->scheduler->get( $reference_id );

		if ( empty( $gift_data ) || ! is_object( $gift_data ) ) {
			$res->success = false;
			$res->message = esc_html__( 'Gift data is missing or invalid.', 'tutor-pro' );
			return $res;
		}

		$payload = json_decode( $gift_data->payload );
		if ( empty( $payload ) || ! is_object( $payload ) ) {
			$res->success = false;
			$res->message = esc_html__( 'Gift data is missing or invalid.', 'tutor-pro' );
			return $res;
		}

		// Status not processed mean it is scheduled. Not available yet.
		if ( $this->scheduler::STATUS_PROCESSED !== $gift_data->status ) {
			$res->success = false;
			$res->message = esc_html__( 'This gift is not yet available to claim.', 'tutor-pro' );
			return $res;
		}

		try {
			$proceeder = GiftCourse::get_gift_proceeder();
			$order     = $proceeder->get_order( $payload->order_id );
			if ( ! $order || 'completed' !== $order->order_status ) {
				$res->success = false;
				$res->message = esc_html__( 'The associated order is not completed yet.', 'tutor-pro' );
				return $res;
			}
		} catch ( \Throwable $th ) {
			$res->success = false;
			$res->message = esc_html__( 'Unable to process the order for this gift.', 'tutor-pro' );
			return $res;
		}

		$course = get_post( $payload->course_id );
		if ( ! $course || CourseModel::STATUS_PUBLISH !== $course->post_status ) {
			$res->success = false;
			$res->message = esc_html__( 'The gifted course is not available.', 'tutor-pro' );
			return $res;
		}

		$res->payload = $payload;
		return $res;
	}
}

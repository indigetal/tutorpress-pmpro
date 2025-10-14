<?php
/**
 * Manage Email contents for the gift course
 *
 * @package TutorPro\GiftCourse
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

use AllowDynamicProperties;
use Tutor\Helpers\DateTimeHelper;
use TUTOR_EMAIL\EmailData;
use TUTOR_EMAIL\EmailNotification;
use TUTOR_PRO\Mailer;

/**
 * Handle gift emails
 */
#[AllowDynamicProperties]
class GiftMailer {

	/**
	 * Resolve dependencies
	 *
	 * @since 3.8.0
	 *
	 * @param object $gift_data Gift payload.
	 * @param mixed  $proceeder Gift proceeder engine or null.
	 *
	 * @return void
	 */
	public function __construct( object $gift_data, $proceeder = null ) {
		$this->gift_data = $gift_data;
		$this->proceeder = $proceeder;
	}

	/**
	 * Handle gift email sending & scheduling
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function send_emails() {
		if ( $this->gift_data ) {
			$email_contents = $this->get_contents();

			$purchaser = get_userdata( $this->gift_data->purchaser_id );
			if ( $purchaser ) {
				$send = Mailer::send_mail( $purchaser->user_email, $email_contents->purchaser->subject, $email_contents->purchaser->body );
			}

			$can_process = time() >= strtotime( $this->gift_data->scheduled_at_gmt );
			if ( $can_process ) {
				// Notify the receiver.
				Mailer::send_mail( $this->gift_data->recipient_email, $email_contents->student->subject, $email_contents->student->body );

				// Update item status.
				if ( ! is_null( $this->proceeder ) ) {
					$this->proceeder->update_item_meta( $this->gift_data->order_item_id, GiftCourse::GIFT_STATUS_META, GiftCourse::GIFT_STATUS_SENT );
					$this->proceeder->update_item_meta( $this->gift_data->order_item_id, GiftCourse::GIFT_SENT_AT_META, current_time( 'mysql', 'U' ) );
				}
			}
		}
	}

	/**
	 * Send notification email to purchaser to his/her gift
	 * is being received by the recipient
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function send_notification_email_to_purchaser() {
		$mail_content = $this->get_notify_email_content();
		$purchaser    = get_userdata( $this->gift_data->purchaser_id );

		if ( $purchaser ) {
			Mailer::send_mail( $purchaser->user_email, $mail_content->subject, $mail_content->body );
		}
	}

	/**
	 * Send notification email to the receiver
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function send_notification_email_to_receiver() {
		$mail_content = $this->get_receiver_email_content();
		Mailer::send_mail( $this->gift_data->recipient_email, $mail_content->subject, $mail_content->body );
	}

	/**
	 * Get email contents
	 *
	 * @since 3.8.0
	 *
	 * @return object {purchaser, student}
	 */
	public function get_contents() {
		$email_contents = (object) array(
			'purchaser' => $this->get_purchaser_email_content(),
			'student'   => $this->get_receiver_email_content(),
		);

		return apply_filters( 'tutor_pro_gift_course_email_contents', $email_contents );
	}

	/**
	 * Get purchaser email content
	 *
	 * @since 3.8.0
	 *
	 * @return object {subject, body}
	 */
	public function get_purchaser_email_content() {
		$data = $this->get_purchaser_email_data();

		$purchaser    = get_userdata( $this->gift_data->purchaser_id );
		$course_id    = $this->gift_data->course_id;
		$rating       = tutor_utils()->get_course_rating( $course_id );
		$scheduled_at = $this->gift_data->scheduled_at_gmt;
		$author       = get_userdata( get_post_field( 'post_author', $course_id ) );

		$replaceable_data = array(
			'{email_heading}'    => $data['heading'],
			'{course_thumbnail}' => get_tutor_course_thumbnail_src( 'post-thumbnail', $course_id ),
			'{email_message}'    => wp_unslash( json_decode( $data['message'] ) ),
			'{recipient_name}'   => $this->gift_data->recipient_name,
			'{delivery_date}'    => DateTimeHelper::get_gmt_to_user_timezone_date( $scheduled_at ),
			'{course_name}'      => get_the_title( $course_id ),
			'{course_author}'    => $author->display_name,
			'{user_name}'        => $purchaser->display_name,
			'{course_rating}'    => $rating->rating_avg,
			'{gift_message}'     => $this->gift_data->message,
			'{footer_text}'      => str_replace( '{recipient_name}', $this->gift_data->recipient_name, $data['footer_text'] ),
			'{site_name}'        => get_bloginfo( 'name' ),
			'{site_url}'         => get_home_url(),
		);

		$template_path = tutor_pro()->templates . 'email/to_user_gifted_a_course.php';
		ob_start();
		include $template_path;
		$string = ob_get_clean();

		$mail_body = str_replace( array_keys( $replaceable_data ), array_values( $replaceable_data ), $string );

		// Replace placeholder.
		$subject = str_replace( '{recipient_name}', $this->gift_data->recipient_name, $data['subject'] );

		return (object) array(
			'subject' => $subject,
			'body'    => $mail_body,
		);
	}

	/**
	 * Get purchaser email content
	 *
	 * @since 3.8.0
	 *
	 * @return object {subject, body}
	 */
	public function get_receiver_email_content() {
		$data = $this->get_receiver_email_data();

		$purchaser = get_userdata( $this->gift_data->purchaser_id );

		$gift_id      = $this->gift_data->reference_id;
		$redirect_url = add_query_arg(
			array(
				'tutor_action' => 'claim_gift',
				'reference'    => $gift_id,
			),
			get_home_url( null, 'gift-course' )
		);

		$replaceable_data = array(
			'{email_heading}'    => $data['heading'],
			'{email_message}'    => wp_unslash( json_decode( $data['message'] ) ),
			'{course_thumbnail}' => get_tutor_course_thumbnail_src( 'post-thumbnail', $this->gift_data->course_id ),
			'{course_url}'       => get_the_permalink( $this->gift_data->course_id ),
			'{course_name}'      => get_the_title( $this->gift_data->course_id ),
			'{buyer_name}'       => tutor_utils()->display_name( $purchaser->ID ),
			'{user_name}'        => $this->gift_data->recipient_name,
			'{footer_text}'      => $data['footer_text'],
			'{redirect_url}'     => $redirect_url,
			'{site_name}'        => get_bloginfo( 'name' ),
			'{site_url}'         => get_home_url(),
		);

		$template_path = tutor_pro()->templates . 'email/to_user_received_a_gifted_course.php';
		ob_start();
		include $template_path;
		$string = ob_get_clean();

		$mail_body = str_replace( array_keys( $replaceable_data ), array_values( $replaceable_data ), $string );

		return (object) array(
			'subject' => $data['subject'],
			'body'    => $mail_body,
		);
	}

	/**
	 * Get purchaser notify me email content
	 *
	 * @since 3.8.0
	 *
	 * @return object {subject, body}
	 */
	public function get_notify_email_content() {
		$data      = $this->get_notify_me_email_data();
		$purchaser = get_userdata( $this->gift_data->purchaser_id );
		$course_id = $this->gift_data->course_id;
		$author    = get_userdata( get_post_field( 'post_author', $course_id ) );
		$rating    = tutor_utils()->get_course_rating( $course_id );

		$course_rating = $rating->rating_avg;

		$replaceable_data = array(
			'{email_heading}'    => $data['heading'],
			'{email_message}'    => wp_unslash( json_decode( $data['message'] ) ),
			'{course_author}'    => tutor_utils()->display_name( $author->ID ),
			'{course_rating}'    => $course_rating,
			'{course_thumbnail}' => get_tutor_course_thumbnail_src( 'post-thumbnail', $course_id ),
			'{course_name}'      => get_the_title( $course_id ),
			'{receiver_name}'    => $this->gift_data->recipient_name,
			'{user_name}'        => tutor_utils()->display_name( $purchaser->ID ),
			'{footer_text}'      => '',
			'{site_name}'        => get_bloginfo( 'name' ),
			'{site_url}'         => get_home_url(),
		);

		$template_path = tutor_pro()->templates . 'email/to_sender_gift_received_notification.php';
		ob_start();
		include $template_path;
		$string = ob_get_clean();

		$mail_body = str_replace( array_keys( $replaceable_data ), array_values( $replaceable_data ), $string );

		return (object) array(
			'subject' => $data['subject'],
			'body'    => $mail_body,
		);
	}

	/**
	 * Get purchaser email data
	 *
	 * @since 3.8.0
	 *
	 * @return array
	 */
	private function get_purchaser_email_data() {
		if ( tutor_utils()->is_addon_enabled( 'tutor-email' ) ) {
			$email_notification = new EmailNotification( false );

			$default_data = ( new EmailData() )->get_recipients()['email_to_students']['gifted_a_course'];
			$updated_data = $email_notification->get_option_data( $email_notification::TO_STUDENTS, 'gifted_a_course' );

			return wp_parse_args( $updated_data, $default_data );
		}

		$data = array(
			'subject'     => __( 'You\'ve Successfully Gifted a Course to {recipient_name}', 'tutor-pro' ),
			'heading'     => __( 'Your gifted course is on its way!', 'tutor-pro' ),
			'message'     => wp_json_encode( 'You\'ve successfully gifted <strong>{course_name}</strong> course to <strong>{recipient_name}</strong>. The delivery is scheduled for {delivery_date}.' ),
			'footer_text' => __( 'We\'ll notify you once <strong>{recipient_name}</strong> accepts the gift.', 'tutor-pro' ),
		);

		return $data;
	}

	/**
	 * Get receiver email data
	 *
	 * @since 3.8.0
	 *
	 * @return array
	 */
	private function get_receiver_email_data() {
		if ( tutor_utils()->is_addon_enabled( 'tutor-email' ) ) {
			$email_notification = new EmailNotification( false );

			$default_data = ( new EmailData() )->get_recipients()['email_to_students']['received_a_gifted_course'];
			$updated_data = $email_notification->get_option_data( $email_notification::TO_STUDENTS, 'received_a_gifted_course' );

			return wp_parse_args( $updated_data, $default_data );
		}

		$data = array(
			'subject'     => __( 'Congrats! A Special Gift Is Ready for You 🎁', 'tutor-pro' ),
			'heading'     => __( 'Great news!', 'tutor-pro' ),
			'message'     => wp_json_encode( '<strong>{buyer_name}</strong> has gifted you the <strong>{course_name}</strong> course on <strong>{site_name}</strong>.' ),
			'footer_text' => __( "Note: You'll be automatically registered in {site_name} once you accept the gift.", 'tutor-pro' ),
		);

		return $data;
	}

	/**
	 * Get receiver email data
	 *
	 * @since 3.8.0
	 *
	 * @return array
	 */
	private function get_notify_me_email_data() {
		$data = array(
			'subject'     => __( 'Your gift course has been received', 'tutor-pro' ),
			'heading'     => __( '🎁 Your gift has been received!', 'tutor-pro' ),
			'message'     => wp_json_encode( 'Recipient: <strong>{receiver_name}<strong>, Course: <strong>{course_name}</strong>' ),
			'footer_text' => '',
		);

		return $data;
	}
}

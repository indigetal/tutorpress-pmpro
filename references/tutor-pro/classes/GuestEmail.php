<?php
/**
 * Send guest password reset email after checkout
 *
 * @package TutorPro\GuestPasswordReset
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

namespace TUTOR_PRO;

use TUTOR_EMAIL\EmailNotification;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage guest password reset request
 */
class GuestEmail {

	/**
	 * Send password reset email after guest checkout
	 *
	 * @since 3.3.0
	 *
	 * @param WP_User $userdata User data object.
	 *
	 * @return void
	 */
	public function send_password_reset_email( WP_User $userdata ) {
		$to = $userdata->user_email;

		do_action( 'tutor_pro_before_prepare_email_template_data', $to );

		$subject = sprintf( __( 'Password Reset for %s', 'tutor-pro' ), get_bloginfo( 'name' ) ); //phpcs:ignore

		$email_body = $this->get_password_reset_email_body( $userdata );

		do_action( 'tutor_pro_after_prepare_template_email_data' );

		$content_type = apply_filters( 'wp_mail_content_type', 'text/html' );
		$header       = 'Content-Type: ' . $content_type;

		$is_email_addon_enabled = tutor_utils()->is_addon_enabled( 'tutor-emails/tutor-email.php' );
		if ( $is_email_addon_enabled ) {
			( new EmailNotification() )->send( $to, $subject, $email_body, $header );
		} else {
			$send = wp_mail( $to, $subject, $email_body, $header );
			if ( ! $send ) {
				tutor_log( 'Failed to send an email after guest checkout' );
			}
		}
	}

	/**
	 * Get password reset email body content
	 *
	 * @since 3.3.0
	 *
	 * @param WP_User $userdata   WP_User object.
	 *
	 * @return string Email body content
	 */
	public function get_password_reset_email_body( WP_User $userdata ) {
		ob_start();

		$reset_key  = get_password_reset_key( $userdata );
		$reset_link = add_query_arg(
			array(
				'reset_key' => $reset_key,
				'user_id'   => $userdata->ID,
			),
			tutor_utils()->tutor_dashboard_url( 'retrieve-password' )
		);

		$username     = $userdata->user_login;
		$display_name = tutor_utils()->display_name( $userdata->ID );

		tutor_load_template_from_custom_path(
			tutor_pro()->path . 'templates/email/to_guest_password_reset.php',
			array(
				'username'     => $username,
				'display_name' => $display_name,
				'reset_link'   => $reset_link,
			),
			false
		);

		return apply_filters( 'tutor_reset_password_email_body', ob_get_clean(), $username, $reset_link );
	}
}

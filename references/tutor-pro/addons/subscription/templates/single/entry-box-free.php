<?php
/**
 * Template for free enroll in course details entry box.
 *
 * @package TutorPro\Subscription
 * @subpackage Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.2.0
 */

$login_url = tutor_utils()->get_option( 'enable_tutor_native_login', null, true, true ) ? '' : wp_login_url( tutor()->current_url );

ob_start();
?>

<div class="tutor-course-single-btn-group <?php echo is_user_logged_in() ? '' : 'tutor-course-entry-box-login'; ?>" data-login_url="<?php echo esc_url( $login_url ); ?>">
	<form class="tutor-enrol-course-form" method="post">
		<?php wp_nonce_field( tutor()->nonce_action, tutor()->nonce ); ?>
		<input type="hidden" name="tutor_course_id" value="<?php echo esc_attr( get_the_ID() ); ?>">
		<input type="hidden" name="tutor_subscription_enrollment" value="true">
		<input type="hidden" name="tutor_course_action" value="_tutor_course_enroll_now">
		<button type="submit" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block tutor-enroll-course-button <?php echo is_user_logged_in() ? 'tutor-static-loader' : ''; ?>">
			<?php esc_html_e( 'Enroll Now', 'tutor-pro' ); ?>
		</button>
	</form>
</div>
<?php echo apply_filters( 'tutor_pro_subscription_enrollment', ob_get_clean(), get_the_ID() ); //phpcs:ignore ?>

<div class="tutor-fs-7 tutor-color-muted tutor-mt-20 tutor-text-center">
	<?php esc_html_e( 'Included in your subscription.', 'tutor-pro' ); ?>
</div>

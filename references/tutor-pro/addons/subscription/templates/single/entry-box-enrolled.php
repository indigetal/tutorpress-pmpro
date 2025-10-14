<?php
/**
 * Display template entry box information
 * when the subscription is inactive but the user is still enrolled.
 *
 * @package TutorPro\Subscription
 * @subpackage Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.2.0
 */

$wrapper_class       = $data['wrapper_class'] ?? '';
$plan_name           = $data['plan_name'] ?? '';
$subscription_status = $data['subscription_status'] ?? '';
$subscription_url    = $data['subscription_url'] ?? '';

?>
<div class="<?php echo esc_attr( $wrapper_class ); ?>">
	<p class="tutor-fs-6 tutor-text-center tutor-color-subdued tutor-mt-0 tutor-mb-24">
		<?php esc_html_e( 'You have a subscription', 'tutor-pro' ); ?>
		<br>
		<?php
			/* translators: %s: plan name */
			echo esc_html( sprintf( __( 'Plan: %s', 'tutor-pro' ), $plan_name ) );
		?>
		<br>
		<?php
			/* translators: %s: status */
			echo wp_kses_post( sprintf( __( 'Status: %s', 'tutor-pro' ), tutor_utils()->translate_dynamic_text( $subscription_status, true ) ) );
		?>
	</p>
	<a	href="<?php echo esc_url( $subscription_url ); ?>" 
		class="tutor-btn tutor-btn-primary tutor-btn-block">
		<?php esc_html_e( 'View Subscription', 'tutor-pro' ); ?>
	</a>
</div>

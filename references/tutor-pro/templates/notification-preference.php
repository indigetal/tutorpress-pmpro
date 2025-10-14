<?php
/**
 * Notification Preference Template
 *
 * @package TutorPro\Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.1.0
 */

use TUTOR_PRO\NotificationPreference;

$preferences = NotificationPreference::prepare_notification_preferences_data( get_current_user_id() );
$disable_all = 'on' === $preferences['disable_all']['value'];
?>

<div class="tutor-fs-4 tutor-fw-medium tutor-mb-24"><?php esc_html_e( 'Settings', 'tutor-pro' ); ?></div>

<div class="tutor-dashboard-content-inner tutor-dashboard-setting-notification">
	<?php tutor_load_template( 'dashboard.settings.nav-bar', array( 'active_setting_nav' => 'notification' ) ); ?>
	<div class="tutor-fs-5 tutor-color-black tutor-my-24"><?php esc_html_e( 'Email Notification Preference', 'tutor-pro' ); ?></div>

	<form method="post" id="tutor_notification_pref_form" style="max-width: 780px;">
		<?php tutor_nonce_field(); ?>
		<input type="hidden" value="tutor_save_notification_preference" name="action" />

		<div class="tutor-d-flex tutor-justify-between tutor-border tutor-radius-6 tutor-p-12">
			<div class="tutor-d-flex tutor-gap-12px tutor-align-center">

				<div class="tutor-bell-icon-wrapper">
					<img class="tutor-icon-image-bell <?php echo esc_attr( $disable_all ? 'tutor-d-none' : '' ); ?>" src="<?php echo esc_url( tutor_pro()->url . 'assets/images/icons/bell.svg' ); ?>" alt="bell">
					<img class="tutor-icon-image-bell-slash <?php echo esc_attr( $disable_all ? '' : 'tutor-d-none' ); ?>" src="<?php echo esc_url( tutor_pro()->url . 'assets/images/icons/bell-slash.svg' ); ?>" alt="bell-slash">
				</div>

				<label class="tutor-form-check tutor-d-flex tutor-align-center" for="tutor-disable-all-notification">
					<input type="checkbox" id="tutor-disable-all-notification" 
							name="tutor_notification_preference[disable_all]"
							<?php checked( $preferences['disable_all']['value'], 'on' ); ?> class="tutor-form-check-input">
					<span class="tutor-color-secondary tutor-fs-7"><?php esc_html_e( 'Turn off email notifications', 'tutor-pro' ); ?></span>
				</label>
			</div>
		</div>

		<div id="tutor-customize-notification-preference" class="tutor-mt-32 <?php echo esc_attr( $disable_all ? 'tutor-d-none' : '' ); ?>">
			<div class="tutor-fs-5 tutor-color-black tutor-mb-12"><?php esc_html_e( 'Customize Preference', 'tutor-pro' ); ?></div>

			<?php
			foreach ( $preferences['email'] as $group_key => $trigger_group ) :
				$group_label = __( 'Email to Students', 'tutor-pro' );
				if ( 'email_to_students' === $group_key ) {
					$group_label = __( 'Email to Students', 'tutor-pro' );
				} elseif ( 'email_to_teachers' === $group_key ) {
					$group_label = __( 'Email to Teachers', 'tutor-pro' );
				} elseif ( 'email_to_admin' === $group_key ) {
					$group_label = __( 'Email to Admin', 'tutor-pro' );
				}
				?>
				<div class="tutor-option-group-title tutor-mb-16">
					<?php if ( tutor_utils()->count( $preferences['email'] ) ) : ?>
					<div class="tutor-fs-6 tutor-color-subdued"><?php echo esc_html( $group_label ); ?></div>
					<?php endif; ?>
				</div>
				<div class="tutor-border tutor-radius-6">
				<?php
				foreach ( $trigger_group as $key => $item ) :
					?>
				<div class="tutor-setting-notification-item tutor-d-flex tutor-justify-between tutor-py-16 tutor-px-24">
					<div class="tutor-fs-6"><?php echo esc_html( $item['label'] ); ?></div>
					<label class="tutor-form-toggle">
						<input type="hidden" name="tutor_notification_preference[email][<?php echo esc_attr( $group_key ); ?>][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $item['value'] ); ?>">
						<input type="checkbox" <?php checked( $item['value'], 'on' ); ?> class="tutor-form-toggle-input">
						<span class="tutor-form-toggle-control"></span>
					</label>
				</div>
					<?php

				endforeach;
				?>
				</div>
				<?php
			endforeach;
			?>
		</div>
	</form>
</div>

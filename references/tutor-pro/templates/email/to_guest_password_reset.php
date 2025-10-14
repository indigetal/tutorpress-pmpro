<?php
/**
 * Guest checkout password reset email template
 *
 * @package TutorPro
 * @subpackage Templates\Email
 *
 * @since 3.3.0
 */

$username     = $data['username'] ?? '';
$display_name = $data['display_name'] ?? '';
$reset_link   = $data['reset_link'] ?? '';
$site_name    = get_bloginfo( 'name' );
?>
<!DOCTYPE html>
<html>

<head>
	<meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
	<?php require TUTOR()->path . 'templates/email/email_styles.php'; ?>
</head>

<body>
	<div class="tutor-email-body">
		<div class="tutor-email-wrapper">
			<?php require TUTOR()->path . 'templates/email/email_header.php'; ?>
			<div class="tutor-email-content">
				<div>
					<h6 data-source="email-heading" class="tutor-email-heading">
						<?php
						// translators: %s: username.
						echo sprintf( esc_html__( 'Hi %s,', 'tutor-pro' ), esc_html( $display_name ) );
						?>
					</h6>
				</div>
				<br>
				<div class="email-user-content" data-source="email-additional-message">
					<p>
						<?php
						$heading_text = apply_filters(
							'tutor_guest_password_reset_email_heading_text',
							// translators: %s for the site name.
							sprintf( __( "As part of your enrollment, we've created an account for you on %s. Please set your password to get started.", 'tutor-pro' ), $site_name )
						);
						echo esc_html( $heading_text );
						?>
					</p>
					<p><strong><?php esc_html_e( 'Username:', 'tutor-pro' ); ?></strong> <?php echo esc_html( $username ); ?></p>
					<p><?php esc_html_e( 'Click the button below to set your password:', 'tutor-pro' ); ?></p>
					<p>
						<a href="<?php echo esc_url( $reset_link ); ?>
						" class="tutor-email-button" style="padding: 10px;">
							<?php esc_html_e( 'Set Up Your Password', 'tutor-pro' ); ?>
						</a>
					</p>

					<br>
					<p><strong><?php esc_html_e( 'Best regards,', 'tutor-pro' ); ?></strong></p>
					<p><?php echo esc_html( $site_name ); ?></p>
				</div>
			</div>
		</div>
	</div>
</body>
</html>

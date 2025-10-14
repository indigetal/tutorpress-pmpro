<?php
/**
 * E-mail template for instructor when course review submitted.
 *
 * @package TutorPro
 * @subpackage Templates\Email
 *
 * @since 3.7.0
 */

?>
<!DOCTYPE html>
<html>

<head>
	<meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
	<?php require TUTOR()->path . 'templates/email/email_styles.php'; ?>
</head>

<body>
	<div class="tutor-email-body">
		<div class="tutor-email-wrapper" style="background-color: #fff;">
			<?php require TUTOR_PRO()->path . 'templates/email/email_header.php'; ?>
			<div class="tutor-email-content">
				<?php require TUTOR_PRO()->path . 'templates/email/email_heading_content.php'; ?>

				<hr class="email-hr-separator">

				<div data-source="email-before-button" class="tutor-email-before-button tutor-h-center email-mb-30">{before_button}</div>

				<div class="tutor-email-buttons tutor-h-center">
					<a target="_blank" class="tutor-email-button" href="{reviews_url}" data-source="email-btn-url">
						<span><?php esc_html_e( 'Go to Reviews', 'tutor-pro' ); ?></span>
					</a>
				</div>
			</div>
			<?php require TUTOR_PRO()->path . 'templates/email/email_footer.php'; ?>
		</div>
	</div>
</body>
</html>

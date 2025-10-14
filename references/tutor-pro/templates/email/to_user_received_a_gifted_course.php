<?php
/**
 * E-mail template for student when received a gifted course.
 *
 * @package TutorPro
 * @subpackage Templates\Email
 *
 * @since 3.8.0
 */

?>
<!DOCTYPE html>
<html>

<head>
	<meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
	<?php require TUTOR()->path . 'templates/email/email_styles.php'; ?>

	<style>
	.tutor-gift-course {
			padding: 38px;
			border-radius: 6px;
			border: 1px solid #DCDFE5;
			background-color: #F8F8F8;
			display: flex;
			align-items: center;
			justify-content: center;
			margin-bottom: 24px;

			img {
				width: 172px;
				height: 172px; 
			}
		}
	</style>
</head>

<body>
	<div class="tutor-email-body">
		<div class="tutor-email-wrapper" style="background-color: #fff;">

			<?php require TUTOR_PRO()->path . 'templates/email/email_header.php'; ?>
			<div class="tutor-email-content">
				<?php require TUTOR_PRO()->path . 'templates/email/email_heading_content.php'; ?>

				<div class="tutor-gift-course">
					<img src="<?php echo esc_url( TUTOR_PRO()->url . 'assets/images/gift_box.webp' ); ?>" alt="gifted course" />
				</div>

				<div class="tutor-email-buttons">
					<a href="{redirect_url}" class="tutor-email-button" style="width: 100%"><?php esc_html_e( 'Reveal Gift', 'tutor-pro' ); ?></a>
				</div>
			</div>
			<?php require TUTOR_PRO()->path . 'templates/email/email_footer.php'; ?>
		</div>
	</div>
</body>
</html>

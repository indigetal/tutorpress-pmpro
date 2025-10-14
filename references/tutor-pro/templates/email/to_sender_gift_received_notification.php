<?php
/**
 * E-mail template for student when gifted a course.
 *
 * @package TutorPro
 * @subpackage Templates\Email
 *
 * @since 3.8.0
 */

use TUTOR\Input;

$is_email_editor = 'tutor-email-preview' === Input::get( 'page' );

?>
<!DOCTYPE html>
<html>

<head>
	<meta http-equiv="Content-Type" content="text/html charset=UTF-8" />
	<?php require TUTOR()->path . 'templates/email/email_styles.php'; ?>

	<style>
		@import url(<?php echo esc_url( tutor()->url . 'assets/css/tutor-icon.min.css' ); ?>);
		.tutor-gifted-course {
			margin-bottom: -36px;
			border: 1px solid #E0E2EA;
			background: #FCFCFC;
			border-radius: 8px;
			padding: 16px 16px 4px 16px;
		}
		.tutor-course-card {
			border: 1px solid #E0E2EA80;
			background: #FFFFFF;
			border-radius: 4px;
			margin-bottom: 16px;
			display: flex;
			overflow: hidden;

			img {
				width: 144px;
				object-fit: cover;
				flex-shrink: 0;
			}

			.tutor-course-info {
				display: flex;
				flex-direction: column;
				padding: 16px;

				.tutor-course-author {
						color: #595D7EB2;
						font-weight: 600;
						font-size: 14px;
				}
			
				.tutor-course-rating-wrapper {
					display: flex;
					gap: 4px;
					align-items: center;

					.tutor-course-rating {
						color: #FAA625;
						div {
							display: flex;
							gap: 4px;
						}
					}
				}
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

				<div class="tutor-gifted-course">

					<div class="tutor-course-card">
						<?php if ( $is_email_editor ) : ?>
							<img src="<?php echo esc_url( tutor()->url . 'assets/images/placeholder.svg' ); ?>" alt="{course_name}" width="144" height="144">
						<?php else : ?>
							<img src="{course_thumbnail}" alt="{course_name}">
						<?php endif; ?>						
						<div class="tutor-course-info">
							<p><strong>{course_name}</strong></p>
							<p class="tutor-course-author"><?php esc_html_e( 'By', 'tutor-pro' ); ?> <strong>{course_author}</strong></p>
							<?php if ( isset( $course_rating ) ) : ?>
							<div class="tutor-course-rating-wrapper">
								<strong>{course_rating}</strong>
								<div class="tutor-course-rating">
									<?php tutor_utils()->star_rating_generator( $course_rating ); ?>
								</div>
							</div>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
			<?php require TUTOR_PRO()->path . 'templates/email/email_footer.php'; ?>
		</div>
	</div>
</body>
</html>

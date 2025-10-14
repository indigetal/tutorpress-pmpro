<?php
/**
 * Course coming soon template.
 *
 * @package Tutor\Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

?>

<div class="tutor-coming-soon-wrapper">
	<i class="tutor-icon-book-open-line"></i>
	<span class="tutor-fw-medium">
	<?php esc_html_e( 'Course available from ', 'tutor-pro' ); ?>
		<span class="tutor-utc-date-time tutor-color-success">
		<?php echo esc_html( $course->post_date_gmt ); ?>			
		</span>
	</span>
</div>

<?php
/**
 * Template for my courses bundle filter.
 *
 * @package TutorPro\CourseBundle
 * @subpackage Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.5.0
 */

use TUTOR\Input;

$filters  = array(
	''              => __( 'Courses & Bundles', 'tutor-pro' ),
	'courses'       => __( 'Courses', 'tutor-pro' ),
	'course-bundle' => __( 'Bundles', 'tutor-pro' ),
);
$selected = Input::get( 'type' );
?>

<li class="tutor-dashboard-my-courses-filter">
	<span><?php esc_html_e( 'Type:', 'tutor-pro' ); ?></span>
	<select name="type" class="tutor-form-control tutor-form-select tutor-filter-select">
		<?php foreach ( $filters as $key => $value ) : ?>
		<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $selected ); ?>>
			<?php echo esc_html( $value ); ?>
		</option>
		<?php endforeach; ?>
	</select>
</li>

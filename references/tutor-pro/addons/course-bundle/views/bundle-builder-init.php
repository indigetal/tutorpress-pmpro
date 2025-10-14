<?php
/**
 * Course Bundle Builder Root Component
 *
 * This file works as a placeholder for the course bundle builder react app
 * for the both admin & front side.
 *
 * @package TutorLMS/CourseBundle
 * @since 3.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_admin() ) {
	?>
	<div id="tutor-course-bundle-builder-root"></div>
	<?php
} else {
	?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">

	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<div id="tutor-course-bundle-builder-root"></div>
	<?php wp_footer(); ?>
</body>
</html>

<?php } ?>

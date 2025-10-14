<?php
/**
 * Manage dashboard for course bundle.
 *
 * @package TutorPro\CourseBundle
 * @subpackage Frontend
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 2.2.0
 */

namespace TutorPro\CourseBundle\Frontend;

use TutorPro\CourseBundle\CustomPosts\CourseBundle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard Class
 *
 * @since 2.2.0
 */
class Dashboard {

	/**
	 * Register hooks
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'tutor_wishlist_post_types', array( $this, 'add_wishlist_post_types' ) );
		add_action( 'tutor_course_create_button', array( $this, 'create_bundle_button' ) );
	}

	/**
	 * Add create new bundle button on dashboard page.
	 *
	 * @since 3.5.0
	 *
	 * @return void
	 */
	public function create_bundle_button() {
		?>
		<a href="#" data-source="frontend" class="tutor-add-new-course-bundle tutor-mr-8 tutor-btn tutor-btn-outline-primary">
			<i class="tutor-icon-bundle tutor-mr-8"></i>
			<?php esc_html_e( 'New Bundle', 'tutor-pro' ); ?>
		</a>
		<?php
	}

	/**
	 * Add course bundle post type to wishlist post types.
	 *
	 * @since 2.2.0
	 *
	 * @param array $post_types post types.
	 *
	 * @return array
	 */
	public function add_wishlist_post_types( $post_types ) {
		$post_types[] = CourseBundle::POST_TYPE;
		return $post_types;
	}
}

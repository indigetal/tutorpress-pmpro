<?php
/**
 * Handle Tutor enrollments
 *
 * @package TutorPro\CourseBundle
 * @subpackage Integrations
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.2.0
 */

namespace TutorPro\CourseBundle\Frontend;

use TutorPro\CourseBundle\CustomPosts\CourseBundle;
use TutorPro\CourseBundle\Models\BundleModel;
use TutorPro\CourseBundle\Utils;

/**
 * Integration with tutor enrollments
 */
class Enrollments {

	/**
	 * Register hooks
	 *
	 * @since 3.2.0
	 */
	public function __construct() {
		add_action( 'tutor_after_enroll', array( $this, 'enroll_the_user' ) );
		add_action( 'is_course_purchasable', array( $this, 'filter_purchaseable' ), 100, 2 );
	}

	/**
	 * Enroll the user to the bundle courses
	 *
	 * For the free purchase order hooks are not getting
	 * triggered that's why we need to enroll the user separately
	 *
	 * @since 3.2.0
	 *
	 * @param int $bundle_id Bundle course id.
	 *
	 * @return void
	 */
	public function enroll_the_user( $bundle_id ) {
		if ( CourseBundle::POST_TYPE === get_post_type( $bundle_id ) && Utils::is_free( $bundle_id ) ) {
			$user_id = get_current_user_id();
			BundleModel::enroll_to_bundle_courses( $bundle_id, $user_id );
		}
	}

	/**
	 * If there is no regular or sale price set bundle as free
	 *
	 * @since 3.2.0
	 *
	 * @param bool $is_purchaseable Is purchaseable.
	 * @param int  $course_id Course id.
	 *
	 * @return bool
	 */
	public function filter_purchaseable( $is_purchaseable, $course_id ) {
		if ( CourseBundle::POST_TYPE === get_post_type( $course_id ) ) {
			if ( Utils::is_free( $course_id ) ) {
				$is_purchaseable = false;
			}
		}

		return $is_purchaseable;
	}
}

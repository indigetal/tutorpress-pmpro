<?php
/**
 * Access Checker Interface
 *
 * Defines the contract for course access checking.
 *
 * @package TutorPress_PMPro
 * @subpackage Access
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Access;

/**
 * Interface Access_Checker_Interface
 *
 * Contract for checking user access to courses based on PMPro membership levels.
 * Supports three membership types:
 * - Full-site membership (access to all courses)
 * - Category-wise membership (access by course category)
 * - Course-specific levels (access to individual courses)
 */
interface Access_Checker_Interface {

	/**
	 * Check if user has membership access to a course.
	 *
	 * @param int      $course_id Course post ID.
	 * @param int|null $user_id   User ID (defaults to current user, 0 for logged-out users).
	 *
	 * @return bool|array True if user has access,
	 *                    false if course has no restrictions,
	 *                    array of required level IDs if user lacks access to restricted course.
	 */
	public function has_course_access( $course_id, $user_id = null );

	/**
	 * Check if any full-site membership levels exist.
	 *
	 * @return bool True if full-site levels exist, false otherwise.
	 */
	public function has_any_full_site_level();

	/**
	 * Get required membership levels for given categories.
	 *
	 * @param array $term_ids   Array of category term IDs.
	 * @param bool  $check_full Whether to check for full-site levels.
	 *
	 * @return array Array of required PMPro level objects.
	 */
	public function get_required_levels( $term_ids, $check_full = false );
}


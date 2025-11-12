<?php
/**
 * Access Checker
 *
 * Handles all course access verification logic for PMPro memberships.
 * Supports three membership types:
 * - Full-site membership (grants access to all courses)
 * - Category-wise membership (grants access by course category)
 * - Course-specific levels (grants access to individual courses)
 *
 * @package TutorPress_PMPro
 * @subpackage Access
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Access;

/**
 * Class Access_Checker
 *
 * Service class responsible for checking user access to courses based on PMPro membership levels.
 * Extracted from PaidMembershipsPro class to follow Single Responsibility Principle.
 */
class Access_Checker implements Access_Checker_Interface {

	/**
	 * Membership model constant: Full website membership
	 *
	 * @since 1.0.0
	 */
	const FULL_WEBSITE_MEMBERSHIP = 'full_website_membership';

	/**
	 * Membership model constant: Category-wise membership
	 *
	 * @since 1.0.0
	 */
	const CATEGORY_WISE_MEMBERSHIP = 'category_wise_membership';

	/**
	 * Check if user has membership access to a course.
	 *
	 * This method checks three types of PMPro membership access:
	 * 1. Full-site membership: Level with TUTORPRESS_PMPRO_membership_model = 'full_website_membership'
	 *    - Grants access to ALL courses on the site
	 * 2. Category-wise membership: Level with TUTORPRESS_PMPRO_membership_model = 'category_wise_membership'
	 *    - Grants access to courses in specific categories assigned to the level
	 * 3. Course-specific level: Level directly associated with course via pmpro_memberships_pages
	 *    - No membership_model meta set
	 *    - Grants access to individual courses
	 *
	 * Additional Behavior:
	 * - Filters out expired memberships (checks enddate timestamp)
	 * - Respects membership-only mode (logged-out users denied when enabled)
	 * - Returns gracefully when PMPro not available (grants access)
	 * - Returns array of required level IDs if user lacks access to restricted course
	 * - Includes WordPress cache support (5-minute TTL)
	 * - Provides 'tutorpress_pmpro_has_course_access' filter hook for extensibility
	 *
	 * @since 1.0.0
	 *
	 * @param int      $course_id Course post ID.
	 * @param int|null $user_id   User ID (defaults to current user, 0 for logged-out users).
	 *
	 * @return bool|array True if user has access,
	 *                    false if course has no restrictions,
	 *                    array of required level IDs if user lacks access to restricted course.
	 */
	public function has_course_access( $course_id, $user_id = null ) {
		global $wpdb;

		if ( ! tutor_utils()->has_pmpro( true ) ) {
			// Check if monetization is pmpro and the plugin exists.
			return true;
		}

		// Prepare data.
		$user_id = null === $user_id ? get_current_user_id() : $user_id;

		// Phase 4, Step 4.1: Check WordPress object cache
		// Cache key format: course_{course_id}_user_{user_id}_access
		$cache_key   = 'course_' . $course_id . '_user_' . $user_id . '_access';
		$cache_group = 'tutorpress_pmpro';

		$cached_result = wp_cache_get( $cache_key, $cache_group );
		if ( false !== $cached_result ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[TP-PMPRO] has_course_access cache HIT course=' . $course_id . ' user=' . $user_id );
			}
			return $cached_result;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[TP-PMPRO] has_course_access cache MISS course=' . $course_id . ' user=' . $user_id );
		}

		$has_course_access = false;

		// Phase 2: In membership-only mode, logged-out users have NO access
		if ( \TUTORPRESS_PMPRO\PaidMembershipsPro::tutorpress_pmpro_membership_only_enabled() && ! $user_id ) {
			// Cache this result too (short TTL for logged-out users)
			wp_cache_set( $cache_key, false, $cache_group, 300 );
			return false;
		}

		// Get all membership levels of this user.
		$levels = $user_id ? pmpro_getMembershipLevelsForUser( $user_id ) : array();
		! is_array( $levels ) ? $levels = array() : 0;

		// Get course categories by id.
		$terms    = get_the_terms( $course_id, 'course-category' );
		$term_ids = array_map(
			function ( $term ) {
				return $term->term_id;
			},
			( is_array( $terms ) ? $terms : array() )
		);

		$required_cats = $this->get_required_levels( $term_ids );

		// Check if course has any PMPro level associations (course-specific levels)
		$has_course_levels = false;
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$has_course_levels = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d",
					$course_id
				)
			) > 0;
		}

		// Only grant automatic access if:
		// 1. No category restrictions exist
		// 2. No full-site levels exist
		// 3. No course-specific levels exist (course is not PMPro-restricted)
		if ( is_array( $required_cats ) && ! count( $required_cats ) && ! $this->has_any_full_site_level() && ! $has_course_levels ) {
			// Course has no PMPro restrictions at all - grant access
			// Cache this result
			wp_cache_set( $cache_key, true, $cache_group, 300 );
			return true;
		}

		// Check if any level has access to the course.
		foreach ( $levels as $level ) {
			// Remove enrolment of expired levels.
			$endtime = (int) $level->enddate;
			if ( 0 < $endtime && $endtime < tutor_time() ) {
				// Remove here.
				continue;
			}

			if ( $has_course_access ) {
				// No need further check if any level has access to the course.
				continue;
			}

			$model = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_membership_model', true );

			if ( self::FULL_WEBSITE_MEMBERSHIP === $model ) {
				// If any model of the user is full site then the user has membership access.
				$has_course_access = true;

			} elseif ( self::CATEGORY_WISE_MEMBERSHIP === $model ) {
				// Check this course if attached to any category that is linked with this membership.
				$member_cats = pmpro_getMembershipCategories( $level->id );
				$member_cats = array_map(
					function ( $member ) {
						return (int) $member;
					},
					( is_array( $member_cats ) ? $member_cats : array() )
				);

				// Check if the course id in the level category.
				foreach ( $term_ids as $term_id ) {
					if ( in_array( $term_id, $member_cats ) ) {
						$has_course_access = true;
						break;
					}
				}
			}
		}

		// Check course-specific levels (no membership model, but associated via pmpro_memberships_pages)
		if ( ! $has_course_access ) {
			foreach ( $levels as $level ) {
				// Skip expired levels
				$endtime = (int) $level->enddate;
				if ( 0 < $endtime && $endtime < tutor_time() ) {
					continue;
				}

				if ( $this->level_grants_course_access( $level->id, $course_id ) ) {
					$has_course_access = true;
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[TP-PMPRO] has_course_access granted via course-specific level=' . $level->id . ' course=' . $course_id );
					}
					break;
				}
			}
		}

		// Determine final result
		$result = $has_course_access ? true : $this->get_required_levels( $term_ids, true );

		/**
		 * Filter the course access result.
		 *
		 * Allows third-party plugins or custom code to override access decisions.
		 * This filter runs after all core access checks (full-site, category-wise,
		 * and course-specific levels) but before the result is cached.
		 *
		 * @since 1.0.0
		 *
		 * @param bool|array $result      Access result:
		 *                                - true: User has access to the course
		 *                                - false: User has no access
		 *                                - array: Array of required level IDs (no access)
		 * @param int        $course_id   Course post ID being checked.
		 * @param int        $user_id     User ID being checked (0 for logged-out users).
		 *
		 * @example
		 * // Grant promotional access to a specific course
		 * add_filter( 'tutorpress_pmpro_has_course_access', function( $has_access, $course_id, $user_id ) {
		 *     if ( $course_id === 123 && time() < strtotime('2025-12-31') ) {
		 *         return true; // Grant access during promotion
		 *     }
		 *     return $has_access;
		 * }, 10, 3 );
		 *
		 * @example
		 * // Block access for users with overdue payments
		 * add_filter( 'tutorpress_pmpro_has_course_access', function( $has_access, $course_id, $user_id ) {
		 *     if ( user_has_overdue_invoices( $user_id ) ) {
		 *         return false; // Block access regardless of membership
		 *     }
		 *     return $has_access;
		 * }, 10, 3 );
		 */
		$result = apply_filters( 'tutorpress_pmpro_has_course_access', $result, $course_id, $user_id );

		// Phase 4, Step 4.1: Cache the result (after filtering)
		// TTL: 300 seconds (5 minutes) - shorter than PMPro's 1 hour since access can change more frequently
		// In environments without object cache, this only lasts for the current request
		// In environments with Redis/Memcached, this persists across requests
		wp_cache_set( $cache_key, $result, $cache_group, 300 );

		return $result;
	}

	/**
	 * Check if a specific level grants access to a specific course via pmpro_memberships_pages.
	 *
	 * This checks for course-specific levels (those without a membership model meta)
	 * that are directly associated with the course.
	 *
	 * @since 1.0.0
	 * @param int $level_id  PMPro membership level ID.
	 * @param int $course_id Course post ID.
	 * @return bool True if level grants course access, false otherwise.
	 */
	private function level_grants_course_access( $level_id, $course_id ) {
		global $wpdb;

		// Only check course-specific association if no membership model is set
		$model = get_pmpro_membership_level_meta( $level_id, 'TUTORPRESS_PMPRO_membership_model', true );
		if ( ! empty( $model ) ) {
			return false; // Has a model, not a course-specific level
		}

		// Check pmpro_memberships_pages association
		$associated = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} 
				 WHERE membership_id = %d AND page_id = %d",
				$level_id,
				$course_id
			)
		);

		return ( $associated > 0 );
	}

	/**
	 * Check if any full-site membership levels exist.
	 *
	 * @since 1.0.0
	 * @return bool True if full-site levels exist, false otherwise.
	 */
	public function has_any_full_site_level() {
		global $wpdb;

		$count = $wpdb->get_var(
			"SELECT level_table.id
			FROM {$wpdb->pmpro_membership_levels} level_table 
				INNER JOIN {$wpdb->pmpro_membership_levelmeta} meta ON level_table.id=meta.pmpro_membership_level_id 
			WHERE 
				meta.meta_key='TUTORPRESS_PMPRO_membership_model' AND 
				meta.meta_value='full_website_membership'"
		);

		return (int) $count;
	}

	/**
	 * Get required membership levels for given categories.
	 *
	 * @since 1.0.0
	 * @param array $term_ids   Array of category term IDs.
	 * @param bool  $check_full Whether to check for full-site levels.
	 *
	 * @return array Array of required PMPro level objects.
	 */
	public function get_required_levels( $term_ids, $check_full = false ) {
		global $wpdb;
		$cat_clause = count( $term_ids ) ? ( $check_full ? ' OR ' : '' ) . " (meta.meta_value='category_wise_membership' AND cat_table.category_id IN (" . implode( ',', $term_ids ) . '))' : '';

		$query_last = ( $check_full ? " meta.meta_value='full_website_membership' " : '' ) . $cat_clause;
		$query_last = ( ! $query_last || ctype_space( $query_last ) ) ? '' : ' AND (' . $query_last . ')';

		// phpcs:disable
		return $wpdb->get_results(
			"SELECT DISTINCT level_table.*
			FROM {$wpdb->pmpro_membership_levels} level_table 
				LEFT JOIN {$wpdb->pmpro_memberships_categories} cat_table ON level_table.id=cat_table.membership_id
				LEFT JOIN {$wpdb->pmpro_membership_levelmeta} meta ON level_table.id=meta.pmpro_membership_level_id 
			WHERE 
				meta.meta_key='TUTORPRESS_PMPRO_membership_model' " . $query_last
		);
		// phpcs:enable
	}
}


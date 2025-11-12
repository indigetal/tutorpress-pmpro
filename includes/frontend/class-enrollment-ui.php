<?php
/**
 * Enrollment UI
 *
 * Handles frontend enrollment UI and user action logic.
 * Manages enrollment buttons, guest restrictions, and enrollment tracking.
 *
 * @package TutorPress_PMPro
 * @subpackage Frontend
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Frontend;

/**
 * Class Enrollment_UI
 *
 * Service class responsible for enrollment-related UI and actions on the frontend.
 * Extracted from PaidMembershipsPro class to follow Single Responsibility Principle.
 */
class Enrollment_UI {

	/**
	 * Access checker service instance.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\Access\Access_Checker
	 */
	private $access_checker;

	/**
	 * Pricing display service instance.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\Frontend\Pricing_Display
	 */
	private $pricing_display;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param \TUTORPRESS_PMPRO\Access\Access_Checker    $access_checker    Access checker instance.
	 * @param \TUTORPRESS_PMPRO\Frontend\Pricing_Display $pricing_display   Pricing display instance.
	 */
	public function __construct( $access_checker, $pricing_display ) {
		$this->access_checker  = $access_checker;
		$this->pricing_display = $pricing_display;
	}

	/**
	 * Register frontend enrollment hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// Enrollment UI display
		add_filter( 'tutor/course/single/entry-box/purchasable', array( $this, 'show_pmpro_membership_plans' ), 12, 2 );
		
		// Enrollment behavior
		add_filter( 'tutor_allow_guest_attempt_enrollment', array( $this, 'filter_allow_guest_attempt_enrollment' ), 11, 3 );
		add_action( 'tutor_after_enrolled', array( $this, 'handle_after_enrollment_completed' ), 10, 3 );
	}

	/**
	 * Show PMPro membership plans for purchasable courses.
	 *
	 * Displays membership pricing options in the entry box for courses
	 * set to purchasable mode. Delegates to Pricing_Display for actual rendering.
	 *
	 * @since 1.0.0
	 * @param string $html      Current HTML output.
	 * @param int    $course_id Course post ID.
	 * @return string Modified HTML output showing membership plans.
	 */
	public function show_pmpro_membership_plans( $html, $course_id ) {
		// Always respect free courses - they should never show PMPro pricing
		$is_free = get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free';
		if ( $is_free ) {
			return $html;
		}

		// For all paid courses with PMPro monetization, show PMPro pricing:
		// - one_time: Shows PMPro one-time level
		// - subscription: Shows PMPro subscription plans
		// - both: Shows PMPro one-time + subscription plans
		// - membership: Shows full-site membership levels
		// - all: Shows all PMPro levels (course-specific + full-site membership)
		//
		// Delegate to Pricing_Display for the actual rendering
		return $this->pricing_display->pmpro_pricing( $html, $course_id );
	}

	/**
	 * Filter to block guest enrollment attempts when membership-only mode is enabled.
	 *
	 * When membership-only mode is ON, guests cannot enroll in courses.
	 * They must register and purchase a membership first.
	 *
	 * @since 1.0.0
	 * @param bool $allowed   Whether guest enrollment is allowed.
	 * @param int  $course_id Course ID.
	 * @param int  $user_id   User ID (0 for guests).
	 * @return bool False if membership-only mode is enabled, otherwise original value.
	 */
	public function filter_allow_guest_attempt_enrollment( $allowed, $course_id, $user_id ) {
		if ( \TUTORPRESS_PMPRO\PaidMembershipsPro::tutorpress_pmpro_membership_only_enabled() ) {
			return false;
		}

		return $allowed;
	}

	/**
	 * Handle enrollment flagging after enrollment is completed.
	 *
	 * Mark enrollments as PMPro membership-based when appropriate.
	 * This helps track which enrollments came from membership plans vs individual purchases.
	 *
	 * @since 1.0.0
	 * @param int $course_id   Course ID.
	 * @param int $user_id     User ID.
	 * @param int $enrolled_id Enrollment ID.
	 * @return void
	 */
	public function handle_after_enrollment_completed( $course_id, $user_id, $enrolled_id ) {
		$membership_enrollment_flag_required = false;

		// For membership-only mode: flag all enrollments as membership-based
		if ( \TUTORPRESS_PMPRO\PaidMembershipsPro::tutorpress_pmpro_membership_only_enabled() ) {
			$membership_enrollment_flag_required = true;
		}

		// For hybrid mode: check if this enrollment is via PMPro membership
		// (user has active membership level)
		if ( ! $membership_enrollment_flag_required && function_exists( 'pmpro_hasMembershipLevel' ) ) {
			if ( pmpro_hasMembershipLevel( null, $user_id ) ) {
				$membership_enrollment_flag_required = true;
			}
		}

		if ( $membership_enrollment_flag_required ) {
			// Get user's active PMPro membership levels
			$user_levels = function_exists( 'pmpro_getMembershipLevelsForUser' )
				? pmpro_getMembershipLevelsForUser( $user_id )
				: array();

			if ( ! empty( $user_levels ) ) {
				// Get the first active level (could be enhanced to find the specific level that grants access)
				$level    = is_array( $user_levels ) ? reset( $user_levels ) : null;
				$level_id = $level && isset( $level->id ) ? (int) $level->id : 0;

				if ( $level_id > 0 ) {
					// Mark this enrollment as PMPro membership-based
					// Store the level ID in enrollment meta for tracking
					update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_enrollment', 1 );
					update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_level_id', $level_id );

					// Handle bundle courses if Course Bundle addon is enabled
					if ( tutor_utils()->is_addon_enabled( 'course-bundle' ) &&
						 function_exists( 'tutor' ) &&
						 isset( tutor()->bundle_post_type ) &&
						 tutor()->bundle_post_type === get_post_type( $course_id ) ) {

						$this->handle_bundle_course_membership_enrollment( $course_id, $user_id, $level_id );
					}
				}
			}
		}
	}

	/**
	 * Handle auto-enrollment in bundle courses for membership enrollments.
	 *
	 * When a user enrolls in a bundle via membership,
	 * automatically enroll them in all courses within the bundle.
	 *
	 * @since 1.0.0
	 * @param int $bundle_id Bundle course ID.
	 * @param int $user_id   User ID.
	 * @param int $level_id  PMPro membership level ID.
	 * @return void
	 */
	private function handle_bundle_course_membership_enrollment( $bundle_id, $user_id, $level_id ) {
		// Check if BundleModel class exists (from Course Bundle addon)
		if ( ! class_exists( 'TutorPro\CourseBundle\Models\BundleModel' ) ) {
			return;
		}

		$bundle_course_ids = \TutorPro\CourseBundle\Models\BundleModel::get_bundle_course_ids( $bundle_id );

		foreach ( $bundle_course_ids as $bundle_course_id ) {
			// Check if user has access to this course via their membership
			$has_access = $this->access_checker->has_course_access( $bundle_course_id, $user_id );

			if ( $has_access ) {
				// Auto-enroll in the bundle course
				add_filter(
					'tutor_enroll_data',
					function ( $enroll_data ) {
						return array_merge( $enroll_data, array( 'post_status' => 'completed' ) );
					}
				);

				$course_enrolled_id = tutor_utils()->do_enroll( $bundle_course_id, 0, $user_id, false );

				if ( $course_enrolled_id ) {
					// Mark this course enrollment as membership-based too
					update_post_meta( $course_enrolled_id, '_tutorpress_pmpro_membership_enrollment', 1 );
					update_post_meta( $course_enrolled_id, '_tutorpress_pmpro_membership_level_id', $level_id );
				}
			}
		}
	}
}


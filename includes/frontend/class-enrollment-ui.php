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

}

<?php
/**
 * Initialize Gift Course
 *
 * @package TutorPro\GiftCourse
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.8.0
 */

namespace TutorPro\GiftCourse;

use TUTOR\Container;

/**
 * Initialize gift course feature
 *
 * @since 3.8.0
 */
class InitGift {

	/**
	 * Register hooks.
	 *
	 * @since 3.3.0
	 */
	public function __construct() {
		add_filter( 'tutor/options/extend/attr', array( $this, 'gift_course_setting_field' ), 13 );
		if ( ! $this->is_enabled() ) {
			return;
		}

		$this->include_modules();
	}

	/**
	 * Add gift course settings field.
	 *
	 * @since 3.8.0
	 *
	 * @param array $attr attr.
	 *
	 * @return array
	 */
	public function gift_course_setting_field( $attr ) {
		$attr['course']['blocks']['block_course']['fields'][] = array(
			'key'     => 'enable_gift_course',
			'type'    => 'toggle_switch',
			'label'   => __( 'Enable Course Gifting', 'tutor-pro' ),
			'default' => 'off',
			'desc'    => __( 'Allow users to purchase and send courses as gifts.', 'tutor-pro' ),
		);

		return $attr;
	}

	/**
	 * Include modules
	 *
	 * @since 3.8.0
	 *
	 * @return void
	 */
	public function include_modules() {
		$classes = array(
			GiftCourse::class,
			EventHandler::class,
			GiftEnrollment::class,
			GiftScheduler::class,
		);

		foreach ( $classes as $class ) {
			Container::make( $class );
		}
	}

	/**
	 * Check whether gift course is enabled & useable
	 *
	 * This method consider all the scenarios that should
	 * meet to use this feature
	 *
	 * @since 3.8.0
	 *
	 * @return boolean
	 */
	public function is_enabled(): bool {
		// Gift course only works with woocommerce and tutor native.
		$monetization = tutor_utils()->get_option( 'monetize_by' );
		if ( 'tutor' !== $monetization && 'wc' !== $monetization ) {
			return false;
		}

		$subscription_enabled = tutor_utils()->is_addon_enabled( 'subscription' );
		if ( $subscription_enabled ) {
			$membership_only_mode_enabled = tutor_utils()->get_option( 'membership_only_mode', false );
			if ( $membership_only_mode_enabled ) {
				return false;
			}
		}

		$enable_gift_course = tutor_utils()->get_option( 'enable_gift_course', false );
		if ( ! $enable_gift_course ) {
			return false;
		}

		return true;
	}
}

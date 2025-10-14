<?php
/**
 * Manage shortcodes related to subscription
 *
 * @package TutorPro\Subscription
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.2.0
 */

namespace TutorPro\Subscription;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Short code class
 *
 * @since 3.2.0
 */
class Shortcode {
	/**
	 * Membership pricing shortcode name.
	 *
	 * @since 3.2.0
	 */
	const MEMBERSHIP_PRICING = 'tutor_membership_pricing';

	/**
	 * Register hooks
	 *
	 * @since 3.2.0
	 */
	public function __construct() {
		add_shortcode( self::MEMBERSHIP_PRICING, array( $this, 'membership_pricing_page' ) );
	}

	/**
	 * Tutor Membership Page Shortcode
	 *
	 * @since 3.2.0
	 *
	 * @return mixed
	 */
	public function membership_pricing_page() {
		ob_start();
		tutor_load_template_from_custom_path( Utils::template_path( 'shortcode/pricing.php', array() ) );
		return apply_filters( 'tutor_membership_pricing', ob_get_clean() );
	}
}

<?php
/**
 * Handle PMPro logics
 * 
 * @package TutorPress
 * @subpackage PMPro
 * @author Indigetal WebCraft <support@indigetal.com>
 * @link https://indigetal.com
 * @since 0.1.0
 *
 */

namespace TUTORPRESS_PMPRO;

use Tutor\Helpers\QueryHelper;
use TUTOR\Input;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class PaidMembershipsPro
 *
 * @since 1.3.5
 */
class PaidMembershipsPro {
    /**
	 * Membership types constants.
	 *
	 * @since 2.5.0
	 */
    const FULL_WEBSITE_MEMBERSHIP  = 'full_website_membership';
    const CATEGORY_WISE_MEMBERSHIP = 'category_wise_membership';

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
     * Enrollment UI service instance.
     *
     * @since 1.0.0
     * @var \TUTORPRESS_PMPRO\Frontend\Enrollment_UI
     */
    private $enrollment_ui;

    /**
     * PMPro Orders Integration service instance.
     *
     * @since 1.0.0
     * @var \TUTORPRESS_PMPRO\Frontend\Pmpro_Orders_Integration
     */
    private $pmpro_orders_integration;

    /**
     * Level settings service instance.
     *
     * @since 1.0.0
     * @var \TUTORPRESS_PMPRO\Admin\Level_Settings
     */
    private $level_settings;

    /**
     * Admin notices service instance.
     *
     * @since 1.0.0
     * @var \TUTORPRESS_PMPRO\Admin\Admin_Notices
     */
    private $admin_notices;

    /**
     * Sale price handler service instance.
     *
     * @since 1.0.0
     * @var \TUTORPRESS_PMPRO\Pricing\Sale_Price_Handler
     */
    private $sale_price_handler;

    /**
     * Backend pricing service instance.
     *
     * @since 1.0.0
     * @var \TUTORPRESS_PMPRO\Pricing\Backend_Pricing
     */
    private $backend_pricing;

    /**
     * Enrollment handler service instance.
     *
     * @since 1.0.0
     * @var \TUTORPRESS_PMPRO\Enrollment\Enrollment_Handler
     */
    private $enrollment_handler;

    /**
	 * Register hooks
	 */
    public function __construct() {
        // Load service classes (required before container can instantiate them)
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/access/interface-access-checker.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/access/class-access-checker.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/pricing/class-pricing-manager.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/pricing/class-sale-price-handler.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/pricing/class-backend-pricing.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/enrollment/class-enrollment-handler.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/admin/class-level-settings.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/admin/class-admin-notices.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/frontend/class-pricing-display.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/frontend/class-enrollment-ui.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/frontend/class-pmpro-orders-integration.php';
        
        // Load service container
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/class-service-container.php';
        
        // Initialize services via container
        $this->access_checker      = Service_Container::get( 'access_checker' );
        $this->sale_price_handler  = Service_Container::get( 'sale_price_handler' );
        $this->backend_pricing     = Service_Container::get( 'backend_pricing' );
        $this->enrollment_handler  = Service_Container::get( 'enrollment_handler' );
        $this->level_settings      = Service_Container::get( 'level_settings' );
        $this->admin_notices       = Service_Container::get( 'admin_notices' );
        
        // Store frontend services for later hook registration
        $this->pmpro_orders_integration = Service_Container::get( 'pmpro_orders_integration' );
        
        // Initialize pricing_display (needs $this reference)
        $this->pricing_display = new \TUTORPRESS_PMPRO\Frontend\Pricing_Display(
            $this->access_checker,
            $this,
            $this->sale_price_handler,
            $this->enrollment_handler
        );
        
        // Initialize enrollment_ui (depends on pricing_display)
        $this->enrollment_ui = new \TUTORPRESS_PMPRO\Frontend\Enrollment_UI(
            $this->access_checker,
            $this->pricing_display
        );
        
        // Register PMPro admin settings hooks early (needed before wp hook)
        add_action( 'pmpro_membership_level_after_other_settings', array( $this->level_settings, 'display_courses_categories' ) );
        add_action( 'pmpro_save_membership_level', array( $this->level_settings, 'pmpro_settings' ) );
        add_filter( 'tutor/options/attr', array( $this->level_settings, 'add_options' ) );
        
        // Register backend pricing hooks (for admin/REST API display)
        $this->backend_pricing->register_hooks();
        
        // Register frontend pricing/enrollment hooks on wp hook (ensures Tutor is loaded)
        add_action( 'wp', array( $this, 'init_pmpro_price_overrides' ), 20 );
        
        // Also register hooks for REST API requests (Gutenberg editor)
        // Use priority 5 to ensure hooks are registered before REST routes are processed
        add_action( 'rest_api_init', array( $this, 'init_pmpro_price_overrides' ), 5 );

		if ( tutor_utils()->has_pmpro( true ) ) {
			// Only wire PMPro behaviors when PMPro is the selected monetization engine (overridable via filter)
			if ( ! $this->is_pmpro_enabled() ) {
				return;
			}

			// Membership-Only Mode filter
			$this->wire_membership_only_mode_filter();
        }
    }

    /**
     * Initialize frontend pricing and enrollment hooks.
     * 
     * Called on 'wp' hook to ensure all Tutor utilities are fully loaded.
     * Registers all pricing display and enrollment UI hooks.
	 *
	 * @return void
	 */
    public function init_pmpro_price_overrides() {
        // Guard: only run on frontend or REST API, not regular admin pages
        // Allow REST API requests (Gutenberg editor) and AJAX to register hooks
        if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            // Exception: if we're being called from rest_api_init hook, proceed anyway
            if ( ! doing_action( 'rest_api_init' ) ) {
                return;
            }
        }

        // Guard: PMPro must be active and selected as monetization engine
        if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
            return;
        }

        if ( ! $this->is_pmpro_enabled() ) {
            return;
        }

        // Register frontend service hooks
        $this->pricing_display->register_hooks();
        $this->enrollment_ui->register_hooks();
        $this->pmpro_orders_integration->register_hooks();
    }

    /**
     * Get PMPro currency settings (symbol and position).
     * 
     * Delegates to Pricing_Manager for centralized currency handling.
     * 
     * @since 1.0.0
     * @return array Array with 'currency_symbol' and 'currency_position' keys.
     */
    public function get_pmpro_currency() {
        return \TUTORPRESS_PMPRO\Pricing\Pricing_Manager::get_pmpro_currency();
    }

	/**
	 * Determine if PMPro integration should be active based on monetization engine.
	 * Allow override via filter 'tutorpress_pmpro_enabled'.
	 *
	 * @return bool
	 */
    public function is_pmpro_enabled() {
        $forced = apply_filters( 'tutorpress_pmpro_enabled', null );
        if ( is_bool( $forced ) ) {
            return $forced;
        }
        // Check Tutor option directly first (most reliable on frontend)
        if ( function_exists( 'get_tutor_option' ) ) {
            if ( 'pmpro' === get_tutor_option( 'monetize_by' ) ) {
                return true;
            }
        }
        // Then consult centralized helper if available
        if ( function_exists( 'tutorpress_monetization' ) ) {
            if ( tutorpress_monetization()->is_pmpro() ) {
                return true;
            }
        }
        return false;
	}

    /**
     * Wire the global tutor_membership_only_mode filter.
     * When PMPro is active, this filter returns the PMPro-local toggle value.
     *
     * @since 1.4.0
     * @return void
     */
    private function wire_membership_only_mode_filter() {
        add_filter( 'tutor_membership_only_mode', array( $this, 'filter_tutor_membership_only_mode' ) );
    }

    /**
     * Get the effective selling option for a course, respecting membership-only mode.
     * 
     * When membership-only mode is enabled, override all courses
     * to use SELLING_OPTION_MEMBERSHIP regardless of their individual settings.
     *
     * @since 1.4.0
     * @param int $course_id Course ID.
     * @return string Selling option constant.
     */
    public function get_effective_selling_option( $course_id ) {
        // If membership-only mode is enabled, force MEMBERSHIP selling option
        if ( self::tutorpress_pmpro_membership_only_enabled() ) {
            return \TUTOR\Course::SELLING_OPTION_MEMBERSHIP;
        }

        // Otherwise return the course's actual selling option
        return \TUTOR\Course::get_selling_option( $course_id );
    }

    /**
     * Filter callback for tutor_membership_only_mode.
     * Returns the PMPro membership-only setting when PMPro is active.
     * Validates that full-site membership levels exist before enforcing.
     *
     * @since 1.4.0
     * @return bool
     */
    public function filter_tutor_membership_only_mode() {
        // Only apply when PMPro is selected as the monetization engine
        if ( ! $this->is_pmpro_enabled() ) {
            // Fallback to native Tutor setting if PMPro is not active
            return (bool) tutor_utils()->get_option( 'membership_only_mode', false );
        }

        // Read the PMPro-local toggle (toggle_switch saves 'on' or 'off' strings)
        $toggle_value = tutor_utils()->get_option( 'tutorpress_pmpro_membership_only_mode', 'off' );
        $toggle_enabled = ( 'on' === $toggle_value || $toggle_value === true );

        // Only enforce if toggle is ON and at least one full-site level exists
        if ( $toggle_enabled && $this->pmpro_has_full_site_level() ) {
            return true;
        }

        return false;
    }

    /**
     * Check if at least one PMPro level with full_website_membership model exists.
     *
     * @since 1.4.0
     * @return bool
     */
    public static function pmpro_has_full_site_level() {
        global $wpdb;

        $count = $wpdb->get_var(
            "SELECT COUNT(level_table.id)
            FROM {$wpdb->pmpro_membership_levels} level_table 
            INNER JOIN {$wpdb->pmpro_membership_levelmeta} meta 
                ON level_table.id = meta.pmpro_membership_level_id 
            WHERE 
                meta.meta_key = 'TUTORPRESS_PMPRO_membership_model' AND 
                meta.meta_value = 'full_website_membership'"
        );

        return (int) $count > 0;
    }

    /**
     * Get all active PMPro levels with full_website_membership model.
     *
     * @since 1.4.0
     * @return array Array of level IDs
     */
    public static function pmpro_get_active_full_site_levels() {
        global $wpdb;

        $level_ids = $wpdb->get_col(
            "SELECT DISTINCT level_table.id
            FROM {$wpdb->pmpro_membership_levels} level_table 
            INNER JOIN {$wpdb->pmpro_membership_levelmeta} meta 
                ON level_table.id = meta.pmpro_membership_level_id 
            WHERE 
                meta.meta_key = 'TUTORPRESS_PMPRO_membership_model' AND 
                meta.meta_value = 'full_website_membership'"
        );

        return is_array( $level_ids ) ? array_map( 'intval', $level_ids ) : array();
    }

    /**
     * Check if membership-only mode is enabled.
     * 
     * Returns true if the membership-only mode setting is enabled.
     * Note: This ONLY checks if the setting is enabled, NOT whether full-site
     * membership levels exist. The existence of levels should be validated
     * separately in the UI (has_full_site_levels metadata).
     *
     * @since 1.4.0
     * @return bool True if membership-only mode is enabled, false otherwise.
     */
    public static function tutorpress_pmpro_membership_only_enabled() {
        $toggle_value = tutor_utils()->get_option( 'tutorpress_pmpro_membership_only_mode', 'off' );
        $toggle_enabled = ( 'on' === $toggle_value || $toggle_value === true );
        return $toggle_enabled;
    }

}

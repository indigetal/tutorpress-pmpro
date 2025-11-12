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
	 * Register hooks
	 */
    public function __construct() {
        // Phase 7, Step 7.3: Load access control service
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/access/interface-access-checker.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/access/class-access-checker.php';
        $this->access_checker = new \TUTORPRESS_PMPRO\Access\Access_Checker();
        
        // Phase 8, Step 8.1: Load frontend services
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/frontend/class-pricing-display.php';
        $this->pricing_display = new \TUTORPRESS_PMPRO\Frontend\Pricing_Display( $this->access_checker, $this );
        
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/frontend/class-enrollment-ui.php';
        $this->enrollment_ui = new \TUTORPRESS_PMPRO\Frontend\Enrollment_UI( $this->access_checker, $this->pricing_display );
        
        // Register frontend pricing hooks late in page lifecycle
        add_action( 'wp', array( $this, 'init_pmpro_price_overrides' ), 20 );
        add_action( 'pmpro_membership_level_after_other_settings', array( $this, 'display_courses_categories' ) );
        add_action( 'pmpro_save_membership_level', array( $this, 'pmpro_settings' ) );
        add_filter( 'tutor_course/single/add-to-cart', array( $this, 'tutor_course_add_to_cart' ) );
        add_filter( 'tutor_course_price', array( $this, 'tutor_course_price' ) );
        add_filter( 'tutor-loop-default-price', array( $this, 'add_membership_required' ) );

        // Phase 8, Step 8.1: Moved to Frontend services
        // Pricing_Display:
        //   - tutor/course/single/entry-box/free → pmpro_pricing
        //   - tutor/course/single/entry-box/is_enrolled → pmpro_pricing
        //   - tutor/course/single/content/before/all → pmpro_pricing_single_course
        // Enrollment_UI:
        //   - tutor/course/single/entry-box/purchasable → show_pmpro_membership_plans
        //   - tutor_allow_guest_attempt_enrollment → filter_allow_guest_attempt_enrollment
        //   - tutor_after_enrolled → handle_after_enrollment_completed
        add_filter( 'tutor/options/attr', array( $this, 'add_options' ) );

		if ( tutor_utils()->has_pmpro( true ) ) {
			// Only wire PMPro behaviors when PMPro is the selected monetization engine (overridable via filter)
			if ( ! $this->is_pmpro_enabled() ) {
				return;
			}
            // Remove price column if PM pro used.
            add_filter( 'manage_' . tutor()->course_post_type . '_posts_columns', array( $this, 'remove_price_column' ), 11, 1 );

            // Add categories column to pm pro level table.
            add_action( 'pmpro_membership_levels_table_extra_cols_header', array( $this, 'level_category_list' ) );
            add_action( 'pmpro_membership_levels_table_extra_cols_body', array( $this, 'level_category_list_body' ) );
            add_filter( 'pmpro_membership_levels_table', array( $this, 'outstanding_cat_notice' ) );
            add_action( 'wp_enqueue_scripts', array( $this, 'pricing_style' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'admin_script' ) );

            add_filter( 'tutor_course_expire_validity', array( $this, 'filter_expire_time' ), 99, 2 );
			add_action( 'pmpro_after_change_membership_level', array( $this, 'remove_course_access' ), 10, 3 );

			// Keep Tutor enrollment in sync with PMPro membership level changes
			add_action( 'pmpro_after_all_membership_level_changes', array( $this, 'pmpro_after_all_membership_level_changes' ) );
			add_action( 'pmpro_after_change_membership_level', array( $this, 'pmpro_after_change_membership_level' ), 10, 3 );
			add_action( 'pmpro_after_checkout', array( $this, 'pmpro_after_checkout_enroll' ), 10, 2 );
			// Unenroll on refunded orders when no other valid level still grants access
			add_action( 'pmpro_order_status_refunded', array( $this, 'pmpro_order_status_refunded' ), 10, 2 );

			// Membership-Only Mode filter and helpers
			$this->wire_membership_only_mode_filter();

			// Phase 8, Step 8.1: Enrollment hooks moved to Enrollment_UI service
			// - tutor_allow_guest_attempt_enrollment → filter_allow_guest_attempt_enrollment
			// - tutor_after_enrolled → handle_after_enrollment_completed

			// Step 3.4c: PMPro Critical Filter Hooks for Dynamic Pricing (Zero-Delay Architecture)
			add_filter( 'pmpro_checkout_level', array( $this, 'filter_checkout_level_sale_price' ), 10, 1 ); // Changed priority to 10
			add_filter( 'pmpro_level_cost_text', array( $this, 'filter_level_cost_text_sale_price' ), 999, 4 ); // Changed priority to 999 to run AFTER
			add_filter( 'pmpro_email_data', array( $this, 'filter_email_data_sale_price' ), 10, 2 );
			add_action( 'pmpro_invoice_bullets_bottom', array( $this, 'filter_invoice_sale_note' ), 10, 1 );
			
			// Display notices on PMPro level edit page
			add_action( 'pmpro_membership_level_after_general_information', array( $this, 'display_course_association_notice' ), 10 );
			add_action( 'pmpro_membership_level_after_billing_details_settings', array( $this, 'display_sale_price_notice_on_level_edit' ), 10 );
        }
    }

    /**
     * Initialize pricing-related filters for archive, dashboard, and single contexts.
     *
     * - Inject minimal PMPro price strings via get_tutor_course_price (priority 12)
     * - Ensure Tutor loop uses the 'tutor' price template wrappers so price markup renders
     *
     * @return void
     */
    public function init_pmpro_price_overrides() {
        // Guard: only run on frontend, not admin
        if ( is_admin() ) {
            return;
        }

        // Guard: PMPro must be active and selected as monetization engine
        if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
            return;
        }

        if ( ! $this->is_pmpro_enabled() ) {
            return;
        }

        // Phase 8, Step 8.1: Register frontend services hooks
        $this->pricing_display->register_hooks();
        $this->enrollment_ui->register_hooks();

    }

    /**
     * [REMOVED - Phase 8, Step 8.1: Substep 2]
     * filter_get_tutor_course_price() → Moved to Pricing_Display::filter_get_tutor_course_price()
     */

    /**
     * Ensure Tutor uses the 'tutor' price template wrappers in loops
     * when the course has PMPro levels so our price string displays.
     *
     * @param string|null $sell_by
     * @return string|null
     */
    /**
     * [REMOVED - Phase 8, Step 8.1: Substep 1]
     * 
     * The following methods have been extracted to Pricing_Display service:
     * - filter_course_sell_by() → Pricing_Display::filter_course_sell_by()
     * - filter_course_loop_price_pmpro() → Pricing_Display::filter_course_loop_price_pmpro()
     * - render_membership_price_button() → Pricing_Display::render_membership_price_button()
     *
     * All functionality preserved via delegation to Pricing_Display service.
     */




    /**
    * On PMPro subscription expired, remove course access
    *
    * @see https://www.paidmembershipspro.com/hook/pmpro_subscription_expired
    *
    * @since 2.5.0
    *
    * @param \MemberOrder $old_order old order data.
    *
    * @return void
    */
    public function remove_course_access( $level_id, $user_id, $cancel_id ) {
        if ( ! $cancel_id ) {
            return;
        }

        $model = get_pmpro_membership_level_meta( $cancel_id, 'TUTORPRESS_PMPRO_membership_model', true );

        $all_models = array( self::FULL_WEBSITE_MEMBERSHIP, self::CATEGORY_WISE_MEMBERSHIP );
        if ( ! in_array( $model, $all_models, true ) ) {
            return;
        }

        $enrolled_courses = array();

        if ( self::FULL_WEBSITE_MEMBERSHIP === $model ) {
            $enrolled_courses = tutor_utils()->get_enrolled_courses_by_user( $user_id );
        }

        if ( self::CATEGORY_WISE_MEMBERSHIP === $model ) {
            $lbl_obj    = new \PMPro_Membership_Level();
            $categories = $lbl_obj->get_membership_level_categories( $cancel_id );
            if ( count( $categories ) ) {
                $enrolled_courses_ids = array_unique( tutor_utils()->get_enrolled_courses_ids_by_user( $user_id ) );
                if ( $enrolled_courses_ids ) {
                    $enrolled_courses = new \WP_Query(
                        array(
                            'post_type'      => tutor()->course_post_type,
                            'post_status'    => 'publish',
                            'posts_per_page' => -1,
                            'tax_query'      => array(
                                array(
                                    'taxonomy' => 'course-category',
                                    'field'    => 'term_id',
                                    'terms'    => $categories,
                                    'operator' => 'IN',
                                ),
                            ),
                            'post__in'       => $enrolled_courses_ids,
                        )
                    );
                }
            }
        }

        // Now cancel the course enrollment.
        if ( isset( $enrolled_courses->posts ) && is_array( $enrolled_courses->posts ) && count( $enrolled_courses->posts ) ) {
            foreach ( $enrolled_courses->posts as $course ) {
                tutor_utils()->cancel_course_enrol( $course->ID, $user_id );
            }
        }
    }

    /**
	 * Remove price column
	 *
	 * @param array $columns columns.
	 *
	 * @return array
	 */
    public function remove_price_column( $columns = array() ) {
        if ( isset( $columns['price'] ) ) {
            unset( $columns['price'] );
        }
        return $columns;
    }

    /**
	 * Display courses categories
	 *
	 * @return void
	 */
    public function display_courses_categories() {
        global $wpdb;

        if ( Input::has( 'edit' ) ) {
            $edit = intval( Input::sanitize_request_data( 'edit' ) );
        } else {
            $edit = false;
        }

        // get the level...
        if ( ! empty( $edit ) && $edit > 0 ) {
            $level   = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $wpdb->pmpro_membership_levels
                    WHERE id = %d LIMIT 1",
                    $edit
                ),
                OBJECT
            );
            $temp_id = $level->id;
        } elseif ( ! empty( $copy ) && $copy > 0 ) {
            $level     = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM $wpdb->pmpro_membership_levels
                    WHERE id = %d LIMIT 1",
                    $copy
                ),
                OBJECT
            );
            $temp_id   = $level->id;
            $level->id = null;
        } elseif ( empty( $level ) ) {
            // didn't find a membership level, let's add a new one...
            $level                    = new \stdClass();
            $level->id                = null;
            $level->name              = null;
            $level->description       = null;
            $level->confirmation      = null;
            $level->billing_amount    = null;
            $level->trial_amount      = null;
            $level->initial_payment   = null;
            $level->billing_limit     = null;
            $level->trial_limit       = null;
            $level->expiration_number = null;
            $level->expiration_period = null;
            $edit                     = -1;
        }

        // defaults for new levels.
        if ( empty( $copy ) && -1 == $edit ) {
            $level->cycle_number = 1;
            $level->cycle_period = 'Month';
        }

        // grab the categories for the given level...
        if ( ! empty( $temp_id ) ) {
            $level->categories = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT c.category_id
                    FROM $wpdb->pmpro_memberships_categories c
                    WHERE c.membership_id = %d",
                    $temp_id
                )
            );
        }

        if ( empty( $level->categories ) ) {
            $level->categories = array();
        }

        $level_categories = $level->categories;
        $highlight        = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_level_highlight', true );

        include_once TUTORPRESS_PMPRO()->path . 'views/pmpro-content-settings.php';
    }

    /**
     * PM Pro save tutor settings
     *
     * @param int $level_id level id.
     *
     * @return void
     */
    public function pmpro_settings( $level_id ) {

        if ( 'pmpro_settings' !== Input::post( 'tutor_action' ) ) {
            return;
        }

        // Admin-only gating: prevent non-admins from modifying membership model and highlight settings
        if ( ! current_user_can( 'manage_options' ) ) {
            // Log security audit trail
            if ( function_exists( 'error_log' ) ) {
                error_log( sprintf(
                    '[TP-PMPRO] Unauthorized attempt to modify membership model for level %d by user %d',
                    absint( $level_id ),
                    get_current_user_id()
                ) );
            }
            return;
        }

        $TUTORPRESS_PMPRO_membership_model = Input::post( 'TUTORPRESS_PMPRO_membership_model' );
        $highlight_level              = Input::post( 'TUTORPRESS_PMPRO_level_highlight' );

        if ( $TUTORPRESS_PMPRO_membership_model ) {
            update_pmpro_membership_level_meta( $level_id, 'TUTORPRESS_PMPRO_membership_model', $TUTORPRESS_PMPRO_membership_model );
        }

        if ( $highlight_level && 1 == $highlight_level ) {
            update_pmpro_membership_level_meta( $level_id, 'TUTORPRESS_PMPRO_level_highlight', 1 );
        } else {
            delete_pmpro_membership_level_meta( $level_id, 'TUTORPRESS_PMPRO_level_highlight' );
        }
    }

    /**
	 * Add options.
	 *
	 * @param array $attr attr.
	 *
	 * @return array
	 */
    public function add_options( $attr ) {
        $attr['TUTORPRESS_PMPRO'] = array(
            'label'    => __( 'PMPro-TutorPress', 'tutorpress-pmpro' ),
            'slug'     => 'pm-pro',
            'desc'     => __( 'Paid Membership', 'tutorpress-pmpro' ),
            'template' => 'basic',
            'icon'     => 'tutor-icon-brand-paid-membersip-pro',
            'blocks'   => array(
                array(
                    'label'      => '',
                    'slug'       => 'pm_pro',
                    'block_type' => 'uniform',
                    'fields'     => array(
                        array(
                            'key'     => 'tutorpress_pmpro_membership_only_mode',
                            'type'    => 'toggle_switch',
                            'label'   => __( 'Membership-Only Mode', 'tutorpress-pmpro' ),
                            'label_title' => '',
                            'default' => 'off',
                            'desc'    => __( 'Enable this to sell courses exclusively through membership plans. Individual course sales will be disabled.', 'tutorpress-pmpro' ),
                        ),
                        array(
                            'key'     => 'pmpro_moneyback_day',
                            'type'    => 'number',
                            'label'   => __( 'Moneyback gurantee in', 'tutorpress-pmpro' ),
                            'default' => '0',
                            'desc'    => __( 'Days in you gurantee moneyback. Set 0 for no moneyback.', 'tutorpress-pmpro' ),
                        ),
                        array(
                            'key'     => 'pmpro_no_commitment_message',
                            'type'    => 'text',
                            'label'   => __( 'No commitment message', 'tutorpress-pmpro' ),
                            'default' => '',
                            'desc'    => __( 'Keep empty to hide', 'tutorpress-pmpro' ),
                        ),
                    ),
                ),
            ),
        );

        return $attr;
    }

    /**
	 * Required levels
	 *
	 * Get required membership levels for given categories.
     *
     * Phase 7, Step 7.3: Delegated to Access_Checker service.
     *
     * @since 1.0.0
     * @param array $term_ids   Array of category term IDs.
     * @param bool  $check_full Whether to check for full-site levels.
     * @return array Array of required PMPro level objects.
	 */
    private function required_levels( $term_ids, $check_full = false ) {
        return $this->access_checker->get_required_levels( $term_ids, $check_full );
    }

    /**
	 * Check if any full-site membership levels exist.
     *
     * Phase 7, Step 7.3: Delegated to Access_Checker service.
	 *
     * @since 1.0.0
	 * @return bool True if full-site levels exist, false otherwise.
	 */
    private function has_any_full_site_level() {
        return $this->access_checker->has_any_full_site_level();
    }

    /**
     * Check if user has membership access to a course.
     *
     * Phase 7, Step 7.3: Delegated to Access_Checker service.
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
    private function has_course_access( $course_id, $user_id = null ) {
        return $this->access_checker->has_course_access( $course_id, $user_id );
    }

    /**
	 * Add membership required.
	 *
	 * @param mixed $price price.
	 *
	 * @return mixed
	 */
    public function add_membership_required( $price ) {
        return ! ( $this->has_course_access( get_the_ID() ) === true ) ? '' : __( 'Free', 'pmpro-tutorlms' );
    }

    /**
	 * Tutor course add to cart
	 *
	 * @param mixed $html html.
	 *
	 * @return mixed
	 */
    public function tutor_course_add_to_cart( $html ) {
        $access_require = $this->has_course_access( get_the_ID() );
        if ( true === $access_require ) {
            // If has membership access, then no need membership require message.
            return $html;
        }

        return apply_filters( 'tutor_enrol_no_membership_msg', '' );
    }

    /**
	 * [REMOVED - Phase 8, Step 8.1: Substep 2]
	 * pmpro_pricing_single_course() → Moved to Pricing_Display::pmpro_pricing_single_course()
	 */

    /**
	 * [REMOVED - Phase 8, Step 8.1: Substep 2]
	 * pmpro_pricing() → Moved to Pricing_Display::pmpro_pricing()
	 */

    /**
	 * Remove the price if Membership Plan activated
	 *
	 * @param string $html html.
	 *
	 * @return mixed
	 */
    public function tutor_course_price( $html ) {
        return 'pmpro' === get_tutor_option( 'monetize_by' ) ? '' : $html;
    }

    /**
	 * Level category list
	 *
	 * @param mixed $reordered_levels reordered levels.
	 *
	 * @return void
	 */
    public function level_category_list( $reordered_levels ) {
        echo '<th>' . esc_html__( 'Recommended', 'tutorpress-pmpro' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'tutorpress-pmpro' ) . '</th>';
    }

    /**
	 * Level category list body
	 *
	 * @param object $level level object.
	 *
	 * @return void
	 */
    public function level_category_list_body( $level ) {
        $model     = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_membership_model', true );
        $highlight = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_level_highlight', true );

        echo '<td>' . ( esc_html( $highlight ) ? '<img src="' . esc_url( TUTORPRESS_PMPRO()->url . 'assets/images/star.svg"/>' ) : '' ) . '</td>';

        echo '<td>';

        if ( 'full_website_membership' === $model ) {
            echo '<b>' . esc_html__( 'Full Site Membership', 'tutorpress-pmpro' ) . '</b>';
        } elseif ( 'category_wise_membership' === $model ) {
            echo '<b>' . esc_html__( 'Category Wise Membership', 'tutorpress-pmpro' ) . '</b><br/>';

            $cats = pmpro_getMembershipCategories( $level->id );

            if ( is_array( $cats ) && count( $cats ) ) {
                global $wpdb;
                $cats_str   = QueryHelper::prepare_in_clause( $cats );
                $terms      = $wpdb->get_results( "SELECT * FROM {$wpdb->terms} WHERE term_id IN (" . $cats_str . ')' );
                $term_links = array_map(
                    function( $term ) {
                        return '<small>' . $term->name . '</small>';
                    },
                    $terms
                );

                echo wp_kses_post( implode( ', ', $term_links ) );
            }
        }
        //phpcs:enable

        echo '</td>';
    }

    /**
	 * Get PMPro currency
	 *
	 * @return mixed
	 */
    public function get_pmpro_currency() {
        global $pmpro_currencies, $pmpro_currency;
        $current_currency = $pmpro_currency ? $pmpro_currency : '';
        $currency         = 'USD' === $current_currency ?
                                array( 'symbol' => '$' ) :
                                ( isset( $pmpro_currencies[ $current_currency ] ) ? $pmpro_currencies[ $current_currency ] : null );

        $currency_symbol   = ( is_array( $currency ) && isset( $currency['symbol'] ) ) ? $currency['symbol'] : '';
        $currency_position = ( is_array( $currency ) && isset( $currency['position'] ) ) ? strtolower( $currency['position'] ) : 'left';

        return compact( 'currency_symbol', 'currency_position' );
    }

    /**
	 * Outstanding cat notice.
	 *
	 * @param string $html html.
	 *
	 * @return string
	 */
    public function outstanding_cat_notice( $html ) {
        global $wpdb;

        // Get all categories from all levels.
        $level_cats = $wpdb->get_col(
            "SELECT cat.category_id 
            FROM {$wpdb->pmpro_memberships_categories} cat 
                INNER JOIN {$wpdb->pmpro_membership_levels} lvl ON lvl.id=cat.membership_id"
        );
        ! is_array( $level_cats ) ? $level_cats = array() : 0;

        // Get all categories and check if exist in any level.
        $outstanding = array();
        $course_cats = get_terms( 'course-category', array( 'hide_empty' => false ) );
        foreach ( $course_cats as $cat ) {
            ! in_array( $cat->term_id, $level_cats ) ? $outstanding[] = $cat : 0;
        }

        ob_start();

        //phpcs:ignore
        extract( $this->get_pmpro_currency() ); // $currency_symbol, $currency_position
        include dirname( __DIR__ ) . '/views/outstanding-catagory-notice.php';

        return $html . ob_get_clean();
    }

    /**
	 * Style enqueue
	 *
	 * @return void
	 */
    public function pricing_style() {
        if ( function_exists( 'is_single_course' ) && is_single_course() ) {
            wp_enqueue_style( 'tutorpress-pmpro-pricing', TUTORPRESS_PMPRO()->url . 'assets/css/pricing.css', array(), TUTORPRESS_PMPRO_VERSION );
        }
    }

    /**
	 * Admin style enqueue
	 *
	 * @return void
	 */
    public function admin_script() {
        $screen = get_current_screen();
        if ( 'memberships_page_pmpro-membershiplevels' === $screen->id ) {
            wp_enqueue_style( 'tutorpress-pmpro', TUTORPRESS_PMPRO()->url . 'assets/css/pm-pro.css', array(), TUTORPRESS_PMPRO_VERSION );
        }
    }

    /**
	 * Filter course expire time
	 *
	 * @since 1.0.0
	 *
	 * @param string $validity course validity.
	 * @param int    $course_id course id.
	 *
	 * @return string validity time
	 */
    public function filter_expire_time( $validity, $course_id ) {
        $monetize_by = tutor_utils()->get_option( 'monetize_by' );
        if ( 'pmpro' !== $monetize_by ) {
            return $validity;
        }
        $user_id = get_current_user_id();

        /**
		 * The has_course_access method returns true if user has course
		 * access, if not then returns array of required levels
		 */
        $has_access      = $this->has_course_access( $course_id );
        $term_ids        = $this->get_term_ids( $course_id );
        $required_levels = $this->required_levels( $term_ids );
        $user_levels     = pmpro_getMembershipLevelsForUser( $user_id );
        $is_enrolled     = tutor_utils()->is_enrolled( $course_id, $user_id );

        if ( false === $is_enrolled ) {
            // If course has levels.
                if ( is_array( $has_access ) && count( $has_access ) ) {
                    $validity = __( 'Membership Wise', 'tutorpress-pmpro' );
                }
            // User not enrolled but just paid and will enroll.
            if ( true === $has_access ) {
                $validity = __( 'Membership Wise', 'tutorpress-pmpro' );
            }
        } else {
            // Check if user has level for the current course.
            $user_has_level = null;

            if ( is_array( $required_levels ) && count( $required_levels ) ) {
                foreach ( $required_levels as $key => $req_level ) {
                    $level_id = $req_level->id ?? 0;
                    if ( is_array( $user_levels ) && count( $user_levels ) && isset( $user_levels[ $key ] ) && $user_levels[ $key ]->id === $level_id ) {
                        $user_has_level = $user_levels[ $key ];
                    }
                }
            }

            if ( ! is_null( $user_has_level ) && is_object( $user_has_level ) ) {
                if ( $user_has_level->expiration_number ) {
                    $validity = $user_has_level->expiration_number . ' ' . $user_has_level->expiration_period;
                } else {
                    $validity = $user_has_level->cycle_number . ' ' . $user_has_level->cycle_period;
                }
            }

            /**
			 * If user don't have category wise membership then
			 * look into full-site membership
			 */
            if ( is_array( $user_levels ) && is_null( $user_has_level ) ) {
                $level = isset( $user_levels[0] ) ? $user_levels[0] : null;
                if ( is_object( $level ) ) {
                    if ( isset( $level->expiration_period ) && $level->expiration_period ) {
                        $validity = $level->expiration_number . ' ' . $level->expiration_period;
                    } else {
                        $validity = $level->cycle_number . ' ' . $level->cycle_period;
                    }
                }
            }
        }

        // If membership has no validity then set lifetime.
        if ( 0 == $validity || '' === $validity ) {
            $validity = __( 'Lifetime', 'tutorpress-pmpro' );
        }
        return $validity;
    }

    /**
	 * Get terms ids by course id
	 *
	 * @since 2.1.4
	 *
	 * @param int $course_id course id.
	 *
	 * @return array
	 */
    public function get_term_ids( $course_id ) {
        $terms    = get_the_terms( $course_id, 'course-category' );
        $term_ids = array_map(
            function( $term ) {
                return $term->term_id;
            },
            ( is_array( $terms ) ? $terms : array() )
        );
        return $term_ids;
    }

	/**
	 * Map PMPro membership level IDs to Tutor LMS course IDs.
	 * 
	 * Checks multiple sources:
	 * 1. pmpro_memberships_pages table (primary)
	 * 2. tutorpress_course_id level meta (reverse lookup for course-specific levels)
	 *
	 * @param array|int $level_ids
	 * @return array<int> course IDs
	 */
	private function get_courses_for_levels( $level_ids ) {
		global $wpdb;

		if ( is_object( $level_ids ) ) {
			$level_ids = $level_ids->ID;
		}

		if ( ! is_array( $level_ids ) ) {
			$level_ids = array( $level_ids );
		}

		$level_ids = array_values( array_filter( array_map( 'absint', $level_ids ) ) );
		if ( empty( $level_ids ) ) {
			return array();
		}

		$course_ids = array();

		// Primary: pmpro_memberships_pages table
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$post_type   = tutor()->course_post_type;
			$placeholders = implode( ', ', array_fill( 0, count( $level_ids ), '%d' ) );
			$sql = "
				SELECT mp.page_id
				FROM {$wpdb->pmpro_memberships_pages} mp
				LEFT JOIN {$wpdb->posts} p ON mp.page_id = p.ID
				WHERE mp.membership_id IN ( {$placeholders} )
				AND p.post_type = %s
				AND p.post_status = 'publish'
				GROUP BY mp.page_id
			";
			$params = array_merge( array( $sql ), $level_ids, array( $post_type ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic placeholders handled via call_user_func_array
			$course_ids = $wpdb->get_col( call_user_func_array( array( $wpdb, 'prepare' ), $params ) );
			$course_ids = is_array( $course_ids ) ? array_map( 'intval', $course_ids ) : array();
		}

		// Secondary: Reverse meta lookup (tutorpress_course_id) for course-specific levels
		if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $level_ids ), '%d' ) );
			$sql = "
				SELECT DISTINCT CAST(meta_value AS UNSIGNED) as course_id
				FROM {$wpdb->pmpro_membership_levelmeta}
				WHERE meta_key = 'tutorpress_course_id'
				AND pmpro_membership_level_id IN ( {$placeholders} )
				AND CAST(meta_value AS UNSIGNED) > 0
			";
			$params = array_merge( array( $sql ), $level_ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic placeholders handled via call_user_func_array
			$reverse_course_ids = $wpdb->get_col( call_user_func_array( array( $wpdb, 'prepare' ), $params ) );
			if ( is_array( $reverse_course_ids ) && ! empty( $reverse_course_ids ) ) {
				// Verify these are valid published courses
				$reverse_course_ids = array_map( 'intval', $reverse_course_ids );
				$valid_courses = get_posts( array(
					'post_type'   => tutor()->course_post_type,
					'post_status' => 'publish',
					'post__in'    => $reverse_course_ids,
					'fields'      => 'ids',
					'posts_per_page' => -1,
				) );
				$course_ids = array_merge( $course_ids, $valid_courses );
			}
		}

		// Remove duplicates and return
		$course_ids = array_values( array_unique( array_map( 'intval', $course_ids ) ) );

		return $course_ids;
	}

	/**
	 * Handle PMPro order refunds → unenroll if the refunded level is the only access path.
	 *
	 * @param object $order      PMPro MemberOrder instance
	 * @param string $old_status Previous status
	 * @return void
	 */
	public function pmpro_order_status_refunded( $order, $old_status ) {
		if ( ! function_exists( 'tutor_utils' ) || ! is_object( $order ) ) {
			return;
		}

		$user_id      = isset( $order->user_id ) ? (int) $order->user_id : 0;
		$level_id     = isset( $order->membership_id ) ? (int) $order->membership_id : 0;
		if ( ! $user_id || ! $level_id ) {
			return;
		}

		// Courses tied to the refunded level
		$refunded_courses = $this->get_courses_for_levels( array( $level_id ) );

		// Other active levels for this user (excluding refunded level)
		$current_levels    = pmpro_getMembershipLevelsForUser( $user_id );
		$current_level_ids = ! empty( $current_levels ) ? wp_list_pluck( $current_levels, 'ID' ) : array();
		$other_level_ids   = array_values( array_diff( array_map( 'intval', $current_level_ids ), array( $level_id ) ) );
		$other_courses     = $this->get_courses_for_levels( $other_level_ids );

		// Skip public/free courses
		$refunded_courses = array_values( array_filter( $refunded_courses, function ( $cid ) {
			return get_post_meta( $cid, '_tutor_is_public_course', true ) !== 'yes' && get_post_meta( $cid, '_tutor_course_price_type', true ) !== 'free';
		} ) );

		foreach ( $refunded_courses as $course_id ) {
			// If no other course access remains, unenroll
			if ( ! in_array( $course_id, $other_courses, true ) ) {
				if ( tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
					tutor_utils()->cancel_course_enrol( $course_id, $user_id );
				}
			}
		}
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
	 * Enroll user immediately after successful PMPro checkout.
	 *
	 * @param int             $user_id User ID.
	 * @param \MemberOrder|null $morder  Order object (may be null in some flows).
	 * @return void
	 */
	public function pmpro_after_checkout_enroll( $user_id, $morder ) {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return;
		}

		// Build a set of relevant level IDs: current active levels plus the order's level id.
		$current_levels    = pmpro_getMembershipLevelsForUser( $user_id );
		$current_level_ids = ! empty( $current_levels ) ? wp_list_pluck( $current_levels, 'ID' ) : array();
		$order_level_id    = ( is_object( $morder ) && ! empty( $morder->membership_id ) ) ? (int) $morder->membership_id : 0;
		$level_ids         = $current_level_ids;
		if ( $order_level_id && ! in_array( $order_level_id, $level_ids, true ) ) {
			$level_ids[] = $order_level_id;
		}

		if ( empty( $level_ids ) ) {
			return;
		}

		$courses = $this->get_courses_for_levels( $level_ids );
		// Filter out public/free courses
		$courses = array_values( array_filter( $courses, function ( $cid ) {
			return get_post_meta( $cid, '_tutor_is_public_course', true ) !== 'yes' && get_post_meta( $cid, '_tutor_course_price_type', true ) !== 'free';
		} ) );

		foreach ( $courses as $course_id ) {
			if ( ! tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
				$enrolled_id = tutor_utils()->do_enroll( $course_id, 0, $user_id );
				if ( $enrolled_id ) {
					// Mark as completed so UI shows Start/Continue Learning.
					tutor_utils()->course_enrol_status_change( $enrolled_id, 'completed' );
					// Link enrollment to PMPro order for traceability (mirrors Woo/EDD linkage in Tutor).
					if ( is_object( $morder ) ) {
						if ( isset( $morder->id ) && $morder->id ) {
							update_post_meta( $enrolled_id, '_tutor_enrolled_by_order_id', (int) $morder->id );
						}
						if ( isset( $morder->code ) && $morder->code ) {
							update_post_meta( $enrolled_id, '_tutor_pmpro_order_code', sanitize_text_field( $morder->code ) );
						}
						if ( isset( $morder->membership_id ) && $morder->membership_id ) {
							update_post_meta( $enrolled_id, '_tutor_pmpro_level_id', (int) $morder->membership_id );
						}
					}
				}
			}
		}
	}

	/**
	 * Handle immediate enrollment sync when a single membership level changes.
	 * This fires immediately when levels are changed (e.g., admin assignment).
	 *
	 * @param int $level_id ID of the level changed to (0 if cancelled).
	 * @param int $user_id ID of the user changed.
	 * @param int $cancel_level ID of the level being cancelled if specified.
	 * @return void
	 */
	public function pmpro_after_change_membership_level( $level_id, $user_id, $cancel_level ) {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return;
		}

		// Get current levels for the user
		$current_levels = pmpro_getMembershipLevelsForUser( $user_id );
		$current_level_ids = ! empty( $current_levels ) ? wp_list_pluck( $current_levels, 'ID' ) : array();

		// Get courses for current levels
		$current_courses = $this->get_courses_for_levels( $current_level_ids );

		// Filter out public/free courses
		$current_courses = array_values( array_filter( $current_courses, function ( $cid ) {
			return get_post_meta( $cid, '_tutor_is_public_course', true ) !== 'yes' && get_post_meta( $cid, '_tutor_course_price_type', true ) !== 'free';
		} ) );

		// If level was cancelled (level_id = 0), unenroll from courses that required the cancelled level
		if ( $level_id == 0 && $cancel_level ) {
			$cancelled_courses = $this->get_courses_for_levels( array( $cancel_level ) );
			$cancelled_courses = array_values( array_filter( $cancelled_courses, function ( $cid ) {
				return get_post_meta( $cid, '_tutor_is_public_course', true ) !== 'yes' && get_post_meta( $cid, '_tutor_course_price_type', true ) !== 'free';
			} ) );

			// Only unenroll if user no longer has access via other levels
			$courses_to_unenroll = array_diff( $cancelled_courses, $current_courses );
			foreach ( $courses_to_unenroll as $course_id ) {
				if ( tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
					tutor_utils()->cancel_course_enrol( $course_id, $user_id );
				}
			}
		}

		// If level was added, enroll in new courses
		if ( $level_id > 0 ) {
			$new_courses = $this->get_courses_for_levels( array( $level_id ) );
			$new_courses = array_values( array_filter( $new_courses, function ( $cid ) {
				return get_post_meta( $cid, '_tutor_is_public_course', true ) !== 'yes' && get_post_meta( $cid, '_tutor_course_price_type', true ) !== 'free';
			} ) );

			foreach ( $new_courses as $course_id ) {
				if ( ! tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
					$enrolled_id = tutor_utils()->do_enroll( $course_id, 0, $user_id );
					if ( $enrolled_id ) {
						tutor_utils()->course_enrol_status_change( $enrolled_id, 'completed' );
					}
				}
			}
		}
	}

	/**
	 * When users change PMPro levels, enroll/unenroll them in mapped Tutor courses (non-public only).
	 * Mirrors logic from PMPro Courses addon for Tutor LMS.
	 *
	 * @param array $pmpro_old_user_levels Map of user_id => array of old level objects
	 * @return void
	 */
	public function pmpro_after_all_membership_level_changes( $pmpro_old_user_levels ) {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return;
		}

		if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
			error_log( '[TP-PMPRO] pmpro_after_all_membership_level_changes fired for ' . count( $pmpro_old_user_levels ) . ' user(s)' );
		}

		foreach ( $pmpro_old_user_levels as $user_id => $old_levels ) {
			// Current level IDs for user
			$current_levels = pmpro_getMembershipLevelsForUser( $user_id );
			$current_level_ids = ! empty( $current_levels ) ? wp_list_pluck( $current_levels, 'ID' ) : array();

			// Old level IDs
			$old_level_ids = ! empty( $old_levels ) ? wp_list_pluck( $old_levels, 'ID' ) : array();

			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( sprintf(
					'[TP-PMPRO] User %d: old_levels=%s, current_levels=%s',
					$user_id,
					implode( ',', $old_level_ids ),
					implode( ',', $current_level_ids )
				) );
			}

			$current_courses = $this->get_courses_for_levels( $current_level_ids );
			$old_courses     = $this->get_courses_for_levels( $old_level_ids );

			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( sprintf(
					'[TP-PMPRO] User %d: old_courses=%s, current_courses=%s',
					$user_id,
					implode( ',', $old_courses ),
					implode( ',', $current_courses )
				) );
			}

			// Filter out public/free courses
			$current_courses = array_values( array_filter( $current_courses, function ( $cid ) {
				return get_post_meta( $cid, '_tutor_is_public_course', true ) !== 'yes' && get_post_meta( $cid, '_tutor_course_price_type', true ) !== 'free';
			} ) );
			$old_courses = array_values( array_filter( $old_courses, function ( $cid ) {
				return get_post_meta( $cid, '_tutor_is_public_course', true ) !== 'yes' && get_post_meta( $cid, '_tutor_course_price_type', true ) !== 'free';
			} ) );

			// Compute diffs
			$courses_to_unenroll = array_diff( $old_courses, $current_courses );
			$courses_to_enroll   = array_diff( $current_courses, $old_courses );

			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				if ( ! empty( $courses_to_unenroll ) ) {
					error_log( sprintf(
						'[TP-PMPRO] User %d: courses_to_unenroll=%s',
						$user_id,
						implode( ',', $courses_to_unenroll )
					) );
				}
				if ( ! empty( $courses_to_enroll ) ) {
					error_log( sprintf(
						'[TP-PMPRO] User %d: courses_to_enroll=%s',
						$user_id,
						implode( ',', $courses_to_enroll )
					) );
				}
			}

			// Unenroll
			foreach ( $courses_to_unenroll as $course_id ) {
				if ( tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
					tutor_utils()->cancel_course_enrol( $course_id, $user_id );
					if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
						error_log( sprintf(
							'[TP-PMPRO] Unenrolled user %d from course %d (membership level removed)',
							$user_id,
							$course_id
						) );
					}
				}
			}

			// Enroll
			foreach ( $courses_to_enroll as $course_id ) {
				if ( ! tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
					$enrolled_id = tutor_utils()->do_enroll( $course_id, 0, $user_id );
					if ( $enrolled_id ) {
						tutor_utils()->course_enrol_status_change( $enrolled_id, 'completed' );
					}
				}
			}
		}
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
     * Show PMPro membership plans in entry box when appropriate.
     * 
     * Override entry box to show membership plans when:
     * - Membership-only mode is enabled, OR
     * - Course selling option is not one-time purchase only
     *
     * @since 1.4.0
     * @param string $html Original HTML content.
     * @param int    $course_id Course ID.
     * @return string Modified HTML or original HTML.
     */
    /**
	 * [REMOVED - Phase 8, Step 8.1: Substep 3]
	 * show_pmpro_membership_plans() → Moved to Pricing_Display::show_pmpro_membership_plans()
	 */

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

    /**
     * Check if user is enrolled in a course via PMPro membership (not individual purchase).
     * 
     * Helper to determine if enrollment was via membership plan.
     * This helps preserve individual purchase enrollments in membership-only mode.
     *
     * @since 1.4.0
     * @param int $course_id Course ID.
     * @param int|null $user_id User ID (defaults to current user).
     * @return bool True if enrolled via PMPro membership, false otherwise.
     */
    public function is_enrolled_by_pmpro_membership( $course_id, $user_id = null ) {
        if ( ! $user_id ) {
            $user_id = get_current_user_id();
        }

        if ( ! $user_id || ! tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
            return false;
        }

        // Check if user has any active PMPro membership levels
        if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
            return false;
        }

        // If user has any membership level, consider them enrolled via membership
        // This is a simplified check; could be enhanced to verify specific level access
        return pmpro_hasMembershipLevel();
    }

    /**
     * Render the "View Pricing" button for membership-only mode and membership selling option in course loops.
     * 
     * Displays a button linking to the individual course page where full pricing details are displayed.
     *
     * @since 1.4.0
     * @param int $course_id Course ID to link to.
     * @return string HTML for the pricing button.
     */
    /**
     * [REMOVED - Phase 8, Step 8.1: Substep 1]
     * render_membership_price_button() → Moved to Pricing_Display::render_membership_price_button()
     */

    /**
     * [REMOVED - Phase 8, Step 8.1: Substep 3]
     * filter_allow_guest_attempt_enrollment() → Moved to Pricing_Display::filter_allow_guest_attempt_enrollment()
     */

    /**
     * [REMOVED - Phase 8, Step 8.1: Substep 3]
     * handle_after_enrollment_completed() → Moved to Pricing_Display::handle_after_enrollment_completed()
     * handle_bundle_course_membership_enrollment() → Moved to Pricing_Display::handle_bundle_course_membership_enrollment()
     */

    /**
     * Get the active price for a PMPro level, accounting for sale schedule.
     *
     * Reads sale meta and calculates in real-time whether sale is active.
     * Returns array with active price, regular price, and sale status.
     *
     * @since 1.5.0
     * @param int $level_id PMPro level ID
     * @return array {
     *     Active price data
     *
     *     @type float      $price         Active price (sale or regular)
     *     @type float|null $regular_price Regular price (if on sale) or null
     *     @type bool       $on_sale       Whether sale is currently active
     * }
     */
    public function get_active_price_for_level( $level_id ) {
        global $wpdb;

        // Get level data
        $level = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->pmpro_membership_levels} WHERE id = %d",
            $level_id
        ) );

        if ( ! $level ) {
            return array(
                'price'         => 0.0,
                'regular_price' => null,
                'on_sale'       => false,
            );
        }

        // Get sale meta (stored by REST controller and reconciliation logic)
        $sale_price = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price', true );
        $regular_price_meta = get_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', true );

        // Determine regular price (priority: regular_price_meta > level.initial_payment)
        $regular = ! empty( $regular_price_meta )
            ? floatval( $regular_price_meta )
            : floatval( $level->initial_payment );

        // Check if sale is active (uses helper method below)
        if ( $this->is_sale_active( $level_id, $sale_price, $regular ) ) {
            return array(
                'price'         => floatval( $sale_price ),
                'regular_price' => $regular,
                'on_sale'       => true,
            );
        }

        // No active sale
        return array(
            'price'         => $regular,
            'regular_price' => null,
            'on_sale'       => false,
        );
    }

    /**
     * Check if a sale is currently active for a level.
     *
     * Validates sale price and checks if current time is within sale schedule.
     * Uses Tutor LMS DateTimeHelper for timezone consistency.
     *
     * @since 1.5.0
     * @param int   $level_id      PMPro level ID
     * @param mixed $sale_price    Sale price from meta
     * @param float $regular_price Regular price for comparison
     * @return bool True if sale is active, false otherwise
     */
    private function is_sale_active( $level_id, $sale_price, $regular_price ) {
        // Validate sale price exists and is less than regular
        if ( empty( $sale_price ) || floatval( $sale_price ) <= 0 || floatval( $sale_price ) >= $regular_price ) {
            return false;
        }

        // Get sale schedule
        $sale_from = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_from', true );
        $sale_to = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_to', true );

        // If no schedule, sale is always active (open-ended sale)
        if ( empty( $sale_from ) || empty( $sale_to ) ) {
            return true;
        }

        // Check date range using Tutor LMS timezone helper (aligns with TutorPress)
        if ( class_exists( '\Tutor\Helpers\DateTimeHelper' ) ) {
            $now = \Tutor\Helpers\DateTimeHelper::now()->format( 'U' );
            $from_timestamp = \Tutor\Helpers\DateTimeHelper::create( $sale_from )->format( 'U' );
            $to_timestamp = \Tutor\Helpers\DateTimeHelper::create( $sale_to )->format( 'U' );
        } else {
            // Fallback to WordPress core (GMT/UTC)
            $now = current_time( 'timestamp', true );
            $from_timestamp = strtotime( $sale_from );
            $to_timestamp = strtotime( $sale_to );
        }

        return ( $now >= $from_timestamp && $now <= $to_timestamp );
    }

    /**
     * Filter PMPro checkout level to apply sale price at checkout time.
     *
     * This ensures customers are charged the correct price based on
     * real-time sale schedule, regardless of database initial_payment value.
     *
     * @since 1.5.0
     * @param object $level PMPro level object
     * @return object Modified level object
     */
    public function filter_checkout_level_sale_price( $level ) {
        if ( empty( $level->id ) ) {
            return $level;
        }

        $active_price = $this->get_active_price_for_level( $level->id );

        if ( $active_price['on_sale'] ) {
            $level->initial_payment = $active_price['price'];

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[TP-PMPRO] checkout_sale_applied level_id=%d sale_price=%s regular_price=%s',
                    $level->id,
                    $active_price['price'],
                    $active_price['regular_price']
                ) );
            }
        }

        return $level;
    }

    /**
     * Filter PMPro level cost text to show sale price with strikethrough.
     *
     * Applies to PMPro's membership levels page, account page, and other
     * locations where PMPro renders pricing.
     *
     * IMPORTANT: Sale only applies to initial payment, never to recurring price.
     * This filter runs at priority 999 to ensure it runs AFTER checkout_level filter.
     *
     * @since 1.5.0
     * @param string $text  Cost text HTML
     * @param object $level PMPro level object
     * @param array  $tags  Template tags
     * @param bool   $short Whether to show short version
     * @return string Modified cost text HTML
     */
    public function filter_level_cost_text_sale_price( $text, $level, $tags, $short ) {
        if ( empty( $level->id ) ) {
            return $text;
        }

        $active_price = $this->get_active_price_for_level( $level->id );

        if ( ! $active_price['on_sale'] || ! function_exists( 'pmpro_formatPrice' ) ) {
            return $text;
        }

        $sale_formatted = pmpro_formatPrice( $active_price['price'] );
        $regular_formatted = pmpro_formatPrice( $active_price['regular_price'] );

        // Check if this is a subscription (has recurring payments)
        $is_subscription = ! empty( $level->billing_amount ) && ! empty( $level->cycle_number );

        if ( $is_subscription ) {
            // For subscriptions: Find where the sale price appears and add strikethrough regular price before it
            // Two patterns:
            // 1. Full format: "$20.00 now" → "~~$30.00~~ $20.00 now"
            // 2. Short format: "$20.00 per Month" → "~~$30.00~~ $20.00 now and then $30.00 per Month"
            
            // Try full format first (with "now")
            $pattern_full = '/' . preg_quote( $sale_formatted, '/' ) . '(\s+now)/i';
            if ( preg_match( $pattern_full, $text ) ) {
                $replacement = '<span style="text-decoration: line-through; opacity: 0.6;">' . $regular_formatted . '</span> ' . $sale_formatted . '$1';
                $text = preg_replace( $pattern_full, $replacement, $text, 1 );
            } else {
                // Short format - reconstruct the full text
                // PMPro might show just "$20 per Month" (the recurring), but we need to show initial + recurring
                $recurring_formatted = function_exists( 'pmpro_formatPrice' ) ? pmpro_formatPrice( $level->billing_amount ) : '';
                $cycle_text = '';
                
                if ( ! empty( $level->cycle_number ) && ! empty( $level->cycle_period ) ) {
                    $cycle_text = ' per ' . $level->cycle_period;
                }
                
                // Build: "~~$30~~ $20 now and then $30 per Month"
                $text = '<span style="text-decoration: line-through; opacity: 0.6;">' . $regular_formatted . '</span> ' . 
                        $sale_formatted . ' now and then ' . $recurring_formatted . $cycle_text . '.';
            }
        } else {
            // For one-time purchases: Replace regular price with strikethrough regular + sale
            // PMPro shows the regular price from DB, we need to show it struck through + sale price
            $text = str_replace(
                $regular_formatted,
                '<span style="text-decoration: line-through; opacity: 0.6;">' . $regular_formatted . '</span> ' . $sale_formatted,
                $text
            );
        }

        return $text;
    }

    /**
     * Filter PMPro email data to apply sale price in confirmation emails.
     *
     * @since 1.5.0
     * @param array  $data  Email template data
     * @param object $email PMPro email object
     * @return array Modified email data
     */
    public function filter_email_data_sale_price( $data, $email ) {
        // PMPro emails include membership level data
        if ( ! empty( $data['membership_level'] ) && ! empty( $data['membership_level']->id ) ) {
            $level_id = $data['membership_level']->id;
            $active_price = $this->get_active_price_for_level( $level_id );

            if ( $active_price['on_sale'] ) {
                $data['membership_level']->initial_payment = $active_price['price'];

                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        '[TP-PMPRO] email_sale_applied level_id=%d sale_price=%s',
                        $level_id,
                        $active_price['price']
                    ) );
                }
            }
        }

        return $data;
    }

    /**
     * Add promotional price note to PMPro invoices when sale is active.
     *
     * @since 1.5.0
     * @param object $order PMPro order object
     * @return void
     */
    public function filter_invoice_sale_note( $order ) {
        if ( empty( $order->membership_id ) ) {
            return;
        }

        $active_price = $this->get_active_price_for_level( $order->membership_id );

        if ( $active_price['on_sale'] && function_exists( 'pmpro_formatPrice' ) ) {
            $regular_formatted = pmpro_formatPrice( $active_price['regular_price'] );
            echo '<li><strong>' . esc_html__( 'Promotional Price Applied', 'tutorpress-pmpro' ) . '</strong> (' . esc_html__( 'Regular', 'tutorpress-pmpro' ) . ': ' . esc_html( $regular_formatted ) . ')</li>';
        }
    }

    /**
     * Display course association notice on PMPro level edit page.
     *
     * Shows at the top of General Information section for all TutorPress-managed levels.
     *
     * @since 1.5.0
     * @return void
     */
    public function display_course_association_notice() {
        // Only run on level edit page
        if ( ! \TUTOR\Input::has( 'edit' ) ) {
            return;
        }

        $level_id = intval( \TUTOR\Input::sanitize_request_data( 'edit' ) );
        if ( $level_id <= 0 ) {
            return;
        }

        // Check if this level is associated with a TutorPress course
        $course_id = get_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', true );

        if ( empty( $course_id ) ) {
            return; // Not a TutorPress-managed level
        }

        $course_title = get_the_title( $course_id );
        $course_edit_url = admin_url( 'post.php?post=' . $course_id . '&action=edit' );
        
        ?>
        <tr>
            <td colspan="2">
                <div class="pmpro_course_association_notice" style="background: #f7f7f7; border-left: 4px solid #72aee6; padding: 12px 15px; margin: 10px 0;">
                    <p style="margin: 0; font-size: 13px; color: #333;">
                        <span class="dashicons dashicons-welcome-learn-more" style="font-size: 16px; vertical-align: middle; color: #72aee6;"></span>
                        <strong><?php esc_html_e( 'TutorPress Course Level', 'tutorpress-pmpro' ); ?></strong>
                        <br>
                        <span style="margin-left: 22px; color: #666;">
                            <?php 
                            printf(
                                __( 'This membership level is managed by TutorPress for: %s', 'tutorpress-pmpro' ),
                                '<a href="' . esc_url( $course_edit_url ) . '" target="_blank" style="text-decoration: none;">' . esc_html( $course_title ) . ' <span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span></a>'
                            );
                            ?>
                        </span>
                    </p>
                </div>
            </td>
        </tr>
        <?php
    }

    /**
     * Display sale price notice on PMPro level edit page.
     *
     * Shows in Billing Details section when a sale exists (active or scheduled),
     * including sale price details and schedule.
     *
     * @since 1.5.0
     * @return void
     */
    public function display_sale_price_notice_on_level_edit() {
        // Only run on level edit page
        if ( ! \TUTOR\Input::has( 'edit' ) ) {
            return;
        }

        $level_id = intval( \TUTOR\Input::sanitize_request_data( 'edit' ) );
        if ( $level_id <= 0 ) {
            return;
        }

        // Check if this level is associated with a TutorPress course
        $course_id = get_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', true );

        if ( empty( $course_id ) ) {
            return; // Not a TutorPress-managed level
        }

        // Check if sale price exists in meta
        $sale_price = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price', true );
        $regular_price_meta = get_pmpro_membership_level_meta( $level_id, 'tutorpress_regular_price', true );
        
        if ( empty( $sale_price ) || empty( $regular_price_meta ) || floatval( $regular_price_meta ) <= 0 ) {
            return; // No sale configured
        }
        
        $regular_price = floatval( $regular_price_meta );

        // Get sale schedule
        $sale_from = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_from', true );
        $sale_to = get_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_to', true );

        // Determine sale status (active, scheduled, or expired)
        $sale_status = 'active'; // Default for open-ended sales
        $schedule_text = '';
        
        if ( ! empty( $sale_from ) && ! empty( $sale_to ) ) {
            // Get current time in GMT (matching storage format)
            if ( class_exists( '\Tutor\Helpers\DateTimeHelper' ) ) {
                $now = \Tutor\Helpers\DateTimeHelper::now()->format( 'U' );
                $from_timestamp = \Tutor\Helpers\DateTimeHelper::create( $sale_from )->format( 'U' );
                $to_timestamp = \Tutor\Helpers\DateTimeHelper::create( $sale_to )->format( 'U' );
            } else {
                $now = current_time( 'timestamp', true );
                $from_timestamp = strtotime( $sale_from );
                $to_timestamp = strtotime( $sale_to );
            }

            // Determine status
            if ( $now < $from_timestamp ) {
                $sale_status = 'scheduled';
            } elseif ( $now > $to_timestamp ) {
                // Sale expired - don't show notice
                return;
            } else {
                $sale_status = 'active';
            }

            // Format dates for display in local timezone
            if ( class_exists( '\Tutor\Helpers\DateTimeHelper' ) && method_exists( '\Tutor\Helpers\DateTimeHelper', 'get_gmt_to_user_timezone_date' ) ) {
                $from_formatted = \Tutor\Helpers\DateTimeHelper::get_gmt_to_user_timezone_date( $sale_from, 'M j, Y g:i A' );
                $to_formatted = \Tutor\Helpers\DateTimeHelper::get_gmt_to_user_timezone_date( $sale_to, 'M j, Y g:i A' );
            } else {
                // Fallback: Display as-is
                $from_formatted = date( 'M j, Y g:i A', $from_timestamp );
                $to_formatted = date( 'M j, Y g:i A', $to_timestamp );
            }

            if ( $sale_status === 'scheduled' ) {
                $schedule_text = sprintf( 
                    __( 'Scheduled: %s to %s', 'tutorpress-pmpro' ), 
                    $from_formatted, 
                    $to_formatted 
                );
            } else {
                $schedule_text = sprintf( 
                    __( 'Active from %s to %s', 'tutorpress-pmpro' ), 
                    $from_formatted, 
                    $to_formatted 
                );
            }
        } else {
            $schedule_text = __( 'Open-ended (no expiration)', 'tutorpress-pmpro' );
        }

        $sale_price_formatted = function_exists( 'pmpro_formatPrice' ) ? pmpro_formatPrice( floatval( $sale_price ) ) : '$' . number_format( floatval( $sale_price ), 2 );
        $regular_price_formatted = function_exists( 'pmpro_formatPrice' ) ? pmpro_formatPrice( $regular_price ) : '$' . number_format( $regular_price, 2 );

        $course_title = get_the_title( $course_id );
        $course_edit_url = admin_url( 'post.php?post=' . $course_id . '&action=edit' );
        
        // Different styling for scheduled vs active sales
        $bg_color = ( $sale_status === 'scheduled' ) ? '#fff3cd' : '#d7f1ff';
        $border_color = ( $sale_status === 'scheduled' ) ? '#ffc107' : '#0073aa';
        $heading_color = ( $sale_status === 'scheduled' ) ? '#856404' : '#0073aa';
        $heading_text = ( $sale_status === 'scheduled' ) ? __( 'Scheduled Sale', 'tutorpress-pmpro' ) : __( 'Sale Price Active', 'tutorpress-pmpro' );
        $icon = ( $sale_status === 'scheduled' ) ? 'clock' : 'tag';
        
        ?>
        <div class="pmpro_sale_price_notice" style="background: <?php echo esc_attr( $bg_color ); ?>; border-left: 4px solid <?php echo esc_attr( $border_color ); ?>; padding: 12px 15px; margin-top: 20px;">
            <h3 style="margin-top: 0; color: <?php echo esc_attr( $heading_color ); ?>;">
                <span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" style="font-size: 20px; vertical-align: middle;"></span>
                <?php echo esc_html( $heading_text ); ?>
            </h3>
            <p style="margin: 8px 0;">
                <strong><?php esc_html_e( 'Regular Price:', 'tutorpress-pmpro' ); ?></strong> 
                <span style="text-decoration: line-through; opacity: 0.6;"><?php echo esc_html( $regular_price_formatted ); ?></span>
                &nbsp;&nbsp;
                <strong><?php esc_html_e( 'Sale Price:', 'tutorpress-pmpro' ); ?></strong> 
                <span style="color: <?php echo esc_attr( $heading_color ); ?>; font-weight: bold;"><?php echo esc_html( $sale_price_formatted ); ?></span>
            </p>
            <?php if ( $schedule_text ) : ?>
                <p style="margin: 8px 0;">
                    <strong><?php esc_html_e( 'Schedule:', 'tutorpress-pmpro' ); ?></strong> 
                    <?php echo esc_html( $schedule_text ); ?>
                </p>
            <?php endif; ?>
            <p style="margin: 8px 0; font-style: italic; color: #666;">
                <?php 
                printf(
                    __( 'This sale is managed in the associated course: %s', 'tutorpress-pmpro' ),
                    '<a href="' . esc_url( $course_edit_url ) . '" target="_blank">' . esc_html( $course_title ) . '</a>'
                );
                ?>
            </p>
            <p style="margin: 8px 0 0 0; font-size: 12px; color: #666;">
                <strong><?php esc_html_e( 'Note:', 'tutorpress-pmpro' ); ?></strong>
                <?php 
                if ( $sale_status === 'scheduled' ) {
                    esc_html_e( 'The sale price will automatically apply when the scheduled time begins. Until then, customers will be charged the regular price.', 'tutorpress-pmpro' );
                } else {
                    esc_html_e( 'The Initial Payment field above shows the regular price. Customers will automatically be charged the sale price at checkout.', 'tutorpress-pmpro' );
                }
                ?>
            </p>
        </div>
        <?php
    }
}



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
	 * Register hooks
	 */
    public function __construct() {
        // Register frontend pricing hooks late in page lifecycle
        add_action( 'wp', array( $this, 'init_pmpro_price_overrides' ), 20 );
        add_action( 'pmpro_membership_level_after_other_settings', array( $this, 'display_courses_categories' ) );
        add_action( 'pmpro_save_membership_level', array( $this, 'pmpro_settings' ) );
        add_filter( 'tutor_course/single/add-to-cart', array( $this, 'tutor_course_add_to_cart' ) );
        add_filter( 'tutor_course_price', array( $this, 'tutor_course_price' ) );
        add_filter( 'tutor-loop-default-price', array( $this, 'add_membership_required' ) );

        add_filter( 'tutor/course/single/entry-box/free', array( $this, 'pmpro_pricing' ), 10, 2 );
        add_filter( 'tutor/course/single/entry-box/is_enrolled', array( $this, 'pmpro_pricing' ), 10, 2 );
        add_filter( 'tutor/course/single/entry-box/purchasable', array( $this, 'show_pmpro_membership_plans' ), 12, 2 );
        add_action( 'tutor/course/single/content/before/all', array( $this, 'pmpro_pricing_single_course' ), 100, 2 );
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

			// Frontend behavior overrides
			add_filter( 'tutor_allow_guest_attempt_enrollment', array( $this, 'filter_allow_guest_attempt_enrollment' ), 11, 3 );
			add_action( 'tutor_after_enrolled', array( $this, 'handle_after_enrollment_completed' ), 10, 3 );
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

        // Price string injection for archive/dashboard/single contexts
        add_filter( 'get_tutor_course_price', array( $this, 'filter_get_tutor_course_price' ), 12, 2 );

        // Directly override loop price block for archive/dashboard contexts (revised Step B)
        add_filter( 'tutor_course_loop_price', array( $this, 'filter_course_loop_price_pmpro' ), 12, 2 );

        // Ensure loop uses Tutor monetization wrappers so our string renders inside native markup
        add_filter( 'tutor_course_sell_by', array( $this, 'filter_course_sell_by' ), 9, 1 );

    }

    /**
     * Return PMPro minimal price string when available.
     * Falls back to original price if engine/levels are not applicable.
     *
     * @param mixed $price
     * @param int   $course_id
     * @return mixed
     */
    public function filter_get_tutor_course_price( $price, $course_id = 0 ) {
        $course_id = $course_id ? (int) $course_id : (int) get_the_ID();
        if ( ! $course_id ) {
            return $price;
        }
        if ( ! $this->is_pmpro_enabled() ) {
            return $price;
        }

        if ( class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Pricing' ) ) {
            $pmpro_price = \TUTORPRESS_PMPRO\PMPro_Pricing::get_formatted_price( $course_id );
            if ( is_string( $pmpro_price ) && $pmpro_price !== '' ) {
                return $pmpro_price;
            }
        }

        return $price;
    }

    /**
     * Ensure Tutor uses the 'tutor' price template wrappers in loops
     * when the course has PMPro levels so our price string displays.
     *
     * @param string|null $sell_by
     * @return string|null
     */
    public function filter_course_sell_by( $sell_by ) {
        if ( ! $this->is_pmpro_enabled() ) {
            return $sell_by;
        }
        // For PMPro engine, use Tutor monetization wrappers in loops so
        // get_tutor_course_price can render the PMPro price string.
        // This keeps markup consistent regardless of mapping state.
        return 'tutor';
    }

    /**
     * Replace the loop price block with PMPro minimal pricing when applicable.
     * 
     * When membership-only mode is enabled, show "View Pricing"
     * button instead of normal price for users without access.
     *
     * @param string $html
     * @param int    $course_id
     * @return string
     */
    public function filter_course_loop_price_pmpro( $html, $course_id ) {
        if ( ! $this->is_pmpro_enabled() ) {
            return $html;
        }

        $course_id = (int) $course_id ?: (int) get_the_ID();
        if ( ! $course_id ) {
            return $html;
        }

        // Phase 2, Step 6: Membership-only mode loop price override
        if ( self::tutorpress_pmpro_membership_only_enabled() ) {
            // Respect public courses
            if ( \TUTOR\Course_List::is_public( $course_id ) ) {
                return $html;
            }

            // Respect free courses
            if ( get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free' ) {
                return $html;
            }

            // Keep enrollment display if user purchased this course individually (not via membership)
            if ( tutor_utils()->is_enrolled( $course_id ) && ! $this->is_enrolled_by_pmpro_membership( $course_id ) ) {
                return $html;
            }

            // For all other cases in membership-only mode, show "View Pricing" button
            // This includes: logged-out users, users without membership, users without access
            // has_course_access() returns:
            // - true if user has access
            // - false if no membership required (shouldn't happen in membership-only mode)
            // - array of required levels if user doesn't have access
            $user_has_access = $this->has_course_access( $course_id );
            
            // Only show original HTML if user truly has access (returns exactly true)
            if ( $user_has_access === true ) {
                return $html;
            }
            
            // For all other cases (false, array, etc.), show "View Pricing" button
            return $this->render_membership_price_button();
        }

        // Below this point: Hybrid mode (membership-only is OFF)
        // Respect public/free courses
        if ( get_post_meta( $course_id, '_tutor_is_public_course', true ) === 'yes' ) {
            return $html;
        }
        if ( get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free' ) {
            return $html;
        }

        // Must have PMPro level associations
        global $wpdb;
        $has_levels = false;
        if ( isset( $wpdb->pmpro_memberships_pages ) ) {
            $has_levels = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $course_id ) ) > 0;
        }
        if ( ! $has_levels ) {
            return $html;
        }

        // Resolve minimal price string
        $price = class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Pricing' ) ? \TUTORPRESS_PMPRO\PMPro_Pricing::get_formatted_price( $course_id ) : '';

        // Fallback: derive price from mapped level data if resolver returned empty
        if ( ! is_string( $price ) || $price === '' ) {
            $amount_strings = array();
            $numeric_amounts = array();
            // Currency helpers
            $cur = $this->get_pmpro_currency();
            $symbol = isset( $cur['currency_symbol'] ) ? $cur['currency_symbol'] : '';
            $pos    = isset( $cur['currency_position'] ) ? $cur['currency_position'] : 'left';
            $fmt = function( $amt ) use ( $symbol, $pos ) {
                $amt = number_format_i18n( (float) $amt, 2 );
                if ( $symbol ) {
                    if ( 'left_space' === $pos ) return $symbol . ' ' . $amt;
                    if ( 'right' === $pos )      return $amt . $symbol;
                    if ( 'right_space' === $pos )return $amt . ' ' . $symbol;
                }
                return $symbol . $amt;
            };
            $per_label = function( $p ) {
                $p = strtolower( (string) $p );
                if ( $p === 'day' ) return __( 'day', 'tutorpress-pmpro' );
                if ( $p === 'week' ) return __( 'week', 'tutorpress-pmpro' );
                if ( $p === 'month' ) return __( 'month', 'tutorpress-pmpro' );
                if ( $p === 'year' ) return __( 'year', 'tutorpress-pmpro' );
                return $p;
            };

            $level_ids = isset( $wpdb->pmpro_memberships_pages ) ? $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $course_id ) ) : array();
            if ( is_array( $level_ids ) ) {
                foreach ( $level_ids as $lid ) {
                    $level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( (int) $lid ) : null;
                    if ( ! $level ) continue;
                    $init  = isset( $level->initial_payment ) ? (float) $level->initial_payment : 0.0;
                    $bill  = isset( $level->billing_amount ) ? (float) $level->billing_amount : 0.0;
                    $cycle = isset( $level->cycle_number ) ? (int) $level->cycle_number : 0;
                    $per   = isset( $level->cycle_period ) ? strtolower( (string) $level->cycle_period ) : '';

                    if ( $bill > 0 && $cycle > 0 && $per ) {
                        $amount_strings[] = sprintf( '%s %s/%s', $fmt( $bill ), __( 'per', 'tutorpress-pmpro' ), $per_label( $per ) );
                        $numeric_amounts[] = $bill;
                    } elseif ( $init > 0 ) {
                        $amount_strings[] = sprintf( '%s %s', $fmt( $init ), __( 'one-time', 'tutorpress-pmpro' ) );
                        $numeric_amounts[] = $init;
                    }
                }
            }
            if ( ! empty( $amount_strings ) ) {
                if ( count( $amount_strings ) === 1 ) {
                    $price = $amount_strings[0];
                } else {
                    $min = min( $numeric_amounts );
                    $price = sprintf( '%s %s', __( 'Starts from', 'tutorpress-pmpro' ), $fmt( $min ) );
                }
            }
        }

        if ( ! is_string( $price ) || $price === '' ) {
            return $html;
        }

        ob_start();
        ?>
        <div class="tutor-d-flex tutor-align-center tutor-justify-between">
            <div class="list-item-price tutor-d-flex tutor-align-center">
                <span class="price tutor-fs-6 tutor-fw-bold tutor-color-black"><?php echo esc_html( $price ); ?></span>
            </div>
            <div class="list-item-button">
                <a href="<?php echo esc_url( get_the_permalink( $course_id ) ); ?>" class="tutor-btn tutor-btn-outline-primary tutor-btn-md tutor-btn-block"><?php esc_html_e( 'View Details', 'tutorpress-pmpro' ); ?></a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }




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
	 * @param mixed   $term_ids term ids.
	 * @param boolean $check_full check full.
	 *
	 * @return mixed
	 */
    private function required_levels( $term_ids, $check_full = false ) {
        global $wpdb;
        $cat_clause = count( $term_ids ) ? ( $check_full ? ' OR ' : '' ) . " (meta.meta_value='category_wise_membership' AND cat_table.category_id IN (" . implode( ',', $term_ids ) . '))' : '';

        $query_last = ( $check_full ? " meta.meta_value='full_website_membership' " : '' ) . $cat_clause;
        $query_last = ( ! $query_last || ctype_space( $query_last ) ) ? '' : ' AND (' . $query_last . ')';

        //phpcs:disable
        return $wpdb->get_results(
            "SELECT DISTINCT level_table.*
            FROM {$wpdb->pmpro_membership_levels} level_table 
                LEFT JOIN {$wpdb->pmpro_memberships_categories} cat_table ON level_table.id=cat_table.membership_id
                LEFT JOIN {$wpdb->pmpro_membership_levelmeta} meta ON level_table.id=meta.pmpro_membership_level_id 
            WHERE 
                meta.meta_key='TUTORPRESS_PMPRO_membership_model' " . $query_last
        );
        //phpcs:enable
    }

    /**
	 * Check has any full site level.
	 *
	 * @return boolean
	 */
    private function has_any_full_site_level() {
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
	 * Just check if has membership access
	 *
	 * @param int $course_id course id.
	 * @param int $user_id user id.
	 *
	 * @return boolean|mixed
	 */
    private function has_course_access( $course_id, $user_id = null ) {
        global $wpdb;

        if ( ! tutor_utils()->has_pmpro( true ) ) {
            // Check if monetization is pmpro and the plugin exists.
            return true;
        }

        // Prepare data.
        $user_id           = null === $user_id ? get_current_user_id() : $user_id;
        $has_course_access = false;

        // Phase 2: In membership-only mode, logged-out users have NO access
        if ( self::tutorpress_pmpro_membership_only_enabled() && ! $user_id ) {
            return false;
        }

        // Get all membership levels of this user.
        $levels = $user_id ? pmpro_getMembershipLevelsForUser( $user_id ) : array();
        ! is_array( $levels ) ? $levels = array() : 0;

        // Get course categories by id.
        $terms    = get_the_terms( $course_id, 'course-category' );
        $term_ids = array_map(
            function( $term ) {
                return $term->term_id;
            },
            ( is_array( $terms ) ? $terms : array() )
        );

        $required_cats = $this->required_levels( $term_ids );
        if ( is_array( $required_cats ) && ! count( $required_cats ) && ! $this->has_any_full_site_level() ) {
            // Has access if no full site level and the course has no category.
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
                    function( $member ) {
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

        return $has_course_access ? true : $this->required_levels( $term_ids, true );
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
	 * Check if user has access to the current content
	 *
	 * @since 1.0.0
	 *
	 * @param int $course_id  current course id.
	 * @param int $content_id course content like lesson, quiz etc.
	 *
	 * @return void
	 */
    public function pmpro_pricing_single_course( $course_id, $content_id ) {
        $course_id  = (int) $course_id;
        $content_id = (int) $content_id;

        $require = $this->pmpro_pricing( null, $course_id );
        // @since v2.0.7 If user has no access to the content then get back to the course.
        $has_course_access  = tutor_utils()->has_user_course_content_access();
        $is_enrolled        = tutor_utils()->is_enrolled( $course_id, get_current_user_id() );
        $is_preview_enabled = tutor()->lesson_post_type === get_post_type( $content_id ) ? (bool) get_post_meta( $content_id, '_is_preview', true ) : false;

        if ( $has_course_access || $is_enrolled || $is_preview_enabled ) {
            return;
        }

        if ( null !== $require ) {
            wp_safe_redirect( get_permalink( $course_id ) );
            exit;
        }
    }

    /**
	 * Alter tutor enroll box to show PMPro pricing
	 *
	 * @param string $html  content to filter.
	 * @param string $course_id  current course id.
	 *
	 * @return string  html content to show on the enrollment section
	 */
    public function pmpro_pricing( $html, $course_id ) {
        // Phase 2 Fix: Free courses should ALWAYS show their normal "Free" enrollment box
        // regardless of membership-only mode status
        $is_free = get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free';
        if ( $is_free ) {
            return $html;
        }
        
        $is_enrolled       = tutor_utils()->is_enrolled();
        $has_course_access = tutor_utils()->has_user_course_content_access();

        /**
		 * If current user has course access then no need to show price
		 * plan.
		 *
		 * @since v2.0.7
		 */
        if ( $is_enrolled || $has_course_access ) {
            return $html;
        }

        // Determine which levels to show based on membership-only mode
        $membership_only_enabled = self::tutorpress_pmpro_membership_only_enabled();
        
        if ( $membership_only_enabled ) {
            // Phase 2: In membership-only mode, show ALL full-site membership levels
            $required_levels = self::pmpro_get_active_full_site_levels();
            
            // Get full level objects
            $level_objects = array();
            foreach ( $required_levels as $level_id ) {
                $level = pmpro_getLevel( $level_id );
                if ( $level ) {
                    $level_objects[] = $level;
                }
            }
            $required_levels = $level_objects;
            
            // If no full-site levels exist, return original HTML (shouldn't happen if toggle validation works)
            if ( empty( $required_levels ) ) {
                return $html;
            }
        } else {
            // Phase 2: In hybrid mode, determine which levels to show based on selling option
            $selling_option = \TUTOR\Course::get_selling_option( $course_id );
            
            if ( \TUTOR\Course::SELLING_OPTION_MEMBERSHIP === $selling_option ) {
                // For 'membership' selling option, show full-site membership levels
                $required_levels = self::pmpro_get_active_full_site_levels();
                
                // Get full level objects
                $level_objects = array();
                foreach ( $required_levels as $level_id ) {
                    $level = pmpro_getLevel( $level_id );
                    if ( $level ) {
                        $level_objects[] = $level;
                    }
                }
                $required_levels = $level_objects;
                
                if ( empty( $required_levels ) ) {
                    return $html;
                }
            } elseif ( \TUTOR\Course::SELLING_OPTION_ALL === $selling_option ) {
                // For 'all' selling option, we need to handle this differently
                // For now, show full-site membership levels (individual pricing will be handled by Tutor's native template)
                $required_levels = self::pmpro_get_active_full_site_levels();
                
                // Get full level objects
                $level_objects = array();
                foreach ( $required_levels as $level_id ) {
                    $level = pmpro_getLevel( $level_id );
                    if ( $level ) {
                        $level_objects[] = $level;
                    }
                }
                $required_levels = $level_objects;
                
                if ( empty( $required_levels ) ) {
                    return $html;
                }
            } else {
                // For other selling options (one_time, subscription, both),
                // show COURSE-SPECIFIC PMPro levels (exclude full-site membership levels)
                
                // Get ALL levels associated with this course via pmpro_memberships_pages
                global $wpdb;
                $level_ids = array();
                if ( isset( $wpdb->pmpro_memberships_pages ) ) {
                    $level_ids = $wpdb->get_col( $wpdb->prepare( 
                        "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", 
                        $course_id 
                    ) );
                }
                
                if ( empty( $level_ids ) ) {
                    // No PMPro level associations found, return original HTML
                    return $html;
                }
                
                // Get full level objects
                $required_levels = array();
                foreach ( $level_ids as $lid ) {
                    $level = pmpro_getLevel( $lid );
                    if ( $level ) {
                        $required_levels[] = $level;
                    }
                }
                
                if ( empty( $required_levels ) ) {
                    return $html;
                }
                
                // Filter out full-site and category-wise membership levels - only show course-specific levels
                $course_specific_levels = array();
                foreach ( $required_levels as $level ) {
                    $level_id = is_object( $level ) ? $level->id : $level;
                    $membership_model = get_pmpro_membership_level_meta( $level_id, 'TUTORPRESS_PMPRO_membership_model', true );
                    
                    // Include level if it does NOT have a membership_model meta (course-specific)
                    // OR if it has a model that is NOT full_website_membership or category_wise_membership
                    if ( empty( $membership_model ) || 
                         ( $membership_model !== 'full_website_membership' && 
                           $membership_model !== 'category_wise_membership' ) ) {
                        $course_specific_levels[] = $level;
                    }
                }
                
                if ( empty( $course_specific_levels ) ) {
                    // All levels were full-site or category membership, return original HTML
                    return $html;
                }
                
                $required_levels = $course_specific_levels;
            }
        }
        
        // Render membership pricing template with the appropriate levels
        $level_page_id  = apply_filters( 'TUTORPRESS_PMPRO_level_page_id', pmpro_getOption( 'levels_page_id' ) );
        $level_page_url = get_the_permalink( $level_page_id );

        //phpcs:ignore
        extract( $this->get_pmpro_currency() );

        ob_start();
        include dirname( __DIR__ ) . '/views/pmpro-pricing.php';
        return ob_get_clean();
    }

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
    private function get_pmpro_currency() {
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
	 * Map PMPro membership level IDs to Tutor LMS course IDs using pmpro_memberships_pages.
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

		// Build a prepared IN() clause and include dynamic post type.
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
    private function is_pmpro_enabled() {
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

		foreach ( $pmpro_old_user_levels as $user_id => $old_levels ) {
			// Current level IDs for user
			$current_levels = pmpro_getMembershipLevelsForUser( $user_id );
			$current_level_ids = ! empty( $current_levels ) ? wp_list_pluck( $current_levels, 'ID' ) : array();

			// Old level IDs
			$old_level_ids = ! empty( $old_levels ) ? wp_list_pluck( $old_levels, 'ID' ) : array();

			$current_courses = $this->get_courses_for_levels( $current_level_ids );
			$old_courses     = $this->get_courses_for_levels( $old_level_ids );

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

			// Unenroll
			foreach ( $courses_to_unenroll as $course_id ) {
				if ( tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
					tutor_utils()->cancel_course_enrol( $course_id, $user_id );
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
    public function show_pmpro_membership_plans( $html, $course_id ) {
        // Phase 2 Fix: Always respect free courses - they should never show membership pricing
        $is_free = get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free';
        if ( $is_free ) {
            return $html;
        }
        
        // Phase 2 Fix: Only apply membership pricing logic if:
        // 1. Membership-only mode is enabled, OR
        // 2. The course selling option explicitly includes memberships ('membership' or 'all')
        
        $membership_only_enabled = self::tutorpress_pmpro_membership_only_enabled();
        
        if ( ! $membership_only_enabled ) {
            // In hybrid mode, only show membership plans if course selling option is 'membership' or 'all'
            $selling_option = \TUTOR\Course::get_selling_option( $course_id );
            
            // Only show PMPro membership pricing for courses configured with membership options
            if ( \TUTOR\Course::SELLING_OPTION_MEMBERSHIP !== $selling_option && 
                 \TUTOR\Course::SELLING_OPTION_ALL !== $selling_option ) {
                // For one_time, subscription, or both - return original HTML (shows individual pricing)
                return $html;
            }
        }

        // For membership-only mode OR courses with 'membership' or 'all' selling option,
        // show PMPro membership plans
        return $this->pmpro_pricing( $html, $course_id );
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
     * Wrapper to check if membership-only mode is enabled and validated.
     * This wrapper ensures the toggle is only considered "enabled" when:
     * 1. The toggle option is set to 'on'
     * 2. At least one full-site membership level exists
     *
     * @since 1.4.0
     * @return bool
     */
    public static function tutorpress_pmpro_membership_only_enabled() {
        $toggle_value = tutor_utils()->get_option( 'tutorpress_pmpro_membership_only_mode', 'off' );
        $toggle_enabled = ( 'on' === $toggle_value || $toggle_value === true );
        return $toggle_enabled && self::pmpro_has_full_site_level();
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
     * Render the "View Pricing" button for membership-only mode in course loops.
     * 
     * Displays a button linking to the PMPro levels page.
     *
     * @since 1.4.0
     * @return string HTML for the pricing button.
     */
    public function render_membership_price_button() {
        $level_page_id = apply_filters( 'TUTORPRESS_PMPRO_level_page_id', pmpro_getOption( 'levels_page_id' ) );
        $level_page_url = get_permalink( $level_page_id );

        if ( ! $level_page_url ) {
            $level_page_url = home_url( '/membership-levels/' );
        }

        ob_start();
        ?>
        <div class="tutor-d-flex tutor-align-center">
            <a href="<?php echo esc_url( $level_page_url ); ?>" class="tutor-btn tutor-btn-outline-primary tutor-btn-md tutor-btn-block">
                <?php esc_html_e( 'View Pricing', 'tutorpress-pmpro' ); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Filter to block guest enrollment attempts when membership-only mode is enabled.
     * 
     * When membership-only mode is ON, guests cannot enroll in courses.
     * They must register and purchase a membership first.
     *
     * @since 1.4.0
     * @param bool $allowed Whether guest enrollment is allowed.
     * @param int  $course_id Course ID.
     * @param int  $user_id User ID (0 for guests).
     * @return bool False if membership-only mode is enabled, otherwise original value.
     */
    public function filter_allow_guest_attempt_enrollment( $allowed, $course_id, $user_id ) {
        if ( self::tutorpress_pmpro_membership_only_enabled() ) {
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
     * @since 1.4.0
     * @param int $course_id Course ID.
     * @param int $user_id User ID.
     * @param int $enrolled_id Enrollment ID.
     * @return void
     */
    public function handle_after_enrollment_completed( $course_id, $user_id, $enrolled_id ) {
        $membership_enrollment_flag_required = false;

        // For membership-only mode: flag all enrollments as membership-based
        if ( self::tutorpress_pmpro_membership_only_enabled() ) {
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
                $level = is_array( $user_levels ) ? reset( $user_levels ) : null;
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
     * @since 1.4.0
     * @param int $bundle_id Bundle ID.
     * @param int $user_id User ID.
     * @param int $level_id PMPro level ID.
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
            $has_access = $this->has_course_access( $bundle_course_id, $user_id );
            
            if ( $has_access ) {
                // Auto-enroll in the bundle course
                add_filter( 'tutor_enroll_data', function( $enroll_data ) {
                    return array_merge( $enroll_data, array( 'post_status' => 'completed' ) );
                } );
                
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



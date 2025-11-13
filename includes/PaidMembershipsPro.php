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
	 * Register hooks
	 */
    public function __construct() {
        // Phase 7, Step 7.3: Load access control service
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/access/interface-access-checker.php';
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/access/class-access-checker.php';
        $this->access_checker = new \TUTORPRESS_PMPRO\Access\Access_Checker();
        
        // Phase 10, Substep 1: Load pricing utilities (manually loaded for consistency)
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/pricing/class-pricing-manager.php';
        
        // Phase 10, Substep 2: Load sale price handler service
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/pricing/class-sale-price-handler.php';
        $this->sale_price_handler = new \TUTORPRESS_PMPRO\Pricing\Sale_Price_Handler();
        
        // Phase 8, Step 8.1: Load frontend services
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/frontend/class-pricing-display.php';
        $this->pricing_display = new \TUTORPRESS_PMPRO\Frontend\Pricing_Display( $this->access_checker, $this, $this->sale_price_handler );
        
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/frontend/class-enrollment-ui.php';
        $this->enrollment_ui = new \TUTORPRESS_PMPRO\Frontend\Enrollment_UI( $this->access_checker, $this->pricing_display );
        
        // Phase 9, Substep 1 & 2: Load admin level settings service
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/admin/class-level-settings.php';
        $this->level_settings = new \TUTORPRESS_PMPRO\Admin\Level_Settings();
        
        // Phase 9, Substep 3: Load admin notices service
        require_once \TUTORPRESS_PMPRO_DIR . 'includes/admin/class-admin-notices.php';
        $this->admin_notices = new \TUTORPRESS_PMPRO\Admin\Admin_Notices();
        
        // Register frontend pricing hooks late in page lifecycle
        add_action( 'wp', array( $this, 'init_pmpro_price_overrides' ), 20 );
        add_filter( 'tutor_course/single/add-to-cart', array( $this, 'tutor_course_add_to_cart' ) );
        add_filter( 'tutor_course_price', array( $this, 'tutor_course_price' ) );
        add_filter( 'tutor-loop-default-price', array( $this, 'add_membership_required' ) );

		if ( tutor_utils()->has_pmpro( true ) ) {
			// Only wire PMPro behaviors when PMPro is the selected monetization engine (overridable via filter)
			if ( ! $this->is_pmpro_enabled() ) {
				return;
			}

            // Moved to Admin\Level_Settings service
            // - pmpro_membership_levels_table_extra_cols_body → level_category_list_body
            // - pmpro_membership_levels_table → outstanding_cat_notice
            // - admin_enqueue_scripts → admin_script

            add_action( 'wp_enqueue_scripts', array( $this, 'pricing_style' ) );

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
	 * Required levels
	 *
	 * Get required membership levels for given categories.
     *
     * Delegated to Access_Checker service.
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
     * Delegated to Access_Checker service.
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
     * Delegated to Access_Checker service.
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
	 * Get PMPro currency settings.
	 *
	 * Phase 10, Substep 1 (Refactored): Delegates to Pricing_Manager static utility.
	 * Kept as instance method for backward compatibility with existing code.
	 *
	 * @since 1.0.0
	 * @return array Associative array with 'currency_symbol' and 'currency_position' keys.
	 */
    public function get_pmpro_currency() {
        return \TUTORPRESS_PMPRO\Pricing\Pricing_Manager::get_pmpro_currency();
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
}

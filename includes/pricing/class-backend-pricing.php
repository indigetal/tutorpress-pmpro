<?php
/**
 * Backend Pricing
 *
 * Handles PMPro pricing display in Tutor LMS backend/admin areas.
 * Injects PMPro pricing into course info and REST API responses
 * for accurate display in backend course lists.
 *
 * @package TutorPress_PMPro
 * @subpackage Pricing
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Pricing;

/**
 * Backend Pricing class.
 *
 * Service class responsible for displaying PMPro pricing in admin/backend contexts.
 * Hooks into Tutor's course info and REST API response building to inject
 * accurate PMPro pricing data for both courses and bundles.
 *
 * @since 1.0.0
 */
class Backend_Pricing {

	/**
	 * Access checker service instance.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\Access\Access_Checker
	 */
	private $access_checker;

	/**
	 * Sale price handler service instance.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\Pricing\Sale_Price_Handler
	 */
	private $sale_price_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param \TUTORPRESS_PMPRO\Access\Access_Checker      $access_checker      Access checker instance.
	 * @param \TUTORPRESS_PMPRO\Pricing\Sale_Price_Handler $sale_price_handler  Sale price handler instance.
	 */
	public function __construct( $access_checker, $sale_price_handler ) {
		$this->access_checker      = $access_checker;
		$this->sale_price_handler  = $sale_price_handler;
	}

	/**
	 * Register WordPress hooks for backend pricing display.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// CRITICAL: Hook early for admin course list page (before wp action)
		// The admin course list page calls tutor_utils()->get_course_price() which uses get_tutor_course_price filter
		add_filter( 'get_tutor_course_price', array( $this, 'filter_get_tutor_course_price' ), 12, 2 );

		// Hook 1: Inject into course mini info (affects archives, dashboards, cards)
		add_filter( 'tutor_course_mini_info', array( $this, 'inject_pmpro_mini_info' ), 10, 2 );

		// Hook 2: Inject into REST API responses (affects backend list, API calls)
		add_filter( 'tutor_rest_course_single_post', array( $this, 'inject_pmpro_rest_price' ), 10, 1 );
	}

	/**
	 * Filter get_tutor_course_price to inject PMPro pricing.
	 *
	 * The admin course list page uses tutor_utils()->get_course_price() to display
	 * pricing in the course table. This filter intercepts that call and returns
	 * formatted PMPro pricing if available.
	 *
	 * Priority 12 ensures we run AFTER default handling but BEFORE other integrations.
	 *
	 * @since 1.0.0
	 *
	 * @param string|null $price     The formatted price HTML string (or null).
	 * @param int         $course_id Course or bundle post ID.
	 * @return string|null Formatted PMPro price string, or original price if not PMPro.
	 */
	public function filter_get_tutor_course_price( $price, $course_id = 0 ) {
		$course_id = $course_id ? (int) $course_id : 0;
		if ( ! $course_id ) {
			return $price;
		}

		// Only process if PMPro is the active monetization engine
		if ( ! $this->is_pmpro_enabled() ) {
			return $price;
		}

		// Respect free courses/bundles
		if ( get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free' ) {
			return $price;
		}

		// Detect post type (course or bundle)
		$post_type = get_post_type( $course_id );
		$is_bundle = ( 'course-bundle' === $post_type );

		// Get level IDs
		$level_ids = $this->get_level_ids_for_object( $course_id, $is_bundle );

		if ( empty( $level_ids ) ) {
			return $price; // No PMPro levels, return original price
		}

		// If we have PMPro levels, build and return PMPro price string
		return $this->build_pmpro_price_string( $level_ids );
	}

	/**
	 * Build formatted PMPro price string for display.
	 *
	 * Handles multiple pricing levels and sale prices.
	 * For recurring subscriptions, displays the renewal (billing_amount) price, not initial payment.
	 *
	 * @since 1.0.0
	 *
	 * @param int[] $level_ids Array of PMPro level IDs.
	 * @return string Formatted price string for display.
	 */
	private function build_pmpro_price_string( $level_ids ) {
		if ( empty( $level_ids ) ) {
			return null;
		}

		// Get currency info
		$symbol   = '';
		$position = 'left';

		if ( function_exists( 'pmpro_getOption' ) ) {
			global $pmpro_currencies, $pmpro_currency;
			$curr = $pmpro_currency ? $pmpro_currency : '';
			$data = ( 'USD' === $curr ) ? array( 'symbol' => '$', 'position' => 'left' ) : ( isset( $pmpro_currencies[ $curr ] ) ? $pmpro_currencies[ $curr ] : null );

			$symbol   = ( is_array( $data ) && isset( $data['symbol'] ) ) ? $data['symbol'] : '';
			$position = ( is_array( $data ) && isset( $data['position'] ) ) ? strtolower( $data['position'] ) : 'left';
		}

		// Helper to format amounts
		$format_price = function ( $amt ) use ( $symbol, $position ) {
			$amt = number_format_i18n( (float) $amt, 2 );
			if ( $symbol ) {
				if ( 'left_space' === $position ) {
					return $symbol . ' ' . $amt;
				} elseif ( 'right' === $position ) {
					return $amt . $symbol;
				} elseif ( 'right_space' === $position ) {
					return $amt . ' ' . $symbol;
				}
			}
			return $symbol . $amt;
		};

		// Collect prices and determine minimum
		// For recurring subscriptions, use the renewal price (billing_amount), not initial payment
		$prices         = array();
		$min_level      = null;
		$min_price      = PHP_INT_MAX;
		$has_sale_price = false;

		foreach ( $level_ids as $level_id ) {
			$level = function_exists( 'pmpro_getLevel' ) ? \pmpro_getLevel( (int) $level_id ) : null;
			if ( ! $level ) {
				continue;
			}

			// Determine if this is a recurring subscription
			$billing_amount = (float) $level->billing_amount;
			$cycle_number   = (int) $level->cycle_number;
			$cycle_period   = (string) $level->cycle_period;
			$is_recurring   = ( $billing_amount > 0 && $cycle_number > 0 && $cycle_period );

			// Get active price (includes sale pricing check)
			$active_price = $this->sale_price_handler->get_active_price_for_level( $level_id );

			// CRITICAL: For recurring subscriptions, use the renewal price (billing_amount)
			// not the initial payment. For one-time, use initial_payment.
			// Sales only apply to the initial payment, not the renewal.
			if ( $is_recurring ) {
				// Recurring: always use billing_amount (renewal price), never sale-adjusted
				$display_price = $billing_amount;
			} else {
				// One-time: use initial_payment (may be sale-adjusted)
				$display_price = $active_price['on_sale']
					? (float) $active_price['price']
					: (float) $level->initial_payment;
			}

			$prices[] = $display_price;

			if ( $display_price < $min_price ) {
				$min_price = $display_price;
				$min_level = $level;
			}

			if ( $active_price['on_sale'] ) {
				$has_sale_price = true;
			}
		}

		if ( empty( $prices ) || $min_level === null ) {
			return null;
		}

		// Single price: show as "X.XX one-time" or recurring
		if ( count( $prices ) === 1 ) {
			$billing_amount = (float) $min_level->billing_amount;
			$cycle_number   = (int) $min_level->cycle_number;
			$cycle_period   = (string) $min_level->cycle_period;

			if ( $billing_amount > 0 && $cycle_number > 0 && $cycle_period ) {
				// Recurring: display renewal price with period
				$period_label = $this->get_period_label( $cycle_period );
				return sprintf(
					'%s / %s',
					$format_price( $min_price ),
					$period_label
				);
			} else {
				// One-time: display initial payment
				return sprintf(
					'%s',
					$format_price( $min_price )
				);
			}
		}

		// Multiple prices: show minimum
		return sprintf(
			'%s %s',
			__( 'from', 'tutorpress-pmpro' ),
			$format_price( $min_price )
		);
	}

	/**
	 * Get translated period label.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period Period string (day, week, month, year).
	 * @return string Translated period label.
	 */
	private function get_period_label( $period ) {
		$period = strtolower( (string) $period );
		switch ( $period ) {
			case 'day':
				return __( 'day', 'tutorpress-pmpro' );
			case 'week':
				return __( 'week', 'tutorpress-pmpro' );
			case 'month':
				return __( 'month', 'tutorpress-pmpro' );
			case 'year':
				return __( 'year', 'tutorpress-pmpro' );
			default:
				return esc_html( $period );
		}
	}

	/**
	 * Inject PMPro pricing into course mini info.
	 *
	 * This filter is called when building course info for various displays
	 * (archives, dashboards, cards). We inject the PMPro pricing to replace
	 * the default pricing when PMPro is the active monetization engine.
	 *
	 * @since 1.0.0
	 *
	 * @param array    $info Course mini info array with pricing.
	 * @param \WP_Post $post Course/bundle post object.
	 * @return array Modified info array with PMPro pricing injected.
	 */
	public function inject_pmpro_mini_info( $info, $post ) {
		// Only process if PMPro is the active monetization engine
		if ( ! $this->is_pmpro_enabled() ) {
			return $info;
		}

		// Get PMPro pricing for this course/bundle
		$pmpro_prices = $this->get_pmpro_backend_pricing( $post->ID );

		if ( $pmpro_prices ) {
			// Inject regular price (what the backend displays as "price")
			$info['regular_price'] = $pmpro_prices->regular_price;

			// Inject sale price (if applicable)
			$info['sale_price'] = $pmpro_prices->sale_price;
		}

		return $info;
	}

	/**
	 * Inject PMPro pricing into REST API response.
	 *
	 * This filter is called when building REST API response for a course.
	 * Injects PMPro pricing into the $post->price field for backend display.
	 *
	 * @since 1.0.0
	 *
	 * @param object $post REST response object.
	 * @return object Modified response with PMPro price injected.
	 */
	public function inject_pmpro_rest_price( $post ) {
		// Only process if PMPro is the active monetization engine
		if ( ! $this->is_pmpro_enabled() ) {
			return $post;
		}

		// Get PMPro pricing for this course/bundle
		$pmpro_prices = $this->get_pmpro_backend_pricing( $post->ID );

		if ( $pmpro_prices ) {
			// Set the price field (displayed in backend course list)
			$post->price = $pmpro_prices->regular_price;

			// Optional: Also set formatted_price for display
			if ( ! empty( $pmpro_prices->formatted_price ) ) {
				$post->formatted_price = $pmpro_prices->formatted_price;
			}
		}

		return $post;
	}

	/**
	 * Get PMPro pricing data for backend display.
	 *
	 * Calculates and returns pricing information from associated PMPro levels.
	 * Handles both courses and bundles. Returns object with regular_price, sale_price,
	 * and optional formatted_price for display purposes.
	 *
	 * For recurring subscriptions, uses the renewal price (billing_amount).
	 * For one-time purchases, uses the initial_payment (may be sale-adjusted).
	 *
	 * Returns false if not a PMPro course or no levels found.
	 *
	 * @since 1.0.0
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return object|false Pricing object with regular_price, sale_price, or false if no PMPro pricing.
	 */
	private function get_pmpro_backend_pricing( $object_id ) {
		$object_id = absint( $object_id );
		if ( ! $object_id ) {
			return false;
		}

		// Detect if this is a bundle or course
		$post_type = get_post_type( $object_id );
		$is_bundle = ( 'course-bundle' === $post_type );

		// Get all PMPro levels for this object
		$level_ids = $this->get_level_ids_for_object( $object_id, $is_bundle );

		if ( empty( $level_ids ) ) {
			return false; // No PMPro levels associated
		}

		// Find the minimum price across all levels (for backend display)
		$min_regular_price = PHP_INT_MAX;
		$min_sale_price    = PHP_INT_MAX;
		$min_level_id      = null;
		$level_count       = 0;

		foreach ( $level_ids as $level_id ) {
			$level = function_exists( 'pmpro_getLevel' ) ? \pmpro_getLevel( $level_id ) : null;

			if ( ! $level ) {
				continue;
			}

			$level_count++;

			// Determine if this is a recurring subscription
			$billing_amount = (float) $level->billing_amount;
			$cycle_number   = (int) $level->cycle_number;
			$cycle_period   = (string) $level->cycle_period;
			$is_recurring   = ( $billing_amount > 0 && $cycle_number > 0 && $cycle_period );

			// Get active price data (handles sale pricing)
			$active_price = $this->sale_price_handler->get_active_price_for_level( $level_id );

			// CRITICAL: For recurring subscriptions, use the renewal price (billing_amount)
			// For one-time, use initial_payment (may be sale-adjusted)
			if ( $is_recurring ) {
				$regular = $billing_amount;
			} else {
				$regular = ! empty( $active_price['regular_price'] )
					? (float) $active_price['regular_price']
					: (float) $level->initial_payment;
			}

			if ( $regular < $min_regular_price ) {
				$min_regular_price = $regular;
				$min_level_id = $level_id;
			}

			// Track minimum sale price (if on sale and one-time level)
			if ( ! $is_recurring && $active_price['on_sale'] && $active_price['price'] < $min_sale_price ) {
				$min_sale_price = (float) $active_price['price'];
			}
		}

		// No valid levels found
		if ( is_infinite( $min_regular_price ) || $min_level_id === null ) {
			return false;
		}

		// Build pricing object
		$pricing = (object) array(
			'regular_price'  => $min_regular_price,
			'sale_price'     => ( $min_sale_price < PHP_INT_MAX ) ? $min_sale_price : 0,
			'level_count'    => $level_count,
			'formatted_price' => null, // Optional: can be populated for display
		);

		return $pricing;
	}

	/**
	 * Get PMPro level IDs for a course or bundle.
	 *
	 * Uses three lookup strategies:
	 * 1. pmpro_memberships_pages table (standard PMPro restriction)
	 * 2. Level meta reverse lookup (course-specific levels via tutorpress_course_id)
	 * 3. Bundle expansion (bundle-specific levels via tutorpress_bundle_id)
	 *
	 * @since 1.0.0
	 *
	 * @param int  $object_id Course or bundle post ID.
	 * @param bool $is_bundle Whether this is a bundle (true) or course (false).
	 * @return array Array of PMPro level IDs.
	 */
	private function get_level_ids_for_object( $object_id, $is_bundle = false ) {
		global $wpdb;

		$level_ids = array();

		// Primary: pmpro_memberships_pages table
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$found_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d",
					$object_id
				)
			);
			if ( is_array( $found_ids ) ) {
				$level_ids = array_merge( $level_ids, array_map( 'intval', $found_ids ) );
			}
		}

		// Secondary: Reverse meta lookup via course/bundle meta
		if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$meta_key = $is_bundle ? 'tutorpress_bundle_id' : 'tutorpress_course_id';
			$found_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta}
					 WHERE meta_key = %s AND meta_value = %s",
					$meta_key,
					(string) $object_id
				)
			);
			if ( is_array( $found_ids ) ) {
				$level_ids = array_merge( $level_ids, array_map( 'intval', $found_ids ) );
			}
		}

		// Remove duplicates and ensure integers
		$level_ids = array_values( array_unique( array_filter( $level_ids ) ) );

		return $level_ids;
	}

	/**
	 * Determine if PMPro is the active monetization engine.
	 *
	 * @since 1.0.0
	 * @return bool True if PMPro is enabled, false otherwise.
	 */
	private function is_pmpro_enabled() {
		if ( function_exists( 'get_tutor_option' ) ) {
			return 'pmpro' === \get_tutor_option( 'monetize_by' );
		}
		return false;
	}
}


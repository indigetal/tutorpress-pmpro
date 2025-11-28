<?php
/**
 * Pricing Display
 *
 * Handles frontend pricing and enrollment UI display logic.
 * Manages course loop pricing, single course pricing, membership plan displays,
 * and PMPro content restriction overrides for course pages.
 *
 * @package TutorPress_PMPro
 * @subpackage Frontend
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Frontend;

/**
 * Class Pricing_Display
 *
 * Service class responsible for displaying pricing information and enrollment UI
 * on the frontend. Also handles PMPro content filter overrides to ensure students
 * can see course details, pricing, and enrollment options on course single pages.
 * 
 * Extracted from PaidMembershipsPro class to follow Single Responsibility Principle.
 */
class Pricing_Display {

	/**
	 * Access checker service instance.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\Access\Access_Checker
	 */
	private $access_checker;

	/**
	 * Reference to PaidMembershipsPro for utility methods.
	 *
	 * Used to call methods not yet extracted (is_pmpro_enabled, etc.)
	 * Will be removed as we continue extracting methods in future phases.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\PaidMembershipsPro
	 */
	private $pmpro;

	/**
	 * Sale price handler service instance.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\Pricing\Sale_Price_Handler
	 */
	private $sale_price_handler;

	/**
	 * Enrollment handler service instance.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\Enrollment\Enrollment_Handler
	 */
	private $enrollment_handler;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param \TUTORPRESS_PMPRO\Access\Access_Checker          $access_checker      Access checker instance.
	 * @param \TUTORPRESS_PMPRO\PaidMembershipsPro              $pmpro               PaidMembershipsPro instance.
	 * @param \TUTORPRESS_PMPRO\Pricing\Sale_Price_Handler     $sale_price_handler  Sale price handler instance.
	 * @param \TUTORPRESS_PMPRO\Enrollment\Enrollment_Handler  $enrollment_handler  Enrollment handler instance.
	 */
	public function __construct( $access_checker, $pmpro, $sale_price_handler, $enrollment_handler ) {
		$this->access_checker      = $access_checker;
		$this->pmpro               = $pmpro;
		$this->sale_price_handler  = $sale_price_handler;
		$this->enrollment_handler  = $enrollment_handler;
	}

	/**
	 * Register frontend pricing hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// Course loop/archive pricing
		add_filter( 'get_tutor_course_price', array( $this, 'filter_get_tutor_course_price' ), 12, 2 );
		add_filter( 'tutor_course_loop_price', array( $this, 'filter_course_loop_price_pmpro' ), 12, 2 );
		add_filter( 'tutor_course_sell_by', array( $this, 'filter_course_sell_by' ), 9, 1 );
		
		// Phase 4: Alter bundle enrollment status for entry box
		add_filter( 'tutor_alter_enroll_status', array( $this, 'alter_bundle_enroll_status' ), 10, 1 );
		
		// Phase 4: Override Tutor Pro's bundle enrolled content to fix anchor link
		add_filter( 'tutor/course/single/entry-box/is_enrolled', array( $this, 'fix_bundle_enrolled_content' ), 11, 2 );
		
		// Single course/bundle pricing
		add_filter( 'tutor/course/single/entry-box/free', array( $this, 'pmpro_pricing' ), 10, 2 );
		add_filter( 'tutor/course/single/entry-box/is_enrolled', array( $this, 'pmpro_pricing' ), 10, 2 );
		add_filter( 'tutor/course/single/entry-box/purchasable', array( $this, 'pmpro_pricing' ), 10, 2 );
		add_filter( 'tutor/course/single/entry-box/is_public', array( $this, 'pmpro_pricing' ), 10, 2 );
		add_filter( 'tutor/course/single/entry-box/fully_booked', array( $this, 'pmpro_pricing' ), 10, 2 );
		add_action( 'tutor/course/single/content/before/all', array( $this, 'pmpro_pricing_single_course' ), 100, 2 );
		
		// Additional pricing filters
		add_filter( 'tutor_course/single/add-to-cart', array( $this, 'tutor_course_add_to_cart' ) );
		add_filter( 'tutor_course_price', array( $this, 'tutor_course_price' ) );
		add_filter( 'tutor-loop-default-price', array( $this, 'add_membership_required' ) );
		add_filter( 'tutor_course_expire_validity', array( $this, 'filter_expire_time' ), 99, 2 );
		
		// Frontend styles
		add_action( 'wp_enqueue_scripts', array( $this, 'pricing_style' ) );
		
		// PMPro content restriction override (allow course pages to display full content)
		add_filter( 'pmpro_membership_content_filter', array( $this, 'allow_course_page_content' ), 10, 3 );
	}

	/**
	 * Enqueue frontend pricing styles for single course pages.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function pricing_style() {
		if ( function_exists( 'is_single_course' ) && is_single_course() ) {
			wp_enqueue_style( 'tutorpress-pmpro-pricing', TUTORPRESS_PMPRO()->url . 'assets/css/pricing.css', array(), TUTORPRESS_PMPRO_VERSION );
		}
	}

	/**
	 * Alter enrollment status for bundles.
	 *
	 * Phase 4: For bundles, check if user is enrolled in all bundle courses via PMPro.
	 * This allows the entry box template to display enrollment info correctly.
	 *
	 * @since 1.0.0
	 * @param bool $is_enrolled Current enrollment status.
	 * @return bool Modified enrollment status.
	 */
	public function alter_bundle_enroll_status( $is_enrolled ) {
		// Only run on single bundle pages
		if ( ! is_singular( 'course-bundle' ) ) {
			return $is_enrolled;
		}

		$bundle_id = get_the_ID();
		if ( ! $bundle_id ) {
			return $is_enrolled;
		}

		// Check if user is enrolled in bundle via PMPro
		$bundle_enrolled = $this->is_user_enrolled_in_bundle( $bundle_id, get_current_user_id() );

		// If enrolled via PMPro, return true to show enrollment info
		if ( $bundle_enrolled ) {
			return true;
		}

		return $is_enrolled;
	}

	/**
	 * Fix bundle enrolled content to use correct anchor link.
	 *
	 * Phase 4: Tutor Pro's content_for_enrolled_user checks tutor_utils()->is_enrolled()
	 * which returns FALSE for bundles, causing the link to be just "#". We override this
	 * to provide the correct link with #tutor-bundle-course-list anchor.
	 *
	 * @since 1.0.0
	 * @param string $content Current content.
	 * @param int    $post_id Bundle ID.
	 * @return string Modified content.
	 */
	public function fix_bundle_enrolled_content( $content, $post_id ) {
		// Only run for bundles
		if ( 'course-bundle' !== get_post_type( $post_id ) ) {
			return $content;
		}

		// Only run if user is enrolled via PMPro
		if ( ! $this->is_user_enrolled_in_bundle( $post_id, get_current_user_id() ) ) {
			return $content;
		}

		// Check if content has the incorrect link (href="#")
		if ( strpos( $content, 'href="#"' ) !== false ) {
			// Replace with correct anchor link
			$correct_link = get_the_permalink( $post_id ) . '#tutor-bundle-course-list';
			$content = str_replace( 'href="#"', 'href="' . esc_url( $correct_link ) . '"', $content );
		}

		return $content;
	}

	/**
	 * Filter course selling method to use Tutor monetization wrappers.
	 *
	 * For PMPro engine, this ensures we use Tutor's monetization wrappers in loops
	 * so get_tutor_course_price can render the PMPro price string.
	 *
	 * @since 1.0.0
	 * @param string $sell_by Current selling method.
	 * @return string Modified selling method ('tutor' when PMPro is enabled).
	 */
	public function filter_course_sell_by( $sell_by ) {
		if ( ! $this->pmpro->is_pmpro_enabled() ) {
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
	 * @since 1.0.0
	 * @param string $html      Current HTML output.
	 * @param int    $course_id Course post ID.
	 * @return string Modified HTML output.
	 */
	public function filter_course_loop_price_pmpro( $html, $course_id ) {
		if ( ! $this->pmpro->is_pmpro_enabled() ) {
			return $html;
		}

		$course_id = (int) $course_id ?: (int) get_the_ID();
		if ( ! $course_id ) {
			return $html;
		}

		// Phase 3: Detect post type (course or bundle)
		$post_type = get_post_type( $course_id );
		$is_bundle = ( 'course-bundle' === $post_type );

		// Phase 4: Check bundle enrollment BEFORE mode-specific logic (works in both membership-only and hybrid modes)
		// For bundles, check if enrolled via PMPro (bundles don't have direct enrollment records)
		if ( $is_bundle ) {
			$bundle_enrolled = $this->is_user_enrolled_in_bundle( $course_id, get_current_user_id() );
			if ( $bundle_enrolled ) {
				// User is enrolled in bundle via PMPro - return "Bundle Details" button
				// This matches Tutor's behavior for enrolled courses (shows "Continue Learning" button)
				ob_start();
				?>
				<div class="list-item-button">
					<a href="<?php echo esc_url( get_the_permalink( $course_id ) . '#tutor-bundle-course-list' ); ?>" class="tutor-btn tutor-btn-outline-primary tutor-btn-md tutor-btn-block">
						<?php esc_html_e( 'Bundle Details', 'tutorpress-pmpro' ); ?>
					</a>
				</div>
				<?php
				return ob_get_clean();
			}
		}

		// Phase 2, Step 6: Membership-only mode loop price override
		if ( \TUTORPRESS_PMPRO\PaidMembershipsPro::tutorpress_pmpro_membership_only_enabled() ) {
			// Respect public courses (bundles don't have public option)
			if ( ! $is_bundle && \TUTOR\Course_List::is_public( $course_id ) ) {
				return $html;
			}

			// Respect free courses/bundles
			if ( get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free' ) {
				return $html;
			}

			// Keep enrollment display if user purchased this course individually (not via membership)
			$is_enrolled = tutor_utils()->is_enrolled( $course_id );
			if ( $is_enrolled && ! $this->enrollment_handler->is_enrolled_by_pmpro_membership( $course_id ) ) {
				return $html;
			}

			// For all other cases in membership-only mode, show "View Pricing" button
			// This includes: logged-out users, users without membership, users without access
			// has_course_access() returns:
			// - true if user has access
			// - false if no membership required (shouldn't happen in membership-only mode)
			// - array of required levels if user doesn't have access
			$user_has_access = $this->access_checker->has_course_access( $course_id );

			// Only show original HTML if user truly has access (returns exactly true)
			if ( $user_has_access === true ) {
				return $html;
			}

			// For all other cases (false, array, etc.), show "View Pricing" button
			return $this->render_membership_price_button( $course_id );
		}

		// Below this point: Hybrid mode (membership-only is OFF)
		// Respect public/free courses (bundles don't have public option)
		if ( ! $is_bundle && get_post_meta( $course_id, '_tutor_is_public_course', true ) === 'yes' ) {
			return $html;
		}
		if ( get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free' ) {
			return $html;
		}

		// Check if this course/bundle has "membership" selling option - show "View Pricing" button
		$selling_option = \TUTOR\Course::get_selling_option( $course_id );
		if ( \TUTOR\Course::SELLING_OPTION_MEMBERSHIP === $selling_option ) {
			return $this->render_membership_price_button( $course_id );
		}

		// Phase 3: Get level IDs using bundle-specific or course-specific discovery
		$level_ids = $this->get_level_ids_for_object( $course_id, $is_bundle );
		if ( empty( $level_ids ) ) {
			return $html;
		}

		// Phase 1, Step 1.1 Extension: Check if user has access via any membership type
		// If user has access, return original HTML (don't show pricing)
		$user_has_access = $this->access_checker->has_course_access( $course_id );

		if ( $user_has_access === true ) {
			return $html;
		}

		// Phase 10, Substep 1: Removed Pricing_Manager call here because the code below
		// is more complete and includes sale price support via get_active_price_for_level().
		// Pricing_Manager::get_formatted_price() doesn't support sale pricing.
		$price = '';

		// Derive price from mapped level data with sale support
		if ( ! is_string( $price ) || $price === '' ) {
			$amount_strings  = array();
			$numeric_amounts = array();
			// Currency helpers
			$cur    = $this->pmpro->get_pmpro_currency();
			$symbol = isset( $cur['currency_symbol'] ) ? $cur['currency_symbol'] : '';
			$pos    = isset( $cur['currency_position'] ) ? $cur['currency_position'] : 'left';
			$fmt    = function ( $amt ) use ( $symbol, $pos ) {
				$amt = number_format_i18n( (float) $amt, 2 );
				if ( $symbol ) {
					if ( 'left_space' === $pos ) {
						return $symbol . ' ' . $amt;}
					if ( 'right' === $pos ) {
						return $amt . $symbol;}
					if ( 'right_space' === $pos ) {
						return $amt . ' ' . $symbol;}
				}
				return $symbol . $amt;
			};
			$per_label = function ( $p ) {
				$p = strtolower( (string) $p );
				if ( $p === 'day' ) {
					return __( 'day', 'tutorpress-pmpro' );}
				if ( $p === 'week' ) {
					return __( 'week', 'tutorpress-pmpro' );}
				if ( $p === 'month' ) {
					return __( 'month', 'tutorpress-pmpro' );}
				if ( $p === 'year' ) {
					return __( 'year', 'tutorpress-pmpro' );}
				return $p;
			};

			// Phase 3: Use bundle-specific or course-specific level discovery
			$level_ids = $this->get_level_ids_for_object( $course_id, $is_bundle );
			if ( is_array( $level_ids ) && ! empty( $level_ids ) ) {
				foreach ( $level_ids as $lid ) {
					$level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( (int) $lid ) : null;
					if ( ! $level ) {
						continue;
					}

				// Step 3.4b: Use dynamic pricing for archive display with sale support
				$active_price_data = $this->sale_price_handler->get_active_price_for_level( (int) $lid );
				$init              = isset( $active_price_data['price'] ) ? (float) $active_price_data['price'] : ( isset( $level->initial_payment ) ? (float) $level->initial_payment : 0.0 );
				$is_on_sale        = ! empty( $active_price_data['on_sale'] );
				$regular_price     = $is_on_sale && isset( $active_price_data['regular_price'] ) ? (float) $active_price_data['regular_price'] : $init;

					$bill  = isset( $level->billing_amount ) ? (float) $level->billing_amount : 0.0;
					$cycle = isset( $level->cycle_number ) ? (int) $level->cycle_number : 0;
					$per   = isset( $level->cycle_period ) ? strtolower( (string) $level->cycle_period ) : '';

					if ( $bill > 0 && $cycle > 0 && $per ) {
						// RECURRING SUBSCRIPTION
						// Show initial payment with sale indication, plus recurring in parentheses
						$price_str = '';

						if ( $is_on_sale && $regular_price > $init ) {
							// With sale: "~~$100~~ $75 (then $50/Mo)"
							$price_str = sprintf(
								'<del style="opacity: 0.6;">%s</del> %s <span style="opacity: 0.7;">(%s %s/%s)</span>',
								$fmt( $regular_price ),
								$fmt( $init ),
								__( 'then', 'tutorpress-pmpro' ),
								$fmt( $bill ),
								$per_label( $per )
							);
						} elseif ( $init != $bill ) {
							// No sale, but initial differs from recurring: "$100 (then $50/Mo)"
							$price_str = sprintf(
								'%s <span style="opacity: 0.7;">(%s %s/%s)</span>',
								$fmt( $init ),
								__( 'then', 'tutorpress-pmpro' ),
								$fmt( $bill ),
								$per_label( $per )
							);
						} else {
							// Initial = recurring: Just "$50/Mo"
							$price_str = sprintf( '%s %s/%s', $fmt( $bill ), __( 'per', 'tutorpress-pmpro' ), $per_label( $per ) );
						}

						$amount_strings[]  = $price_str;
						$numeric_amounts[] = $init; // Use initial for price comparison
					} elseif ( $init > 0 ) {
						// ONE-TIME PURCHASE
						if ( $is_on_sale && $regular_price > $init ) {
							// With sale: "~~$100~~ $75 one-time"
							$amount_strings[] = sprintf(
								'<del style="opacity: 0.6;">%s</del> %s %s',
								$fmt( $regular_price ),
								$fmt( $init ),
								__( 'one-time', 'tutorpress-pmpro' )
							);
						} else {
							// No sale: "$100 one-time"
							$amount_strings[] = sprintf( '%s %s', $fmt( $init ), __( 'one-time', 'tutorpress-pmpro' ) );
						}
						$numeric_amounts[] = $init;
					}
				}
			}
			if ( ! empty( $amount_strings ) ) {
				if ( count( $amount_strings ) === 1 ) {
					$price = $amount_strings[0];
				} else {
					// Multiple pricing options - find the minimum and show it with "Starting at"
					$min       = min( $numeric_amounts );
					$min_index = array_search( $min, $numeric_amounts );

					// Use the full price string (with sale if applicable) for the minimum price
					$min_price_display = isset( $amount_strings[ $min_index ] ) ? $amount_strings[ $min_index ] : $fmt( $min );
					$price             = sprintf( '%s %s', __( 'Starting at', 'tutorpress-pmpro' ), $min_price_display );
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
				<span class="price tutor-fs-6 tutor-fw-bold tutor-color-black"><?php echo wp_kses_post( $price ); ?></span>
			</div>
			<div class="list-item-button">
				<a href="<?php echo esc_url( get_the_permalink( $course_id ) ); ?>" class="tutor-btn tutor-btn-outline-primary tutor-btn-md tutor-btn-block"><?php esc_html_e( 'View Details', 'tutorpress-pmpro' ); ?></a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render "View Pricing" button for membership-based courses.
	 *
	 * Displays a button that links to the individual course page where
	 * users can see membership options and pricing details.
	 *
	 * @since 1.0.0
	 * @param int $course_id Course post ID (0 = use current post).
	 * @return string HTML for the pricing button.
	 */
	public function render_membership_price_button( $course_id = 0 ) {
		// If no course_id provided, try to get from current context
		if ( ! $course_id ) {
			$course_id = get_the_ID();
		}

		// Link to the individual course page
		$course_url = get_permalink( $course_id );

		if ( ! $course_url ) {
			$course_url = home_url();
		}

		ob_start();
		?>
		<div class="tutor-d-flex tutor-align-center">
			<a href="<?php echo esc_url( $course_url ); ?>" class="tutor-btn tutor-btn-outline-primary tutor-btn-md tutor-btn-block">
				<?php esc_html_e( 'View Pricing', 'tutorpress-pmpro' ); ?>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Return PMPro minimal price string when available.
	 *
	 * Falls back to original price if engine/levels are not applicable.
	 * Handles sale prices with strikethrough formatting for dashboard display.
	 *
	 * @since 1.0.0
	 * @param mixed $price      Current price.
	 * @param int   $course_id  Course post ID.
	 * @return mixed Modified price string or original price.
	 */
	public function filter_get_tutor_course_price( $price, $course_id = 0 ) {
		$course_id = $course_id ? (int) $course_id : (int) get_the_ID();
		if ( ! $course_id ) {
			return $price;
		}
		if ( ! $this->pmpro->is_pmpro_enabled() ) {
			return $price;
		}

		// Phase 3: Detect post type (course or bundle)
		$post_type = get_post_type( $course_id );
		$is_bundle = ( 'course-bundle' === $post_type );

		// Respect free courses/bundles
		if ( get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free' ) {
			return $price;
		}

		// Phase 3: Get level IDs using bundle-specific or course-specific discovery
		$level_ids = $this->get_level_ids_for_object( $course_id, $is_bundle );

		if ( empty( $level_ids ) ) {
			return $price;
		}

		// Get currency formatting
		$cur    = $this->pmpro->get_pmpro_currency();
		$symbol = isset( $cur['currency_symbol'] ) ? $cur['currency_symbol'] : '';
		$pos    = isset( $cur['currency_position'] ) ? $cur['currency_position'] : 'left';

		$format_price = function ( $amt ) use ( $symbol, $pos ) {
			$amt = number_format_i18n( (float) $amt, 2 );
			if ( $symbol ) {
				if ( 'left_space' === $pos ) {
					return $symbol . ' ' . $amt;}
				if ( 'right' === $pos ) {
					return $amt . $symbol;}
				if ( 'right_space' === $pos ) {
					return $amt . ' ' . $symbol;}
			}
			return $symbol . $amt;
		};

		// Build price string with sale support
		$price_strings = array();
		foreach ( $level_ids as $level_id ) {
			$level = pmpro_getLevel( (int) $level_id );
			if ( ! $level ) {
				continue;
			}

		// Use dynamic pricing helper (handles sale schedule)
		$active_price_data = $this->sale_price_handler->get_active_price_for_level( (int) $level_id );
		$active_price      = isset( $active_price_data['price'] ) ? (float) $active_price_data['price'] : (float) $level->initial_payment;
		$is_on_sale        = ! empty( $active_price_data['on_sale'] );
		$regular_price     = $is_on_sale && isset( $active_price_data['regular_price'] ) ? (float) $active_price_data['regular_price'] : $active_price;

			// Format price string
			if ( $is_on_sale ) {
				// Sale price with strikethrough regular price
				$price_strings[] = '<span class="tutor-course-price"><del>' . esc_html( $format_price( $regular_price ) ) . '</del> <strong>' . esc_html( $format_price( $active_price ) ) . '</strong></span>';
			} else {
				// Regular price
				$price_strings[] = '<span class="tutor-course-price">' . esc_html( $format_price( $active_price ) ) . '</span>';
			}

			// Add subscription info if applicable
			if ( ! empty( $level->billing_amount ) && ! empty( $level->cycle_number ) ) {
				$cycle_period = strtolower( $level->cycle_period );
				$period_label = '';
				if ( 'day' === $cycle_period ) {
					$period_label = __( 'day', 'tutorpress-pmpro' );
				} elseif ( 'week' === $cycle_period ) {
					$period_label = __( 'week', 'tutorpress-pmpro' );
				} elseif ( 'month' === $cycle_period ) {
					$period_label = __( 'month', 'tutorpress-pmpro' );
				} elseif ( 'year' === $cycle_period ) {
					$period_label = __( 'year', 'tutorpress-pmpro' );
				} else {
					$period_label = $cycle_period;
				}

				$billing_amount  = (float) $level->billing_amount;
				$last_price      = array_pop( $price_strings );
				$price_strings[] = $last_price . ' <span class="tutor-course-price-period">' . esc_html( sprintf( __( 'then %s per %s', 'tutorpress-pmpro' ), $format_price( $billing_amount ), $period_label ) ) . '</span>';
			} else {
				// One-time purchase
				$last_price      = array_pop( $price_strings );
				$price_strings[] = $last_price . ' <span class="tutor-course-price-period">' . esc_html__( 'one-time', 'tutorpress-pmpro' ) . '</span>';
			}
		}

		if ( ! empty( $price_strings ) ) {
			// Return first price (or "Starting at" if multiple)
			if ( count( $price_strings ) > 1 ) {
				return '<span class="tutor-course-price-label">' . esc_html__( 'Starting at', 'tutorpress-pmpro' ) . ' </span>' . $price_strings[0];
			}
			return $price_strings[0];
		}

		return $price;
	}

	/**
	 * Content access control for single course pages.
	 *
	 * Redirects users to course page if they don't have access to course content.
	 * Checks membership access, enrollment status, and preview settings.
	 *
	 * @since 1.0.0
	 * @param int $course_id  Course post ID.
	 * @param int $content_id Content post ID (lesson, quiz, etc.).
	 * @return void
	 */
	public function pmpro_pricing_single_course( $course_id, $content_id ) {
		$course_id  = (int) $course_id;
		$content_id = (int) $content_id;

		$require = $this->pmpro_pricing( null, $course_id );

		// Phase 3, Step 3.3: Use our unified access check for consistency
		// Check if user has membership access to the course content
		$has_course_access = $this->access_checker->has_course_access( $course_id, get_current_user_id() );
		// Convert to boolean (our method may return array of required levels)
		$has_course_access = ( $has_course_access === true );

		$is_enrolled        = tutor_utils()->is_enrolled( $course_id, get_current_user_id() );
		$is_preview_enabled = tutor()->lesson_post_type === get_post_type( $content_id ) ? (bool) get_post_meta( $content_id, '_is_preview', true ) : false;

		// @since v2.0.7 If user has access to the content, allow access; otherwise redirect to course page
		if ( $has_course_access || $is_enrolled || $is_preview_enabled ) {
			return;
		}

		if ( null !== $require ) {
			wp_safe_redirect( get_permalink( $course_id ) );
			exit;
		}
	}

	/**
	 * Alter Tutor enroll box to show PMPro pricing.
	 *
	 * Displays available membership levels with pricing information
	 * based on selling option and membership-only mode settings.
	 *
	 * @since 1.0.0
	 * @param string $html      Current enrollment box HTML.
	 * @param int    $course_id Course post ID.
	 * @return string Modified enrollment box HTML.
	 */
	public function pmpro_pricing( $html, $course_id ) {
		// Phase 3: Detect post type (course or bundle)
		$post_type = get_post_type( $course_id );
		$is_bundle = ( 'course-bundle' === $post_type );

		// Phase 2 Fix: Free courses/bundles should ALWAYS show their normal "Free" enrollment box
		// regardless of membership-only mode status
		$is_free = get_post_meta( $course_id, '_tutor_course_price_type', true ) === 'free';
		if ( $is_free ) {
			return $html;
		}

		$is_enrolled = tutor_utils()->is_enrolled();
		
		// Phase 4: For bundles, check if enrolled via PMPro membership
		// Note: tutor_alter_enroll_status handles the entry box template, but pmpro_pricing filters
		// need to check enrollment directly since they run in different contexts
		if ( $is_bundle ) {
			$bundle_enrolled = $this->is_user_enrolled_in_bundle( $course_id, get_current_user_id() );
			if ( $bundle_enrolled ) {
				$is_enrolled = true;
			}
		}

		// Phase 3, Step 3.2: Use our unified access check for consistency
		// This checks full-site, category-wise, AND course/bundle-specific levels
		$has_course_access = $this->access_checker->has_course_access( $course_id, get_current_user_id() );

		// Convert to boolean (our method may return array of required levels)
		$has_course_access = ( $has_course_access === true );

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
		$membership_only_enabled = \TUTORPRESS_PMPRO\PaidMembershipsPro::tutorpress_pmpro_membership_only_enabled();

		if ( $membership_only_enabled ) {
			// Phase 2: In membership-only mode, show ALL full-site membership levels
			$required_levels = \TUTORPRESS_PMPRO\PaidMembershipsPro::pmpro_get_active_full_site_levels();

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
			// Phase 3: For courses, use Course::get_selling_option(); for bundles, read directly from meta
			if ( ! $is_bundle ) {
				// Course: use Tutor LMS helper method
				$selling_option = \TUTOR\Course::get_selling_option( $course_id );
			} else {
				// Bundle: read selling option directly from meta (Course::get_selling_option() is course-specific)
				$selling_option = get_post_meta( $course_id, 'tutor_course_selling_option', true );
			}

			if ( \TUTOR\Course::SELLING_OPTION_MEMBERSHIP === $selling_option ) {
				// For 'membership' selling option, show full-site membership levels
				$required_levels = \TUTORPRESS_PMPRO\PaidMembershipsPro::pmpro_get_active_full_site_levels();

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
				// For 'all' selling option, show ALL levels: course/bundle-specific (one-time + subscription) + full-site membership

				// Phase 3: Get ALL course/bundle-specific levels using helper method
				$course_level_ids = $this->get_level_ids_for_object( $course_id, $is_bundle );

				// Get full-site membership levels
				$full_site_level_ids = \TUTORPRESS_PMPRO\PaidMembershipsPro::pmpro_get_active_full_site_levels();

				// Combine and remove duplicates
				$all_level_ids = array_unique( array_merge( $course_level_ids, $full_site_level_ids ) );

				// Get full level objects
				$required_levels = array();
				foreach ( $all_level_ids as $level_id ) {
					$level = pmpro_getLevel( $level_id );
					if ( $level ) {
						$required_levels[] = $level;
					}
				}

				if ( empty( $required_levels ) ) {
					return $html;
				}
			} else {
				// For other selling options (one_time, subscription, both),
				// show COURSE/BUNDLE-SPECIFIC PMPro levels (exclude full-site membership levels)

				// Phase 3: Get level IDs using bundle-specific or course-specific discovery
				$level_ids = $this->get_level_ids_for_object( $course_id, $is_bundle );

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
					$level_id         = is_object( $level ) ? $level->id : $level;
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

		// Calculate active prices for all levels (Step 3.4b: Dynamic Pricing)
		// This ensures frontend displays reflect real-time sale status with zero delay
		foreach ( $required_levels as &$level ) {
			// Get active price (accounts for sale schedule)
			$active_price = $this->sale_price_handler->get_active_price_for_level( $level->id );

			// Attach active price data to level object for template use
			$level->active_price          = $active_price['price'];
			$level->regular_price_display = $active_price['regular_price'];
			$level->is_on_sale            = $active_price['on_sale'];
		}
		unset( $level ); // Break reference

		if ( empty( $required_levels ) ) {
			return $html;
		}

		// Render membership pricing template with the appropriate levels
		$level_page_id  = apply_filters( 'TUTORPRESS_PMPRO_level_page_id', pmpro_getOption( 'levels_page_id' ) );
		$level_page_url = get_the_permalink( $level_page_id );

		//phpcs:ignore
		extract( $this->pmpro->get_pmpro_currency() );

		ob_start();
		include dirname( __DIR__, 2 ) . '/views/pmpro-pricing.php';
		return ob_get_clean();
	}

	/**
	 * Filter to show "Free" for courses when user has access via membership.
	 *
	 * Applies to loop default price display when monetization is set to PMPro.
	 *
	 * @since 1.0.0
	 * @param string $price The default price text.
	 * @return string Empty if no access, "Free" if user has membership access.
	 */
	public function add_membership_required( $price ) {
		return ! ( $this->access_checker->has_course_access( get_the_ID() ) === true ) ? '' : __( 'Free', 'pmpro-tutorlms' );
	}

	/**
	 * Filter add-to-cart HTML to show appropriate message based on membership access.
	 *
	 * If user has membership access, show default HTML. Otherwise, apply custom
	 * enrollment message filter.
	 *
	 * @since 1.0.0
	 * @param string $html The add-to-cart HTML.
	 * @return string Modified HTML based on access.
	 */
	public function tutor_course_add_to_cart( $html ) {
		$access_require = $this->access_checker->has_course_access( get_the_ID() );
		if ( true === $access_require ) {
			// If has membership access, then no need membership require message.
			return $html;
		}

		return apply_filters( 'tutor_enrol_no_membership_msg', '' );
	}

	/**
	 * Filter course price HTML when monetization is set to PMPro.
	 *
	 * Returns empty string to hide default Tutor price display when using PMPro.
	 *
	 * @since 1.0.0
	 * @param string $html The course price HTML.
	 * @return string Empty string if PMPro monetization, original HTML otherwise.
	 */
	public function tutor_course_price( $html ) {
		return 'pmpro' === get_tutor_option( 'monetize_by' ) ? '' : $html;
	}

	/**
	 * Filter course expiration validity text based on membership level.
	 *
	 * Replaces default Tutor validity with membership-based expiration periods.
	 * Shows "Membership Wise" for non-enrolled users, or the actual level's
	 * expiration/cycle period for enrolled users.
	 *
	 * @since 1.0.0
	 * @param string $validity   The default validity text.
	 * @param int    $course_id  The course ID.
	 * @return string Modified validity text based on membership.
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
		$has_access  = $this->access_checker->has_course_access( $course_id );
		$user_levels = pmpro_getMembershipLevelsForUser( $user_id );
		$is_enrolled = tutor_utils()->is_enrolled( $course_id, $user_id );

		if ( false === $is_enrolled ) {
			// If course has levels or user has access via membership
			if ( is_array( $has_access ) && count( $has_access ) ) {
				$validity = __( 'Membership Wise', 'tutorpress-pmpro' );
			}
			// User not enrolled but just paid and will enroll
			if ( true === $has_access ) {
				$validity = __( 'Membership Wise', 'tutorpress-pmpro' );
			}
		} else {
			// User is enrolled - check their membership level's expiration
			$required_levels = is_array( $has_access ) ? $has_access : array();
			$user_has_level  = null;

			// Check if user has a specific level for this course
			if ( is_array( $required_levels ) && count( $required_levels ) ) {
				foreach ( $required_levels as $key => $req_level ) {
					$level_id = $req_level->id ?? 0;
					if ( is_array( $user_levels ) && count( $user_levels ) && isset( $user_levels[ $key ] ) && $user_levels[ $key ]->id === $level_id ) {
						$user_has_level = $user_levels[ $key ];
					}
				}
			}

			// If user has a matching level, use its expiration
			if ( ! is_null( $user_has_level ) && is_object( $user_has_level ) ) {
				if ( $user_has_level->expiration_number ) {
					$validity = $user_has_level->expiration_number . ' ' . $user_has_level->expiration_period;
				} else {
					$validity = $user_has_level->cycle_number . ' ' . $user_has_level->cycle_period;
				}
			}

			/**
			 * If user doesn't have category-wise membership,
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

		// If membership has no validity then set lifetime
		if ( 0 == $validity || '' === $validity ) {
			$validity = __( 'Lifetime', 'tutorpress-pmpro' );
		}
		return $validity;
	}

	/**
	 * Override PMPro content restriction for course single pages only.
	 *
	 * Allows full course page content to display so students can see:
	 * - Course description
	 * - Pricing information
	 * - Enrollment options
	 *
	 * Course content (lessons, quizzes, assignments) remains restricted.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed  $content_filter Content filter override (false = use PMPro default).
	 * @param string $content        Post content.
	 * @param bool   $hasaccess      Whether user has membership access.
	 * @return mixed Content filter override or false to let PMPro handle it.
	 */
	public function allow_course_page_content( $content_filter, $content, $hasaccess ) {
		// Only override for course single pages
		if ( ! function_exists( 'is_single_course' ) || ! is_single_course() ) {
			return $content_filter; // Return false to let PMPro handle it
		}

		// Verify this is actually a course post type
		global $post;
		if ( empty( $post ) || get_post_type( $post->ID ) !== tutor()->course_post_type ) {
			return $content_filter;
		}

		// For course pages, always return full content (bypass PMPro restriction)
		// Course content (lessons/quizzes) will still be restricted because they're different post types
		return $content;
	}

	/**
	 * Check if user is enrolled in a bundle via PMPro membership.
	 *
	 * Phase 4: Helper method to verify bundle enrollment status.
	 * Checks if user is enrolled in all courses within the bundle with PMPro membership flag.
	 *
	 * Handles both new and retroactive enrollments:
	 * - New enrollments: checks for _tutorpress_pmpro_membership_enrollment meta
	 * - Retroactive enrollments: checks for _tutor_pmpro_level_id meta (set on checkout)
	 *
	 * @since 1.0.0
	 * @param int $bundle_id Bundle post ID.
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return bool True if enrolled in bundle via PMPro membership, false otherwise.
	 */
	private function is_user_enrolled_in_bundle( $bundle_id, $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id || ! function_exists( 'tutor_utils' ) ) {
			return false;
		}

		// Bundles don't exist without BundleModel
		if ( ! class_exists( 'TutorPro\CourseBundle\Models\BundleModel' ) ) {
			return false;
		}

		$course_ids = \TutorPro\CourseBundle\Models\BundleModel::get_bundle_course_ids( $bundle_id );

		if ( empty( $course_ids ) ) {
			return false;
		}

		// User must be enrolled in at least the first course to have access to bundle
		// (Phase 4 enrollment handler enrolls in ALL courses simultaneously, so check first as shortcut)
		foreach ( $course_ids as $course_id ) {
			$enrollment_result = tutor_utils()->is_enrolled( $course_id, $user_id );
			
			// Handle both object and ID returns from is_enrolled()
			$enrollment_id = false;
			if ( $enrollment_result ) {
				if ( is_object( $enrollment_result ) && isset( $enrollment_result->ID ) ) {
					$enrollment_id = (int) $enrollment_result->ID;
				} elseif ( is_numeric( $enrollment_result ) ) {
					$enrollment_id = (int) $enrollment_result;
				}
			}
			
			if ( $enrollment_id ) {
				// Verify it's a PMPro membership enrollment (not individual purchase)
				// Check both new flag (_tutorpress_pmpro_membership_enrollment) and checkout meta (_tutor_pmpro_level_id)
				$meta1 = get_post_meta( $enrollment_id, '_tutorpress_pmpro_membership_enrollment', true );
				$meta2 = get_post_meta( $enrollment_id, '_tutor_pmpro_level_id', true );
				
				if ( $meta1 || $meta2 ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Get detailed bundle enrollment status.
	 *
	 * Phase 4: Helper method to retrieve enrollment count and status for bundles.
	 * Used to determine if user has full access to all courses in the bundle.
	 *
	 * Handles both new and retroactive enrollments:
	 * - New enrollments: checks for _tutorpress_pmpro_membership_enrollment meta
	 * - Retroactive enrollments: checks for _tutor_pmpro_level_id meta (set on checkout)
	 *
	 * @since 1.0.0
	 * @param int $bundle_id Bundle post ID.
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return array {
	 *     @type bool $is_enrolled True if enrolled in all bundle courses via PMPro.
	 *     @type int $courses_enrolled Number of courses user is enrolled in.
	 *     @type int $courses_total Total courses in bundle.
	 * }
	 */
	private function get_bundle_enrollment_status( $bundle_id, $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		$return = array(
			'is_enrolled' => false,
			'courses_enrolled' => 0,
			'courses_total' => 0,
		);

		if ( ! $user_id || ! class_exists( 'TutorPro\CourseBundle\Models\BundleModel' ) ) {
			return $return;
		}

		$course_ids = \TutorPro\CourseBundle\Models\BundleModel::get_bundle_course_ids( $bundle_id );
		$return['courses_total'] = count( $course_ids );

		if ( empty( $course_ids ) ) {
			return $return;
		}

		$enrolled_count = 0;
		$has_pmpro_enrollment = false;

		foreach ( $course_ids as $course_id ) {
			$enrollment_result = tutor_utils()->is_enrolled( $course_id, $user_id );
			
			// Handle both object and ID returns from is_enrolled()
			$enrollment_id = false;
			if ( $enrollment_result ) {
				if ( is_object( $enrollment_result ) && isset( $enrollment_result->ID ) ) {
					$enrollment_id = (int) $enrollment_result->ID;
				} elseif ( is_numeric( $enrollment_result ) ) {
					$enrollment_id = (int) $enrollment_result;
				}
			}
			
			if ( $enrollment_id ) {
				$enrolled_count++;
				// Check if it's PMPro membership enrollment
				// Check both new flag (_tutorpress_pmpro_membership_enrollment) and checkout meta (_tutor_pmpro_level_id)
				if ( get_post_meta( $enrollment_id, '_tutorpress_pmpro_membership_enrollment', true ) ||
					 get_post_meta( $enrollment_id, '_tutor_pmpro_level_id', true ) ) {
					$has_pmpro_enrollment = true;
				}
			}
		}

		$return['courses_enrolled'] = $enrolled_count;
		$return['is_enrolled'] = ( $enrolled_count === $return['courses_total'] && $return['courses_total'] > 0 && $has_pmpro_enrollment );

		return $return;
	}

	/**
	 * Get bundle enrollment date for display.
	 *
	 * Phase 4: Helper method to retrieve when user enrolled in the bundle.
	 * Returns the earliest enrollment date among all courses (when bundle access began).
	 *
	 * Handles both new and retroactive enrollments:
	 * - New enrollments: uses enrollment post_date
	 * - Retroactive enrollments: uses enrollment post_date (same for both)
	 *
	 * @since 1.0.0
	 * @param int $bundle_id Bundle post ID.
	 * @param int|null $user_id User ID (defaults to current user).
	 * @return string|false Formatted enrollment date or false if not enrolled.
	 */
	private function get_bundle_enrollment_date( $bundle_id, $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id || ! class_exists( 'TutorPro\CourseBundle\Models\BundleModel' ) ) {
			return false;
		}

		$course_ids = \TutorPro\CourseBundle\Models\BundleModel::get_bundle_course_ids( $bundle_id );

		if ( empty( $course_ids ) ) {
			return false;
		}

		$enrollment_dates = array();

		foreach ( $course_ids as $course_id ) {
			$enrollment_result = tutor_utils()->is_enrolled( $course_id, $user_id );
			
			// Handle both object and ID returns from is_enrolled()
			$enrollment_id = false;
			if ( $enrollment_result ) {
				if ( is_object( $enrollment_result ) && isset( $enrollment_result->ID ) ) {
					$enrollment_id = (int) $enrollment_result->ID;
				} elseif ( is_numeric( $enrollment_result ) ) {
					$enrollment_id = (int) $enrollment_result;
				}
			}
			
			if ( $enrollment_id ) {
				// Verify it's a PMPro enrollment before adding date
				if ( get_post_meta( $enrollment_id, '_tutorpress_pmpro_membership_enrollment', true ) ||
					 get_post_meta( $enrollment_id, '_tutor_pmpro_level_id', true ) ) {
					// Get enrollment post date
					$post = get_post( $enrollment_id );
					if ( $post ) {
						$enrollment_dates[] = strtotime( $post->post_date );
					}
				}
			}
		}

		if ( empty( $enrollment_dates ) ) {
			return false;
		}

		// Return earliest enrollment date (when user got access to bundle)
		$earliest = min( $enrollment_dates );
		return date_i18n( get_option( 'date_format' ), $earliest );
	}

	/**
	 * Get PMPro level IDs for a course or bundle.
	 *
	 * Phase 3: Helper method to discover PMPro levels associated with a course or bundle.
	 * Uses bundle-specific meta key (tutorpress_bundle_id) for bundles and course-specific
	 * meta key (tutorpress_course_id) for courses.
	 *
	 * @since 1.0.0
	 * @param int  $object_id Course or bundle post ID.
	 * @param bool $is_bundle Whether the object is a bundle (true) or course (false).
	 * @return array Array of PMPro level IDs.
	 */
	private function get_level_ids_for_object( $object_id, $is_bundle = false ) {
		global $wpdb;
		$level_ids = array();

		// Primary: pmpro_memberships_pages table (works for both courses and bundles)
		if ( isset( $wpdb->pmpro_memberships_pages ) ) {
			$level_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d",
					$object_id
				)
			);
		}

		// Secondary: post meta (_tutorpress_pmpro_levels) - same for both courses and bundles
		$meta_ids = get_post_meta( $object_id, '_tutorpress_pmpro_levels', true );
		if ( is_array( $meta_ids ) && ! empty( $meta_ids ) ) {
			$level_ids = array_merge( $level_ids, $meta_ids );
		}

		// Tertiary: reverse meta lookup
		// For bundles: use tutorpress_bundle_id
		// For courses: use tutorpress_course_id
		if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
			$meta_key = $is_bundle ? 'tutorpress_bundle_id' : 'tutorpress_course_id';
			$reverse_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pmpro_membership_level_id FROM {$wpdb->pmpro_membership_levelmeta} WHERE meta_key = %s AND meta_value = %s",
					$meta_key,
					(string) $object_id
				)
			);
			$level_ids = array_merge( $level_ids, $reverse_ids );
		}

		// Remove duplicates and ensure integers
		$level_ids = array_map( 'intval', array_unique( $level_ids ) );
		$level_ids = array_filter( $level_ids ); // Remove zeros

		return $level_ids;
	}
}

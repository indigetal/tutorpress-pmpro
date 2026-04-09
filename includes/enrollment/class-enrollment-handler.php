<?php
/**
 * Enrollment Handler
 *
 * Manages enrollment synchronization between PMPro memberships and Tutor LMS courses.
 * Handles enrollment when users purchase memberships, level changes, cancellations, and refunds.
 *
 * @package TutorPress_PMPro
 * @subpackage Enrollment
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Enrollment;

/**
 * Enrollment Handler class.
 *
 * Service class responsible for keeping Tutor LMS course enrollments synchronized
 * with PMPro membership level changes. Handles enrollment/unenrollment based on:
 * - Membership purchases (checkout)
 * - Level changes (upgrades, downgrades, cancellations)
 * - Order refunds
 * - Manual user enrollments (tracking)
 *
 * @since 1.0.0
 */
class Enrollment_Handler {

	/**
	 * Access checker service instance.
	 *
	 * @since 1.0.0
	 * @var \TUTORPRESS_PMPRO\Access\Access_Checker
	 */
	private $access_checker;

	/**
	 * Pending PMPro level IDs for manual one-time enrollments (student_course => level_id).
	 *
	 * Enrollment posts do not exist until after `tutor_manual_enrollment` returns; Step 3 records meta on `tutor_after_enrolled`.
	 *
	 * @since 1.0.0
	 * @var array<string, int>
	 */
	private $pending_manual_meta = array();

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param \TUTORPRESS_PMPRO\Access\Access_Checker $access_checker Access checker instance.
	 */
	public function __construct( $access_checker ) {
		$this->access_checker = $access_checker;
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks for enrollment synchronization.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// Enroll after successful checkout
		add_action( 'pmpro_after_checkout', array( $this, 'pmpro_after_checkout_enroll' ), 10, 2 );
		
		// Sync on membership level changes
		add_action( 'pmpro_after_change_membership_level', array( $this, 'remove_course_access' ), 10, 3 );
		add_action( 'pmpro_after_change_membership_level', array( $this, 'pmpro_after_change_membership_level' ), 10, 3 );
		add_action( 'pmpro_after_all_membership_level_changes', array( $this, 'pmpro_after_all_membership_level_changes' ), 10, 1 );
		
		// Unenroll on refunded orders
		add_action( 'pmpro_order_status_refunded', array( $this, 'pmpro_order_status_refunded' ), 10, 2 );

		// Manual enrollment (admin): PMPro subscription + one-time course paths
		add_filter( 'tutor_manual_enrollment', array( $this, 'handle_manual_pmpro_enrollment' ), 10, 4 );

		// Provide plan info for PMPro levels so student search AJAX doesn't bail
		add_filter( 'tutor_get_plan_info', array( $this, 'provide_pmpro_plan_info' ), 10, 2 );
		
		// Track manual enrollments (Phase 11, Substep 2)
		add_action( 'tutor_after_enrolled', array( $this, 'handle_after_enrollment_completed' ), 10, 3 );

		// Redirect tutor-subscriptions to enrollments when PMPro is the monetization engine.
		// Tutor's enrollment React app redirects to tutor-subscriptions after subscription
		// enrollment, but that page only exists when Tutor's native subscription addon is active.
		add_action( 'admin_menu', array( $this, 'maybe_register_subscriptions_redirect' ), 999 );
	}

	/**
	 * Enroll user immediately after successful PMPro checkout.
	 *
	 * @since 1.0.0
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

		$course_data = $this->get_courses_for_levels( $level_ids );
		// Filter courses: exclude free/public for regular courses, but keep ALL bundle courses
		$courses = $this->filter_courses_by_pricing( $course_data );

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
					
					// Phase 4: Mark as PMPro membership enrollment for display logic
					update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_enrollment', 1 );
					update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_level_id', $order_level_id );
					
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[TP-PMPRO] pmpro_after_checkout_enroll enrolled user=' . $user_id . ' course=' . $course_id . ' enrollment_id=' . $enrolled_id . ' level=' . $order_level_id );
					}
				}
			}
		}
	}

	/**
	 * Manual bulk enrollment under PMPro: subscription (plan = level id) or one-time (course ids + level grant).
	 *
	 * @param array  $enrollment_data  Keys: failed_enrollment_list, total_enrolled_students.
	 * @param array  $object_ids       Subscription: PMPro level id at [0]. One-time: course/bundle post ids.
	 * @param array  $student_ids      User ids to enroll.
	 * @param string $payment_status   Tutor payment status constant.
	 * @return array
	 */
	public function handle_manual_pmpro_enrollment( $enrollment_data, $object_ids, $student_ids, $payment_status ) {
		if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro( true ) ) {
			return $enrollment_data;
		}

		$order_type = '';
		if ( class_exists( '\Tutor\Helpers\Input' ) ) {
			$order_type = (string) \Tutor\Helpers\Input::post( 'order_type' );
		} elseif ( isset( $_POST['order_type'] ) ) {
			$order_type = sanitize_text_field( wp_unslash( $_POST['order_type'] ) );
		}

		$failed = isset( $enrollment_data['failed_enrollment_list'] ) && is_array( $enrollment_data['failed_enrollment_list'] )
			? $enrollment_data['failed_enrollment_list']
			: array();
		$total  = isset( $enrollment_data['total_enrolled_students'] ) ? (int) $enrollment_data['total_enrolled_students'] : 0;

		// (a) Subscription: object_ids[0] is PMPro level id; resolve course via level meta.
		// Always short-circuit when order_type is subscription so core never treats level id as post id.
		if ( 'subscription' === $order_type ) {
			$level_id = isset( $object_ids[0] ) ? (int) $object_ids[0] : 0;

			if ( ! $level_id || ! function_exists( 'pmpro_changeMembershipLevel' ) ) {
				foreach ( $student_ids as $student_id ) {
					$failed[] = $this->get_manual_failed_user_data( (int) $student_id );
				}
				return array(
					'message'                    => __( 'Enrollment done for selected students', 'tutorpress-pmpro' ),
					'is_subscription_enrollment' => true,
					'failed_enrollment_list'     => $failed,
					'total_enrolled_students'    => $total,
				);
			}

			$course_id         = (int) get_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', true );
			$course_post_type  = ( function_exists( 'tutor' ) && isset( tutor()->course_post_type ) ) ? tutor()->course_post_type : 'courses';
			$actual_post_type  = get_post_type( $course_id );

			if ( ! $course_id || $course_post_type !== $actual_post_type ) {
				foreach ( $student_ids as $student_id ) {
					$failed[] = $this->get_manual_failed_user_data( (int) $student_id );
				}
				return array(
					'message'                    => __( 'Enrollment done for selected students', 'tutorpress-pmpro' ),
					'is_subscription_enrollment' => true,
					'failed_enrollment_list'     => $failed,
					'total_enrolled_students'    => $total,
				);
			}

			foreach ( $student_ids as $student_id ) {
				$student_id = (int) $student_id;
				if ( ! $student_id ) {
					continue;
				}

				$already_enrolled = tutor_utils()->is_enrolled( $course_id, $student_id, false );

				if ( $already_enrolled ) {
					$failed[] = $this->get_manual_failed_user_data( $student_id );
					continue;
				}

				$enroll_id = tutor_utils()->do_enroll( $course_id, 0, $student_id );

				if ( $enroll_id ) {
					tutor_utils()->course_enrol_status_change( $enroll_id, 'completed' );
					update_post_meta( $enroll_id, '_tutor_pmpro_level_id', $level_id );
					update_post_meta( $enroll_id, '_tutorpress_pmpro_membership_level_id', $level_id );
					update_post_meta( $enroll_id, '_tutorpress_pmpro_membership_enrollment', 1 );
					update_post_meta( $enroll_id, '_tutorpress_pmpro_manual_enrollment', 1 );
					pmpro_changeMembershipLevel( $level_id, $student_id );
					++$total;
				} else {
					$failed[] = $this->get_manual_failed_user_data( $student_id );
				}
			}

			return array(
				'message'                    => __( 'Enrollment done for selected students', 'tutorpress-pmpro' ),
				'is_subscription_enrollment' => true,
				'failed_enrollment_list'     => $failed,
				'total_enrolled_students'    => $total,
			);
		}

		// (b) One-time: grant first one-time PMPro level per course; Tutor core runs do_enroll() afterward.
		add_filter(
			'tutor_enroll_data',
			function ( $data ) {
				$data['post_status'] = 'completed';
				return $data;
			},
			10
		);

		if ( ! is_array( $object_ids ) || empty( $object_ids ) || ! is_array( $student_ids ) ) {
			return $enrollment_data;
		}

		foreach ( $object_ids as $object_id ) {
			$course_id = (int) $object_id;
			if ( ! $course_id ) {
				continue;
			}

			$one_time_level_id = $this->get_first_one_time_pmpro_level_for_course( $course_id );
			if ( ! $one_time_level_id ) {
				continue;
			}

			foreach ( $student_ids as $student_id ) {
				$student_id = (int) $student_id;
				if ( ! $student_id || ! function_exists( 'pmpro_changeMembershipLevel' ) ) {
					continue;
				}
				// Set pending meta BEFORE the level change so the synchronous
				// pmpro_after_change_membership_level handler can see it and
				// skip its own do_enroll() for this user+course pair.
				$this->pending_manual_meta[ "{$student_id}_{$course_id}" ] = $one_time_level_id;
				pmpro_changeMembershipLevel( $one_time_level_id, $student_id );
			}
		}

		return $enrollment_data;
	}

	/**
	 * First PMPro level on the course that is one-time (billing_amount <= 0 and cycle_number === 0).
	 *
	 * @param int $course_id Course post ID.
	 * @return int Level id or 0.
	 */
	private function get_first_one_time_pmpro_level_for_course( $course_id ) {
		$course_id = (int) $course_id;
		if ( ! $course_id || ! function_exists( 'pmpro_getLevel' ) ) {
			return 0;
		}

		$mapped = get_post_meta( $course_id, '_tutorpress_pmpro_levels', true );
		if ( empty( $mapped ) || ! is_array( $mapped ) ) {
			return 0;
		}

		foreach ( $mapped as $v ) {
			$lid = (int) $v;
			if ( ! $lid ) {
				continue;
			}
			$level = pmpro_getLevel( $lid );
			if ( ! $level ) {
				continue;
			}
			$billing = isset( $level->billing_amount ) ? (float) $level->billing_amount : 0.0;
			$cycle   = isset( $level->cycle_number ) ? (int) $level->cycle_number : 0;
			if ( $billing <= 0 && 0 === $cycle ) {
				return $lid;
			}
		}

		return 0;
	}

	/**
	 * Shape failed student row like Tutor Enrollments::get_failed_user_data().
	 *
	 * @param int $student_id User ID.
	 * @return array{first_name: string, last_name: string, email: string}
	 */
	private function get_manual_failed_user_data( $student_id ) {
		$user = get_userdata( $student_id );
		if ( ! $user ) {
			return array(
				'first_name' => '',
				'last_name'  => '',
				'email'      => '',
			);
		}

		return array(
			'first_name' => $user->user_login,
			'last_name'  => '',
			'email'      => $user->user_email,
		);
	}

	/**
	 * Return a plan-info object for a PMPro level so the student-search AJAX
	 * endpoint (`ajax_get_unenrolled_users`) doesn't bail with "Invalid plan selected".
	 *
	 * Tutor's `Enrollments.php:592` calls `apply_filters('tutor_get_plan_info', null, $object_id)`
	 * when `order_type === 'subscription'`. Under native Tutor the subscription addon handles
	 * this; under PMPro nobody does, so `$plan` stays null → bad-request. We provide a
	 * lightweight plan-shaped object with `is_membership_plan = false` so the endpoint
	 * falls through to using the `course_id` POST field for enrollment-status checks.
	 *
	 * @param object|null $plan    Current plan (null if nobody handled the filter yet).
	 * @param int         $plan_id Plan / PMPro level ID.
	 * @return object|null
	 */
	public function provide_pmpro_plan_info( $plan, $plan_id ) {
		if ( null !== $plan ) {
			return $plan;
		}

		if ( ! function_exists( 'tutor_utils' ) || ! tutor_utils()->has_pmpro( true ) ) {
			return $plan;
		}

		if ( ! function_exists( 'pmpro_getLevel' ) ) {
			return $plan;
		}

		$level = pmpro_getLevel( (int) $plan_id );
		if ( ! $level ) {
			return $plan;
		}

		return (object) array(
			'id'                 => (int) $level->id,
			'plan_name'          => isset( $level->name ) ? $level->name : '',
			'regular_price'      => isset( $level->initial_payment ) ? (float) $level->initial_payment : 0.0,
			'is_membership_plan' => false,
		);
	}

	/**
	 * Register a hidden admin page for tutor-subscriptions that redirects to enrollments.
	 *
	 * Tutor's enrollment React app hardcodes a redirect to tutor-subscriptions after
	 * subscription-type enrollment. That admin page only exists when Tutor's own
	 * subscription addon is active. Under PMPro monetization it does not exist,
	 * so the user would see "Sorry, you are not allowed to access this page."
	 *
	 * We register the page at priority 999 (after all other menus) so we only add
	 * it when no other plugin has already registered it.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_register_subscriptions_redirect() {
		global $submenu, $menu, $_registered_pages;

		$page_hook = get_plugin_page_hookname( 'tutor-subscriptions', '' );
		if ( ! empty( $_registered_pages[ $page_hook ] ) ) {
			return;
		}

		// Register a hidden page (no menu entry) that redirects to enrollments.
		add_submenu_page(
			null,
			'',
			'',
			'manage_options',
			'tutor-subscriptions',
			array( $this, 'handle_subscriptions_redirect' )
		);
	}

	/**
	 * Callback for the hidden tutor-subscriptions page — immediately redirect.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_subscriptions_redirect() {
		wp_safe_redirect( admin_url( 'admin.php?page=enrollments' ) );
		exit;
	}

	/**
	 * Handle immediate enrollment sync when a single membership level changes.
	 * This fires immediately when levels are changed (e.g., admin assignment).
	 *
	 * @since 1.0.0
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
		$current_course_data = $this->get_courses_for_levels( $current_level_ids );
		$current_courses = $this->filter_courses_by_pricing( $current_course_data );

		// If level was cancelled (level_id = 0), unenroll from courses that required the cancelled level
		if ( $level_id == 0 && $cancel_level ) {
			$cancelled_course_data = $this->get_courses_for_levels( array( $cancel_level ) );
			$cancelled_courses = $this->filter_courses_by_pricing( $cancelled_course_data );

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
			$new_course_data = $this->get_courses_for_levels( array( $level_id ) );
			$new_courses = $this->filter_courses_by_pricing( $new_course_data );

			foreach ( $new_courses as $course_id ) {
				// Skip courses awaiting manual enrollment — the Tutor enrollment loop
				// will call do_enroll() and the tutor_after_enrolled handler will
				// consume the pending meta entry.
				if ( isset( $this->pending_manual_meta[ "{$user_id}_{$course_id}" ] ) ) {
					continue;
				}

				if ( ! tutor_utils()->is_enrolled( $course_id, $user_id ) ) {
					$enrolled_id = tutor_utils()->do_enroll( $course_id, 0, $user_id );
					if ( $enrolled_id ) {
						tutor_utils()->course_enrol_status_change( $enrolled_id, 'completed' );
						
						update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_enrollment', 1 );
						update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_level_id', $level_id );
						
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( '[TP-PMPRO] pmpro_after_change_membership_level enrolled user=' . $user_id . ' course=' . $course_id . ' enrollment_id=' . $enrolled_id . ' level=' . $level_id );
						}
					}
				}
			}
		}
	}

	/**
	 * When users change PMPro levels, enroll/unenroll them in mapped Tutor courses (non-public only).
	 * Mirrors logic from PMPro Courses addon for Tutor LMS.
	 *
	 * @since 1.0.0
	 *
	 * @param array $pmpro_old_user_levels Map of user_id => array of old level objects.
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

			$current_course_data = ! empty( $current_level_ids ) ? $this->get_courses_for_levels( $current_level_ids ) : array( 'all_courses' => array(), 'bundle_courses' => array(), 'category_courses' => array(), 'regular_courses' => array() );
			$old_course_data     = ! empty( $old_level_ids ) ? $this->get_courses_for_levels( $old_level_ids ) : array( 'all_courses' => array(), 'bundle_courses' => array(), 'category_courses' => array(), 'regular_courses' => array() );

			if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
				error_log( sprintf(
					'[TP-PMPRO] User %d: old_courses=%s, current_courses=%s',
					$user_id,
					! empty( $old_course_data['all_courses'] ) ? implode( ',', $old_course_data['all_courses'] ) : '',
					! empty( $current_course_data['all_courses'] ) ? implode( ',', $current_course_data['all_courses'] ) : ''
				) );
			}

			// Filter courses: exclude free/public for regular courses; keep all bundle and category-wise courses
			$current_courses = $this->filter_courses_by_pricing( $current_course_data );
			$old_courses = $this->filter_courses_by_pricing( $old_course_data );

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
						
						// Phase 4: Mark as PMPro membership enrollment for display logic
						// Get the first current level ID (user's active level)
						$level_id = ! empty( $current_level_ids ) ? $current_level_ids[0] : 0;
						if ( $level_id > 0 ) {
							update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_enrollment', 1 );
							update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_level_id', $level_id );
							
							if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
								error_log( '[TP-PMPRO] pmpro_after_all_membership_level_changes enrolled user=' . $user_id . ' course=' . $course_id . ' enrollment_id=' . $enrolled_id . ' level=' . $level_id );
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Handle PMPro order refunds → unenroll if the refunded level is the only access path.
	 *
	 * @since 1.0.0
	 *
	 * @param object $order      PMPro MemberOrder instance.
	 * @param string $old_status Previous status.
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
		$refunded_course_data = $this->get_courses_for_levels( array( $level_id ) );
		$refunded_courses = $this->filter_courses_by_pricing( $refunded_course_data );

		// Other active levels for this user (excluding refunded level)
		$current_levels    = pmpro_getMembershipLevelsForUser( $user_id );
		$current_level_ids = ! empty( $current_levels ) ? wp_list_pluck( $current_levels, 'ID' ) : array();
		$other_level_ids   = array_values( array_diff( array_map( 'intval', $current_level_ids ), array( $level_id ) ) );
		$other_course_data = $this->get_courses_for_levels( $other_level_ids );
		$other_courses = $this->filter_courses_by_pricing( $other_course_data );

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
	 * Remove course access when membership level is cancelled.
	 *
	 * @since 1.0.0
	 *
	 * @param int $level_id    New level ID (0 if cancelling).
	 * @param int $user_id     User ID.
	 * @param int $cancel_id   Level being cancelled.
	 * @return void
	 */
	public function remove_course_access( $level_id, $user_id, $cancel_id ) {
		if ( ! $cancel_id ) {
			return;
		}

		$model = get_pmpro_membership_level_meta( $cancel_id, 'TUTORPRESS_PMPRO_membership_model', true );

		$all_models = array( 'full_website_membership', 'category_wise_membership' );
		if ( ! in_array( $model, $all_models, true ) ) {
			return;
		}

		$enrolled_courses = array();

		if ( 'full_website_membership' === $model ) {
			$enrolled_courses = tutor_utils()->get_enrolled_courses_by_user( $user_id );
		}

		if ( 'category_wise_membership' === $model ) {
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
	 * Filter courses based on pricing type and enrollment source.
	 * 
	 * For regular course-specific levels, we filter out free/public courses since they should
	 * be accessible to everyone without a membership. For bundle and category-wise membership
	 * courses, we keep ALL courses (including free ones) because access was purchased via the
	 * bundle or category membership.
	 *
	 * @since 1.0.0
	 *
	 * @param array $course_data Course data from get_courses_for_levels() with keys:
	 *                             - 'all_courses': All course IDs
	 *                             - 'bundle_courses': Courses from bundles
	 *                             - 'category_courses': Courses from category-wise levels
	 *                             - 'regular_courses': Course-specific level enrollments
	 * @return array Filtered array of course IDs to enroll/unenroll.
	 */
	private function filter_courses_by_pricing( $course_data ) {
		$regular_courses  = isset( $course_data['regular_courses'] ) ? $course_data['regular_courses'] : array();
		$bundle_courses   = isset( $course_data['bundle_courses'] ) ? $course_data['bundle_courses'] : array();
		// Populated by get_courses_for_levels for levels with TUTORPRESS_PMPRO_membership_model = category_wise_membership.
		$category_courses = isset( $course_data['category_courses'] ) ? $course_data['category_courses'] : array();

		// Filter regular courses - exclude free/public courses since users get automatic access
		$filtered_regular = array_values( array_filter( $regular_courses, function ( $cid ) {
			return get_post_meta( $cid, '_tutor_is_public_course', true ) !== 'yes'
				&& get_post_meta( $cid, '_tutor_course_price_type', true ) !== 'free';
		} ) );

		// Bundle and category-wise courses - keep ALL courses including free ones

		return array_values( array_unique( array_merge( $filtered_regular, $bundle_courses, $category_courses ) ) );
	}

	/**
	 * Get all courses mapped to a set of PMPro membership levels.
	 *
	 * Uses lookup strategies:
	 * 1. pmpro_memberships_pages table (standard PMPro restriction)
	 * 2. Level meta reverse lookup (course-specific levels via tutorpress_course_id)
	 * 3. Bundle expansion (bundle-specific levels via tutorpress_bundle_id)
	 * 4. Category-wise membership (published courses in level-assigned course categories)
	 *
	 * @since 1.0.0
	 *
	 * @param int|array|object $level_ids Level ID(s) or level object(s).
	 * @param bool             $include_bundle_courses Whether to include courses from bundles (default true).
	 * @return array Associative array with 'all_courses', 'bundle_courses', 'category_courses', and 'regular_courses' keys.
	 */
	private function get_courses_for_levels( $level_ids, $include_bundle_courses = true ) {
		global $wpdb;

		if ( is_object( $level_ids ) ) {
			$level_ids = $level_ids->ID;
		}

		if ( ! is_array( $level_ids ) ) {
			$level_ids = array( $level_ids );
		}

		$level_ids = array_values( array_filter( array_map( 'absint', $level_ids ) ) );
		if ( empty( $level_ids ) ) {
			return array(
				'all_courses'       => array(),
				'bundle_courses'    => array(),
				'category_courses'  => array(),
				'regular_courses'   => array(),
			);
		}

		$course_ids = array();
		$bundle_course_ids   = array(); // Track which courses come from bundles
		$category_course_ids = array(); // Track which courses come from category-wise levels

		// Primary: pmpro_memberships_pages table (courses only)
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

		// Phase 4: Bundle support - look for bundle-linked levels and expand to bundle courses
		// This handles levels that are linked via tutorpress_bundle_id meta
		if ( $include_bundle_courses && isset( $wpdb->pmpro_membership_levelmeta ) && class_exists( 'TutorPro\CourseBundle\Models\BundleModel' ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $level_ids ), '%d' ) );
			$sql = "
				SELECT DISTINCT CAST(meta_value AS UNSIGNED) as bundle_id
				FROM {$wpdb->pmpro_membership_levelmeta}
				WHERE meta_key = 'tutorpress_bundle_id'
				AND pmpro_membership_level_id IN ( {$placeholders} )
				AND CAST(meta_value AS UNSIGNED) > 0
			";
			$params = array_merge( array( $sql ), $level_ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- dynamic placeholders handled via call_user_func_array
			$bundle_ids = $wpdb->get_col( call_user_func_array( array( $wpdb, 'prepare' ), $params ) );
			
			if ( is_array( $bundle_ids ) && ! empty( $bundle_ids ) ) {
				foreach ( $bundle_ids as $bundle_id ) {
					$bundle_id = (int) $bundle_id;
					if ( $bundle_id > 0 ) {
						// Get all courses in this bundle
						$current_bundle_courses = \TutorPro\CourseBundle\Models\BundleModel::get_bundle_course_ids( $bundle_id );
						
						if ( is_array( $current_bundle_courses ) && ! empty( $current_bundle_courses ) ) {
							$current_bundle_courses = array_map( 'intval', $current_bundle_courses );
							$course_ids = array_merge( $course_ids, $current_bundle_courses );
							$bundle_course_ids = array_merge( $bundle_course_ids, $current_bundle_courses );
						}
					}
				}
			}
		}

		// Category-wise membership: all published courses in categories assigned to the level.
		foreach ( $level_ids as $cw_level_id ) {
			$cw_level_id = (int) $cw_level_id;
			if ( $cw_level_id <= 0 ) {
				continue;
			}
			$model = get_pmpro_membership_level_meta( $cw_level_id, 'TUTORPRESS_PMPRO_membership_model', true );
			if ( 'category_wise_membership' !== $model ) {
				continue;
			}
			$level_obj    = new \PMPro_Membership_Level();
			$category_ids = $level_obj->get_membership_level_categories( $cw_level_id );
			if ( empty( $category_ids ) || ! is_array( $category_ids ) ) {
				continue;
			}
			$category_ids = array_values( array_filter( array_map( 'absint', $category_ids ) ) );
			if ( empty( $category_ids ) ) {
				continue;
			}
			$cat_course_ids = get_posts(
				array(
					'post_type'      => tutor()->course_post_type,
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'tax_query'      => array(
						array(
							'taxonomy' => 'course-category',
							'field'    => 'term_id',
							'terms'    => $category_ids,
							'operator' => 'IN',
						),
					),
				)
			);
			if ( is_array( $cat_course_ids ) && ! empty( $cat_course_ids ) ) {
				$cat_course_ids = array_map( 'intval', $cat_course_ids );
				$course_ids           = array_merge( $course_ids, $cat_course_ids );
				$category_course_ids  = array_merge( $category_course_ids, $cat_course_ids );
			}
		}

		// Remove duplicates
		$course_ids = array_values( array_unique( array_map( 'intval', $course_ids ) ) );
		$bundle_course_ids   = array_values( array_unique( array_map( 'intval', $bundle_course_ids ) ) );
		$category_course_ids = array_values( array_unique( array_map( 'intval', $category_course_ids ) ) );

		// Return array with bundle and category-wise tracking information
		return array(
			'all_courses'       => $course_ids,
			'bundle_courses'    => $bundle_course_ids,
			'category_courses'  => $category_course_ids,
			'regular_courses'   => array_values( array_diff( $course_ids, $bundle_course_ids, $category_course_ids ) ),
		);
	}

	/**
	 * Handle enrollment flagging after enrollment is completed.
	 *
	 * Mark enrollments as PMPro membership-based when appropriate.
	 * This helps track which enrollments came from membership plans vs individual purchases.
	 *
	 * @since 1.0.0
	 *
	 * @param int $course_id   Course ID.
	 * @param int $user_id     User ID.
	 * @param int $enrolled_id Enrollment ID.
	 * @return void
	 */
	public function handle_after_enrollment_completed( $course_id, $user_id, $enrolled_id ) {
		$course_id   = (int) $course_id;
		$user_id     = (int) $user_id;
		$enrolled_id = (int) $enrolled_id;

		// One-time manual enrollment: Step 2 stores level id in pending_manual_meta before do_enroll().
		if ( $course_id && $user_id && $enrolled_id ) {
			$pending_key = "{$user_id}_{$course_id}";
			if ( isset( $this->pending_manual_meta[ $pending_key ] ) ) {
				$level_id = (int) $this->pending_manual_meta[ $pending_key ];
				unset( $this->pending_manual_meta[ $pending_key ] );

				if ( $level_id > 0 ) {
					update_post_meta( $enrolled_id, '_tutor_pmpro_level_id', $level_id );
					update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_level_id', $level_id );
					update_post_meta( $enrolled_id, '_tutorpress_pmpro_membership_enrollment', 1 );
					update_post_meta( $enrolled_id, '_tutorpress_pmpro_manual_enrollment', 1 );
				}

				return;
			}
		}

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
	 *
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

	/**
	 * Check if user is enrolled in a course via PMPro membership (not individual purchase).
	 * 
	 * Helper to determine if enrollment was via membership plan.
	 * This helps preserve individual purchase enrollments in membership-only mode.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $course_id Course ID.
	 * @param int|null $user_id   User ID (defaults to current user).
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


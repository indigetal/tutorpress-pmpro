<?php
/**
 * PMPro Subscriptions REST Controller
 *
 * Provides REST routes for mapping PMPro membership levels to TutorPress subscription plans.
 *
 * @package TutorPress-PMPro
 */

defined( 'ABSPATH' ) || exit;

class TutorPress_PMPro_Subscriptions_Controller extends TutorPress_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->rest_base = 'subscriptions';
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		try {
			// Get subscription plans for a course
			register_rest_route(
				$this->namespace,
				'/courses/(?P<course_id>[\d]+)/subscriptions',
				[
					[
						'methods' => WP_REST_Server::READABLE,
						'callback' => [ $this, 'get_course_subscriptions' ],
						'permission_callback' => function( $request ) {
							$course_id = (int) $request->get_param( 'course_id' );
							if ( $course_id && current_user_can( 'edit_post', $course_id ) ) {
								return true;
							}
							return $this->check_permission( $request );
						},
						'args' => [
							'course_id' => [
								'required' => true,
								'type' => 'integer',
								'sanitize_callback' => 'absint',
								'description' => __( 'The ID of the course to get subscription plans for.', 'tutorpress-pmpro' ),
							],
						],
					],
				]
			);

			// Get subscription plans for a bundle
			register_rest_route(
				$this->namespace,
				'/bundles/(?P<bundle_id>[\d]+)/subscriptions',
				[
					[
						'methods' => WP_REST_Server::READABLE,
						'callback' => [ $this, 'get_bundle_subscriptions' ],
						'permission_callback' => function( $request ) {
							$bundle_id = (int) $request->get_param( 'bundle_id' );
							if ( $bundle_id && current_user_can( 'edit_post', $bundle_id ) ) {
								return true;
							}
							return $this->check_permission( $request );
						},
						'args' => [
							'bundle_id' => [
								'required' => true,
								'type' => 'integer',
								'sanitize_callback' => 'absint',
								'description' => __( 'The ID of the bundle to get subscription plans for.', 'tutorpress-pmpro' ),
							],
						],
					],
				]
			);

			// Create new subscription plan (course or bundle)
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base,
				[
					[
						'methods' => WP_REST_Server::CREATABLE,
						'callback' => [ $this, 'create_subscription_plan' ],
						'permission_callback' => function( $request ) {
							$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
							if ( $object_id && current_user_can( 'edit_post', $object_id ) ) {
								return true;
							}
							return $this->check_permission( $request );
						},
						'args' => [
							'course_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
							'object_id' => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
							'plan_name' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
							'regular_price' => [ 'required' => true, 'type' => 'number', 'minimum' => 0 ],
						],
					],
				]
			);

		} catch ( Exception $e ) {
			error_log( 'TutorPress PMPro Subscriptions Controller: Failed to register routes - ' . $e->getMessage() );
		}
	}

	/**
	 * Get PMPro levels mapped to TutorPress subscription format for a course.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_course_subscriptions( $request ) {
		// Ensure Tutor LMS
		$tutor_check = $this->ensure_tutor_lms();
		if ( is_wp_error( $tutor_check ) ) {
			return $tutor_check;
		}

		$course_id = (int) $request->get_param( 'course_id' );

		// Validate course via shared utils
		$validation = TutorPress_Subscription_Utils::validate_course_id( $course_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Paid Memberships Pro is not active.', 'tutorpress-pmpro' ), 'pmpro_not_active', 400 );
		}

		$plans = [];
		// First try explicit course meta mapping
		$mapped = get_post_meta( $course_id, '_tutorpress_pmpro_levels', true );
		$level_ids = array();
		if ( ! empty( $mapped ) && is_array( $mapped ) ) {
			// mapped may be array of level IDs or associative plan_name=>level_id
			foreach ( $mapped as $k => $v ) {
				if ( is_numeric( $k ) ) {
					$level_ids[] = (int) $v;
				} else {
					$level_ids[] = (int) $v;
				}
			}
		} else {
			// Fallback: scan all PMPro levels and find ones with level meta 'tutorpress_course_id' === $course_id
			$all_levels = pmpro_getAllLevels( true, true );
			foreach ( $all_levels as $lvl ) {
				$assoc = get_pmpro_membership_level_meta( $lvl->id, 'tutorpress_course_id', true );
				if ( $assoc && (int) $assoc === $course_id ) {
					$level_ids[] = (int) $lvl->id;
				}
			}
		}

		// Build plans array from level IDs (or empty if none)
		if ( ! empty( $level_ids ) ) {
			foreach ( $level_ids as $lid ) {
				$level = pmpro_getLevel( $lid );
				if ( ! $level ) {
					continue;
				}
				$plans[] = [
					'id' => (int) $level->id,
					'name' => $level->name,
					'description' => $level->description,
					'price' => (float) $level->initial_payment,
					'recurring_price' => (float) ( isset( $level->billing_amount ) ? $level->billing_amount : 0 ),
					'billing_period' => $level->cycle_period,
					'billing_frequency' => (int) ( isset( $level->cycle_number ) ? $level->cycle_number : 0 ),
					'trial_period' => ( isset( $level->trial_limit ) ? $level->trial_limit : 0 ),
					'status' => 'active',
				];
			}
		}

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $plans, __( 'PMPro membership levels retrieved.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Get PMPro levels for a bundle. Currently mirrors course behavior.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_bundle_subscriptions( $request ) {
		// Validate Tutor LMS
		$tutor_check = $this->ensure_tutor_lms();
		if ( is_wp_error( $tutor_check ) ) {
			return $tutor_check;
		}

		$bundle_id = (int) $request->get_param( 'bundle_id' );

		$validation = TutorPress_Subscription_Utils::validate_bundle_id( $bundle_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Paid Memberships Pro is not active.', 'tutorpress-pmpro' ), 'pmpro_not_active', 400 );
		}

		$plans = [];
		// First attempt to read bundle mapping from post meta
		$mapped = get_post_meta( $bundle_id, '_tutorpress_pmpro_levels', true );
		$level_ids = array();
		if ( ! empty( $mapped ) && is_array( $mapped ) ) {
			foreach ( $mapped as $k => $v ) {
				$level_ids[] = (int) ( is_numeric( $k ) ? $v : $v );
			}
		} else {
			// Fallback: scan levels for 'tutorpress_bundle_id' meta
			$all_levels = pmpro_getAllLevels( true, true );
			foreach ( $all_levels as $lvl ) {
				$assoc = get_pmpro_membership_level_meta( $lvl->id, 'tutorpress_bundle_id', true );
				if ( $assoc && (int) $assoc === $bundle_id ) {
					$level_ids[] = (int) $lvl->id;
				}
			}
		}

		if ( ! empty( $level_ids ) ) {
			foreach ( $level_ids as $lid ) {
				$level = pmpro_getLevel( $lid );
				if ( ! $level ) continue;
				$plans[] = [
					'id' => (int) $level->id,
					'name' => $level->name,
					'description' => $level->description,
					'price' => (float) $level->initial_payment,
					'recurring_price' => (float) ( isset( $level->billing_amount ) ? $level->billing_amount : 0 ),
					'billing_period' => $level->cycle_period,
					'billing_frequency' => (int) ( isset( $level->cycle_number ) ? $level->cycle_number : 0 ),
					'trial_period' => ( isset( $level->trial_limit ) ? $level->trial_limit : 0 ),
					'status' => 'active',
				];
			}
		}

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $plans, __( 'PMPro membership levels retrieved for bundle.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Create a PMPro membership level from TutorPress plan data.
	 *
	 * For Step 2A this method validates inputs and returns a 501 Not Implemented.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_subscription_plan( $request ) {
		// Ensure Tutor LMS
		$tutor_check = $this->ensure_tutor_lms();
		if ( is_wp_error( $tutor_check ) ) {
			return $tutor_check;
		}

		$object_id = $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' );
		if ( ! $object_id ) {
			return new WP_Error( 'missing_object_id', __( 'Object ID is required (course_id or object_id).', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}

		$validation = TutorPress_Subscription_Utils::validate_course_id( $object_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! function_exists( 'pmpro_insert_or_replace' ) && ! class_exists( 'PMPro_Membership_Level' ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Paid Memberships Pro is not available for creating levels.', 'tutorpress-pmpro' ), 'pmpro_not_available', 400 );
		}

		// Creation logic will be implemented in Step 2C.
		return TutorPress_Subscription_Utils::format_error_response( __( 'Create operation not implemented yet.', 'tutorpress-pmpro' ), 'not_implemented', 501 );
	}

}



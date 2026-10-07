<?php
/**
 * PMPro Subscriptions REST Controller
 *
 * Provides REST routes for mapping PMPro membership levels to TutorPress subscription plans.
 *
 * @package TutorPress-PMPro
 */

defined( 'ABSPATH' ) || exit;

// Load mapper helper (small, local helper)
if ( file_exists( __DIR__ . '/../utilities/class-pmpro-mapper.php' ) ) {
	require_once __DIR__ . '/../utilities/class-pmpro-mapper.php';
}
// Association helper
if ( file_exists( __DIR__ . '/../utilities/class-pmpro-association.php' ) ) {
    require_once __DIR__ . '/../utilities/class-pmpro-association.php';
}

class TutorPress_PMPro_Subscriptions_Controller extends TutorPress_REST_Controller {

	/**
	 * The namespace for our REST API endpoints.
	 *
	 * @var string
	 */
	protected $namespace = 'tutorpress/v1';

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
					'permission_callback' => '__return_true', // Public read access - needed for frontend pricing display
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
					'permission_callback' => '__return_true', // Public read access - needed for frontend pricing display
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
						'permission_callback' => [ $this, 'authorize_subscription_object' ],
						'args' => [
							'course_id' => [ 'required' => false, 'type' => 'integer' ],
							'object_id' => [ 'required' => false, 'type' => 'integer' ],
							'plan_name' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
							'regular_price' => [ 'required' => true, 'type' => 'number', 'minimum' => 0 ],
							'object_title' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
						],
					],
				]
			);

			// Update existing subscription plan
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)',
				[
					[
						'methods' => WP_REST_Server::EDITABLE,
						'callback' => [ $this, 'update_subscription_plan' ],
						'permission_callback' => [ $this, 'authorize_subscription_object' ],
						'args' => [
							'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
						],
					],
				]
			);

			// Delete subscription plan
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)',
				[
					[
						'methods' => WP_REST_Server::DELETABLE,
						'callback' => [ $this, 'delete_subscription_plan' ],
						'permission_callback' => [ $this, 'authorize_subscription_object' ],
						'args' => [
							'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
						],
					],
				]
			);

		// Duplicate a subscription plan
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/duplicate',
			[
				[
					'methods' => WP_REST_Server::CREATABLE,
						'callback' => [ $this, 'duplicate_subscription_plan' ],
						'permission_callback' => [ $this, 'authorize_subscription_object' ],
					'args' => [
						'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					],
				],
			]
		);

		// Sort subscription plans. Display order is stored in _tutorpress_pmpro_levels.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sort',
			[
				[
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => [ $this, 'sort_subscription_plans' ],
					'permission_callback' => [ $this, 'authorize_subscription_object' ],
					'args' => [
						'object_id' => [ 'required' => true, 'type' => 'integer' ],
						'ordered_ids' => [ 'required' => true, 'type' => 'array' ],
					],
				],
			]
		);

		$this->register_editor_sort_route( '/courses/(?P<course_id>[\d]+)/subscriptions/sort', 'course_id', [ $this, 'sort_editor_course_plans' ] );
		$this->register_editor_sort_route( '/bundles/(?P<bundle_id>[\d]+)/subscriptions/sort', 'bundle_id', [ $this, 'sort_editor_bundle_plans' ] );

		} catch ( Exception $e ) {
			$this->log( 'TutorPress PMPro Subscriptions Controller: Failed to register routes - ' . $e->getMessage() );
		}
	}

	/**
	 * Register an editor sort route. Permission reads only $id_arg.
	 *
	 * @param string   $route    Route pattern.
	 * @param string   $id_arg   Post id argument name.
	 * @param callable $callback Route callback.
	 * @return void
	 */
	private function register_editor_sort_route( $route, $id_arg, $callback ) {
		register_rest_route(
			$this->namespace,
			$route,
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => $callback,
					'permission_callback' => function( $request ) use ( $id_arg ) {
						$source = 'bundle_id' === $id_arg ? 'editor_bundle' : 'editor_course';
						return $this->authorize_subscription_object( $request, $source );
					},
					'args'                => [
						$id_arg      => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint', 'description' => __( 'The ID of the course or bundle the plans belong to.', 'tutorpress-pmpro' ) ],
						'plan_order' => [
							'required'    => true,
							'type'        => 'array',
							'description' => __( 'Array of plan IDs in the desired order.', 'tutorpress-pmpro' ),
						],
					],
				],
			]
		);
	}

	/**
	 * Map a course editor sort onto the shared sort callback.
	 *
	 * @param WP_REST_Request $request Request with course_id and plan_order.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sort_editor_course_plans( $request ) {
		$url_params = $request->get_url_params();
		$request->set_param( 'object_id', array_key_exists( 'course_id', $url_params ) ? $url_params['course_id'] : null );
		$request->set_param( 'ordered_ids', $request->get_param( 'plan_order' ) );
		return $this->sort_subscription_plans( $request );
	}

	/**
	 * Map a bundle editor sort onto the shared sort callback.
	 *
	 * @param WP_REST_Request $request Request with bundle_id and plan_order.
	 * @return WP_REST_Response|WP_Error
	 */
	public function sort_editor_bundle_plans( $request ) {
		$url_params = $request->get_url_params();
		$request->set_param( 'object_id', array_key_exists( 'bundle_id', $url_params ) ? $url_params['bundle_id'] : null );
		$request->set_param( 'ordered_ids', $request->get_param( 'plan_order' ) );
		return $this->sort_subscription_plans( $request );
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
		$level_ids = $this->collect_subscription_level_ids( $course_id, 'tutorpress_course_id' );
		if ( is_wp_error( $level_ids ) ) {
			return $level_ids;
		}

		$level_ids = $this->filter_visible_level_ids( $course_id, $level_ids );

		// Build plans array from level IDs (or empty if none)
		if ( ! empty( $level_ids ) ) {
			$mapper = new \TutorPress_PMPro_Mapper();
			foreach ( $level_ids as $lid ) {
				$level = pmpro_getLevel( $lid );
				if ( ! $level ) {
					continue;
				}
				$plans[] = $mapper->map_pmpro_to_ui( $level );
			}
		}

		// Filter plans by the course's current selling_option (if applicable)
		$selling_option = get_post_meta( $course_id, 'tutor_course_selling_option', true );
		$this->log( '[TP-PMPRO] get_course_subscriptions filter: course=' . $course_id . ' selling_option=' . ( $selling_option ? $selling_option : 'empty' ) . ' plans_before_filter=' . count( $plans ) );
		if ( ! empty( $plans ) && ! empty( $selling_option ) ) {
			// Filter based on selling_option: only include matching payment types
			$plans = array_filter( $plans, function( $plan ) use ( $selling_option ) {
				$plan_type = isset( $plan['payment_type'] ) ? $plan['payment_type'] : 'recurring';
				$match = false;
				if ( 'one_time' === $selling_option ) {
					// Only show one-time plans
					$match = 'one_time' === $plan_type;
				} elseif ( 'subscription' === $selling_option ) {
					// Only show recurring plans
					$match = 'recurring' === $plan_type;
				} else {
					// 'both' or other: show all plans
					$match = true;
				}
				$this->log( '[TP-PMPRO] get_course_subscriptions filter_item: plan_id=' . ( isset( $plan['id'] ) ? $plan['id'] : 'unknown' ) . ' plan_type=' . $plan_type . ' selling_option=' . $selling_option . ' match=' . ( $match ? 'yes' : 'no' ) );
				return $match;
			} );
			// Re-index array to ensure clean structure
			$plans = array_values( $plans );
		}
		$this->log( '[TP-PMPRO] get_course_subscriptions filter: plans_after_filter=' . count( $plans ) );

		// Add membership mode metadata for frontend (Phase 3 integration)
		$metadata = array(
			'has_full_site_levels' => false,
			'membership_only_mode' => false,
		);

		// Check if PaidMembershipsPro class is available
		if ( class_exists( '\TUTORPRESS_PMPRO\PaidMembershipsPro' ) ) {
			// Use static methods to get membership mode status
			$metadata['has_full_site_levels'] = \TUTORPRESS_PMPRO\PaidMembershipsPro::pmpro_has_full_site_level();
			$metadata['membership_only_mode'] = \TUTORPRESS_PMPRO\PaidMembershipsPro::tutorpress_pmpro_membership_only_enabled();
		}

		// Return plans with metadata
		$response_data = array(
			'plans'    => $plans,
			'metadata' => $metadata,
		);

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $response_data, __( 'PMPro membership levels retrieved.', 'tutorpress-pmpro' ) ) );
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
		$level_ids = $this->collect_subscription_level_ids( $bundle_id, 'tutorpress_bundle_id' );
		if ( is_wp_error( $level_ids ) ) {
			return $level_ids;
		}

		$level_ids = $this->filter_visible_level_ids( $bundle_id, $level_ids );

		if ( ! empty( $level_ids ) ) {
			$mapper = new \TutorPress_PMPro_Mapper();
			foreach ( $level_ids as $lid ) {
				$level = pmpro_getLevel( $lid );
				if ( ! $level ) continue;
				$plans[] = $mapper->map_pmpro_to_ui( $level );
			}
		}

		// Filter plans by the bundle's current selling_option (if applicable)
		$selling_option = get_post_meta( $bundle_id, 'tutor_course_selling_option', true );
		$this->log( '[TP-PMPRO] get_bundle_subscriptions filter: bundle=' . $bundle_id . ' selling_option=' . ( $selling_option ? $selling_option : 'empty' ) . ' plans_before_filter=' . count( $plans ) );
		if ( ! empty( $plans ) && ! empty( $selling_option ) ) {
			// Filter based on selling_option: only include matching payment types
			$plans = array_filter( $plans, function( $plan ) use ( $selling_option ) {
				$plan_type = isset( $plan['payment_type'] ) ? $plan['payment_type'] : 'recurring';
				$match = false;
				if ( 'one_time' === $selling_option ) {
					// Only show one-time plans
					$match = 'one_time' === $plan_type;
				} elseif ( 'subscription' === $selling_option ) {
					// Only show recurring plans
					$match = 'recurring' === $plan_type;
				} else {
					// 'both' or other: show all plans
					$match = true;
				}
				$this->log( '[TP-PMPRO] get_bundle_subscriptions filter_item: plan_id=' . ( isset( $plan['id'] ) ? $plan['id'] : 'unknown' ) . ' plan_type=' . $plan_type . ' selling_option=' . $selling_option . ' match=' . ( $match ? 'yes' : 'no' ) );
				return $match;
			} );
			// Re-index array to ensure clean structure
			$plans = array_values( $plans );
		}
		$this->log( '[TP-PMPRO] get_bundle_subscriptions filter: plans_after_filter=' . count( $plans ) );

		// Add membership mode metadata for frontend (Phase 3 integration)
		$metadata = array(
			'has_full_site_levels' => false,
			'membership_only_mode' => false,
		);

		// Check if PaidMembershipsPro class is available
		if ( class_exists( '\TUTORPRESS_PMPRO\PaidMembershipsPro' ) ) {
			// Use static methods to get membership mode status
			$metadata['has_full_site_levels'] = \TUTORPRESS_PMPRO\PaidMembershipsPro::pmpro_has_full_site_level();
			$metadata['membership_only_mode'] = \TUTORPRESS_PMPRO\PaidMembershipsPro::tutorpress_pmpro_membership_only_enabled();
		}

		// Return plans with metadata
		$response_data = array(
			'plans'    => $plans,
			'metadata' => $metadata,
		);

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $response_data, __( 'PMPro membership levels retrieved for bundle.', 'tutorpress-pmpro' ) ) );
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
		$authorized = $this->authorize_subscription_object( $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		// Ensure Tutor LMS
		$tutor_check = $this->ensure_tutor_lms();
		if ( is_wp_error( $tutor_check ) ) {
			return $tutor_check;
		}

		$object_id = $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' );
		if ( ! $object_id ) {
			return new WP_Error( 'missing_object_id', __( 'Object ID is required (course_id or object_id).', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}

		// Detect post type and get appropriate validation
		$object_info = $this->detect_object_type( $object_id );
		$validation = call_user_func( $object_info['validate'], $object_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! function_exists( 'pmpro_insert_or_replace' ) && ! class_exists( 'PMPro_Membership_Level' ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Paid Memberships Pro is not available for creating levels.', 'tutorpress-pmpro' ), 'pmpro_not_available', 400 );
		}

		global $wpdb;

		$recurring_limit = $this->validate_supplied_recurring_limit( $request );
		if ( is_wp_error( $recurring_limit ) ) {
			return $recurring_limit;
		}

		// Prepare level data mapping using mapper helper
		$mapper = new \TutorPress_PMPro_Mapper();
		$level_data = $mapper->map_ui_to_pmpro( $request->get_params() );

		// Prepare DB-level data (strip UI-only meta before inserting into pmpro_membership_levels)
		$db_level_data = $level_data;
		if ( isset( $db_level_data['meta'] ) ) {
			unset( $db_level_data['meta'] );
		}

		// Normalize one-time vs recurring semantics (core may send payment_type)
		$payment_type = $request->get_param( 'payment_type' ) ?? ( isset( $level_data['payment_type'] ) ? $level_data['payment_type'] : null );
		if ( 'one_time' === $payment_type ) {
			$db_level_data['initial_payment'] = isset( $request['regular_price'] ) ? floatval( $request['regular_price'] ) : ( isset( $level_data['initial_payment'] ) ? $level_data['initial_payment'] : 0 );
			$db_level_data['billing_amount'] = 0;
			$db_level_data['cycle_number'] = 0;
			$db_level_data['cycle_period'] = '';
			$db_level_data['billing_limit'] = 0;
		}

		// Ensure level has a usable name for one-time plans only: prefer provided name, fall back to object title
		$object_id_for_name = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
		if ( empty( $db_level_data['name'] ) && 'one_time' === $payment_type ) {
			$object_title = $object_id_for_name ? get_the_title( $object_id_for_name ) : '';
			if ( $object_title ) {
				$db_level_data['name'] = sanitize_text_field( sprintf( '%s (One-time)', $object_title ) );
			} else {
				$db_level_data['name'] = sprintf( 'One-time plan for %s', $object_id_for_name ? $object_id_for_name : 'site' );
			}
		}

		// Suppress signups for non-published posts; allow_signups toggled on publish (Step 4)
		$post_status = get_post_status( (int) $object_id );
		if ( 'publish' !== $post_status ) {
			$db_level_data['allow_signups'] = 0;
		}

		// Insert level using PMPro helper if available
		if ( function_exists( 'pmpro_insert_or_replace' ) ) {
			$table = $wpdb->pmpro_membership_levels;
			$format = array();
			foreach ( $db_level_data as $k => $v ) {
				$format[] = is_int( $v ) ? '%d' : ( is_float( $v ) ? '%f' : '%s' );
			}
			$result = pmpro_insert_or_replace( $table, $db_level_data, $format );
			if ( ! $result ) {
				return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to create PMPro level.', 'tutorpress-pmpro' ), 'database_error', 500 );
			}
			$level_id = is_array( $result ) && isset( $result['id'] ) ? intval( $result['id'] ) : intval( $wpdb->insert_id );
		} else {
			// Fallback direct insert
			$insert = $wpdb->insert( $wpdb->pmpro_membership_levels, $db_level_data );
			if ( $insert === false ) {
				return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to create PMPro level.', 'tutorpress-pmpro' ), 'database_error', 500 );
			}
			$level_id = intval( $wpdb->insert_id );
		}

		if ( ! $level_id ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to determine new level ID.', 'tutorpress-pmpro' ), 'database_error', 500 );
		}

		// Associate level with course/bundle via pmpro_memberships_pages and level meta
		$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
        if ( $object_id ) {
			// Ensure association row exists in pmpro_memberships_pages
			if ( class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Association' ) ) {
				\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $object_id, $level_id );
			}

			// Set reverse lookup on PMPro level meta (use appropriate meta key based on post type)
			if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
				update_pmpro_membership_level_meta( $level_id, $object_info['meta_key'], $object_id );
				update_pmpro_membership_level_meta( $level_id, 'tutorpress_managed', 1 );
			} else {
				// Fallback: try PMPro meta function names or generic postmeta on pmpro level table
				try {
					if ( function_exists( 'add_pmpro_membership_level_meta' ) ) {
						add_pmpro_membership_level_meta( $level_id, $object_info['meta_key'], $object_id );
						add_pmpro_membership_level_meta( $level_id, 'tutorpress_managed', 1 );
					}
				} catch ( Exception $e ) {
					// ignore
				}
			}

			// Phase 5: Add level to course/bundle group
			if ( class_exists( '\\TUTORPRESS_PMPRO\\Init' ) ) {
				$group_title_override = null;
				if ( $request->has_param( 'object_title' ) ) {
					$group_title_override = sanitize_text_field( wp_unslash( (string) $request->get_param( 'object_title' ) ) );
				}
				if ( false === \TUTORPRESS_PMPRO\Init::add_level_to_course_group( $object_id, $level_id, $object_info['post_type'], $group_title_override ) ) {
					return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to map the membership level to its group.', 'tutorpress-pmpro' ), 'group_mapping_failed', 500 );
				}
			}

			$meta_key = '_tutorpress_pmpro_levels';
			$existing = get_post_meta( $object_id, $meta_key, true );
			if ( ! is_array( $existing ) ) $existing = array();
			$existing[] = $level_id;
			update_post_meta( $object_id, $meta_key, array_values( array_unique( $existing ) ) );

			// Note: Sale price handling for one-time purchases happens in reconciliation logic
			// (reconcile_course_levels/reconcile_bundle_levels calls handle_sale_price_for_one_time)

			// Persist any UI-only meta (sale_price etc.) if present in mapper output
			if ( isset( $level_data['meta'] ) && is_array( $level_data['meta'] ) ) {
				foreach ( $level_data['meta'] as $meta_key => $meta_val ) {
					// Normalize boolean-like values to integers for storage
					if ( is_bool( $meta_val ) ) {
						$meta_val = $meta_val ? 1 : 0;
					}

					if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
						$result = update_pmpro_membership_level_meta( $level_id, $meta_key, $meta_val );

					} elseif ( function_exists( 'add_pmpro_membership_level_meta' ) ) {
						// add_pmpro_membership_level_meta may be available in some PMPro versions
						add_pmpro_membership_level_meta( $level_id, $meta_key, $meta_val );
					}
				}
			}

			// Store sale schedule dates with tutorpress prefix (Step 3.1)
			// TutorPress already handles GMT conversion, so store as-is
			if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
				// Store sale_price_from if provided
				if ( isset( $level_data['meta']['sale_price_from'] ) && ! empty( $level_data['meta']['sale_price_from'] ) ) {
					update_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_from', $level_data['meta']['sale_price_from'] );
				}
				// Store sale_price_to if provided
				if ( isset( $level_data['meta']['sale_price_to'] ) && ! empty( $level_data['meta']['sale_price_to'] ) ) {
					update_pmpro_membership_level_meta( $level_id, 'tutorpress_sale_price_to', $level_data['meta']['sale_price_to'] );
				}
			}

			// Handle sale price for recurring subscriptions (Step 2)
			// Only process sale price for recurring plans (not one-time)
			// Sale price applies to initial_payment (enrollment window discount)
			if ( $payment_type !== 'one_time' && isset( $db_level_data['initial_payment'] ) ) {
				$regular_initial_payment = floatval( $db_level_data['initial_payment'] );
				if ( $regular_initial_payment > 0 ) {
					// Call static method to handle sale price logic
					\TUTORPRESS_PMPRO\Init::handle_sale_price_for_subscription( $level_id, $regular_initial_payment );
				}
			}
		}

		// Map created level back into TutorPress UI shape for response
		$level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( $level_id ) : null;
		$payload = $mapper->map_pmpro_to_ui( $level ?: (object) array_merge( array( 'id' => $level_id ), $level_data ) );

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $payload, __( 'PMPro membership level created.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Update an existing PMPro membership level and mapping.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_subscription_plan( $request ) {
		$authorized = $this->authorize_subscription_object( $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$plan_id = (int) $request->get_param( 'id' );
		if ( ! $plan_id ) {
			return new WP_Error( 'missing_id', __( 'Plan ID is required.', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}

		if ( ! function_exists( 'pmpro_getLevel' ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Paid Memberships Pro is not available.', 'tutorpress-pmpro' ), 'pmpro_not_available', 400 );
		}

		$level = pmpro_getLevel( $plan_id );
		if ( ! $level ) {
			return new WP_Error( 'level_not_found', __( 'PMPro level not found.', 'tutorpress-pmpro' ), [ 'status' => 404 ] );
		}

		$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
		$ownership = $this->validate_level_reverse_ownership( $plan_id, $object_id );
		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		$object_info = null;
		if ( $object_id ) {
			$object_info = $this->detect_object_type( $object_id );
		}

		// Prepare update fields
		$update_data = [];
		$mapper = new \TutorPress_PMPro_Mapper();
		if ( $request->has_param( 'plan_name' ) ) {
			$update_data['name'] = sanitize_text_field( $request->get_param( 'plan_name' ) );
		}
		if ( $request->has_param( 'description' ) ) {
			$update_data['description'] = sanitize_textarea_field( $request->get_param( 'description' ) );
		}
		$removal_object_id = absint( $object_id );
		if ( $removal_object_id > 0 ) {
			$blocked = $this->block_marked_level_update( $removal_object_id, $plan_id );
			if ( null !== $blocked ) {
				return $blocked;
			}
		}
		$recurring_limit = $this->validate_supplied_recurring_limit( $request );
		if ( is_wp_error( $recurring_limit ) ) {
			return $recurring_limit;
		}

        // Normalize create/update semantics depending on payment_type
        $payment_type = $request->get_param( 'payment_type' );
        if ( 'one_time' === $payment_type ) {
            if ( $request->has_param( 'regular_price' ) ) {
                $update_data['initial_payment'] = floatval( $request->get_param( 'regular_price' ) );
            }
            $update_data['billing_amount'] = 0;
            $update_data['cycle_number'] = 0;
            $update_data['cycle_period'] = '';
            $update_data['billing_limit'] = 0;
        } else {
            // Initial payment (enrollment fee) should come from 'enrollment_fee' when using PMPro
            if ( $request->has_param( 'enrollment_fee' ) ) {
                $update_data['initial_payment'] = floatval( $request->get_param( 'enrollment_fee' ) );
            }
            // Recurring (renewal) payment should come from 'recurring_price' (billing_amount)
            if ( $request->has_param( 'recurring_price' ) ) {
                $update_data['billing_amount'] = floatval( $request->get_param( 'recurring_price' ) );
            }
            if ( is_int( $recurring_limit ) ) {
                $update_data['billing_limit'] = $recurring_limit;
            }

            // Ensure PMPro association row (pmpro_memberships_pages) exists for the course/bundle
            if ( $object_id && class_exists( '\TUTORPRESS_PMPRO\PMPro_Association' ) ) {
                \TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $object_id, $plan_id );
            }
        }
		if ( $request->has_param( 'recurring_value' ) ) {
			$update_data['cycle_number'] = intval( $request->get_param( 'recurring_value' ) );
		}
		if ( $request->has_param( 'recurring_interval' ) ) {
			$update_data['cycle_period'] = ucfirst( strtolower( $request->get_param( 'recurring_interval' ) ) );
		}

		// Apply update via PMPro level functions if available
		$updated = false;
		// If there are no level fields to update, skip DB/update calls and consider as updated
		if ( empty( $update_data ) ) {
			// No structural PMPro level fields to update; we'll still persist UI-only meta below.
			$updated = true;
		} else {
		if ( function_exists( 'pmpro_updateMembershipLevel' ) ) {
			$level_arr = (array) $level;
			$level_arr = array_merge( $level_arr, $update_data );

			$result = pmpro_updateMembershipLevel( $level_arr );

			$updated = $result !== false;
		} else {
			// Fallback: direct DB update (not ideal)
			global $wpdb;
			$format = array();
			foreach ( $update_data as $value ) {
				$format[] = is_int( $value ) ? '%d' : ( is_float( $value ) ? '%f' : '%s' );
			}
			$result = $wpdb->update( $wpdb->pmpro_membership_levels, $update_data, array( 'id' => $plan_id ), $format, array( '%d' ) );
			$updated = false !== $result;
			}
		}

		if ( ! $updated ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to update PMPro level.', 'tutorpress-pmpro' ), 'update_failed', 500 );
		}

		// Optionally update mapping (course/bundle association)
		$object_id = $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' );
        if ( $object_id ) {
			// Detect post type if not already detected
			if ( ! $object_info ) {
				$object_info = $this->detect_object_type( $object_id );
			}
			
			// Ensure the level meta is set (use appropriate meta key based on post type)
			if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
				update_pmpro_membership_level_meta( $plan_id, $object_info['meta_key'], intval( $object_id ) );
			}
            // Ensure association exists
            if ( class_exists( '\TUTORPRESS_PMPRO\PMPro_Association' ) ) {
                \TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( intval( $object_id ), $plan_id );
            }
		}

	// Persist UI-only meta if provided
	$incoming_meta = $mapper->map_ui_to_pmpro( $request->get_params() );
        if ( isset( $incoming_meta['meta'] ) && is_array( $incoming_meta['meta'] ) ) {
            foreach ( $incoming_meta['meta'] as $meta_key => $meta_val ) {
                if ( is_bool( $meta_val ) ) {
                    $meta_val = $meta_val ? 1 : 0;
                }

                if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
                    update_pmpro_membership_level_meta( $plan_id, $meta_key, $meta_val );
                } elseif ( function_exists( 'add_pmpro_membership_level_meta' ) ) {
                    add_pmpro_membership_level_meta( $plan_id, $meta_key, $meta_val );
                }
            }
        }

	// Store sale schedule dates with tutorpress prefix (Step 3.1)
	// TutorPress already handles GMT conversion, so store as-is
	if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
		// Store sale_price_from if provided
		if ( isset( $incoming_meta['meta']['sale_price_from'] ) && ! empty( $incoming_meta['meta']['sale_price_from'] ) ) {
			update_pmpro_membership_level_meta( $plan_id, 'tutorpress_sale_price_from', $incoming_meta['meta']['sale_price_from'] );
		}
		// Store sale_price_to if provided
		if ( isset( $incoming_meta['meta']['sale_price_to'] ) && ! empty( $incoming_meta['meta']['sale_price_to'] ) ) {
			update_pmpro_membership_level_meta( $plan_id, 'tutorpress_sale_price_to', $incoming_meta['meta']['sale_price_to'] );
		}
	}

	// Handle sale price for recurring subscriptions (Step 2)
	// Only process sale price for recurring plans (not one-time)
	// Sale price applies to initial_payment (enrollment window discount)
	if ( $payment_type !== 'one_time' && isset( $update_data['initial_payment'] ) ) {
		$regular_initial_payment = floatval( $update_data['initial_payment'] );
		if ( $regular_initial_payment > 0 ) {
			// Call static method to handle sale price logic
			\TUTORPRESS_PMPRO\Init::handle_sale_price_for_subscription( $plan_id, $regular_initial_payment );
		}
	}

	global $wpdb;
	$wpdb->last_error = '';
	$persisted_level = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->pmpro_membership_levels} WHERE id = %d",
			$plan_id
		)
	);
	if ( '' !== $wpdb->last_error || null === $persisted_level ) {
		return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to read the updated PMPro level.', 'tutorpress-pmpro' ), 'persisted_level_read_failed', 500 );
	}
	$payload = $mapper->map_pmpro_to_ui( $persisted_level );

	return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $payload, __( 'PMPro membership level updated.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Remove a PMPro level from the requesting course or bundle.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_subscription_plan( $request ) {
		$authorized = $this->authorize_subscription_object( $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$plan_id = (int) $request->get_param( 'id' );
		if ( ! $plan_id ) {
			return new WP_Error( 'missing_id', __( 'Plan ID is required.', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}
		$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
		$info = $this->detect_object_type( $object_id ); $validation = call_user_func( $info['validate'], $object_id ); if ( is_wp_error( $validation ) ) { return $validation; }
		if ( ! class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Level_Cleanup' ) ) { require_once __DIR__ . '/../utilities/class-pmpro-level-cleanup.php'; }
		$code = \TUTORPRESS_PMPRO\PMPro_Level_Removal_Coordinator::remove_level( $plan_id, $object_id );
		if ( in_array( $code, array( 'ok', 'committed_with_warning' ), true ) ) {
			return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( 'committed_with_warning' === $code ? array( 'warning' => true ) : null, __( 'PMPro membership level removed.', 'tutorpress-pmpro' ) ) );
		}
		$map = array( 'missing' => 404, 'protected' => 409, 'ineligible' => 409, 'ownership_conflict' => 409, 'busy' => 409, 'conflict' => 409 ); return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to delete PMPro level.', 'tutorpress-pmpro' ), $code, isset( $map[ $code ] ) ? $map[ $code ] : 500 );
	}

	/**
	 * Duplicate a PMPro membership level and attach it to a course/bundle.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_subscription_plan( $request ) {
		$authorized = $this->authorize_subscription_object( $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$plan_id = (int) $request->get_param( 'id' );
		if ( ! $plan_id ) {
			return new WP_Error( 'missing_id', __( 'Plan ID is required.', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}

		$object_id = $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' );
		if ( ! $object_id ) {
			return new WP_Error( 'missing_object_id', __( 'Object ID is required (course_id or object_id).', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}
		$object_info = $this->detect_object_type( $object_id );
		$validation = call_user_func( $object_info['validate'], $object_id );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! function_exists( 'pmpro_getLevel' ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Paid Memberships Pro is not available.', 'tutorpress-pmpro' ), 'pmpro_not_available', 400 );
		}

		$level = pmpro_getLevel( $plan_id );
		if ( ! $level ) {
			return new WP_Error( 'level_not_found', __( 'PMPro level not found.', 'tutorpress-pmpro' ), [ 'status' => 404 ] );
		}

		$ownership = $this->validate_level_reverse_ownership( $plan_id, (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) ) );
		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		// Prepare duplicated data
		$data = (array) $level;
		unset( $data['id'] );
		$data['name'] = $data['name'] . ' (Copy)';

		global $wpdb;
		if ( function_exists( 'pmpro_insert_or_replace' ) ) {
			$table = $wpdb->pmpro_membership_levels;
			$format = array();
			foreach ( $data as $v ) {
				$format[] = is_int( $v ) ? '%d' : ( is_float( $v ) ? '%f' : '%s' );
			}
			$res = pmpro_insert_or_replace( $table, $data, $format );
			$new_id = is_array( $res ) && isset( $res['id'] ) ? intval( $res['id'] ) : intval( $wpdb->insert_id );
		} else {
			$wpdb->insert( $wpdb->pmpro_membership_levels, $data );
			$new_id = intval( $wpdb->insert_id );
		}

		if ( ! $new_id ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to duplicate PMPro level.', 'tutorpress-pmpro' ), 'database_error', 500 );
		}

		$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
		if ( $object_id ) {
			$meta_key = '_tutorpress_pmpro_levels';
			$existing = get_post_meta( $object_id, $meta_key, true );
			if ( ! is_array( $existing ) ) $existing = array();
			$existing[] = $new_id;
			update_post_meta( $object_id, $meta_key, array_values( array_unique( $existing ) ) );
			if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
				if ( function_exists( 'get_pmpro_membership_level_meta' ) ) {
					$copied_meta_keys = array(
						'provide_certificate',
						'is_featured',
						'sale_price',
						'sale_price_from',
						'sale_price_to',
						'tutorpress_sale_price_from',
						'tutorpress_sale_price_to',
						'tutorpress_regular_price',
						'tutorpress_sale_price',
					);
					foreach ( $copied_meta_keys as $copied_meta_key ) {
						$stored_values = get_pmpro_membership_level_meta( $plan_id, $copied_meta_key );
						if ( ! is_array( $stored_values ) || array() === $stored_values ) {
							continue;
						}
						update_pmpro_membership_level_meta( $new_id, $copied_meta_key, $stored_values[0] );
					}
				}
				update_pmpro_membership_level_meta( $new_id, $object_info['meta_key'], $object_id );
				update_pmpro_membership_level_meta( $new_id, 'tutorpress_managed', 1 );
			}
			// Ensure association exists
			if ( class_exists( '\TUTORPRESS_PMPRO\PMPro_Association' ) ) {
				\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $object_id, $new_id );
			}
			// Add to level group
			if ( class_exists( '\\TUTORPRESS_PMPRO\\Init' ) ) {
				if ( false === \TUTORPRESS_PMPRO\Init::add_level_to_course_group( $object_id, $new_id, $object_info['post_type'] ) ) {
					return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to map the membership level to its group.', 'tutorpress-pmpro' ), 'group_mapping_failed', 500 );
				}
			}
		}

		$mapper = new \TutorPress_PMPro_Mapper();
		$new_level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( $new_id ) : null;
		$payload = $mapper->map_pmpro_to_ui( $new_level ?: (object) array_merge( array( 'id' => $new_id ), $data ) );

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $payload, __( 'PMPro level duplicated.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Normalize submitted sort IDs before merge.
	 *
	 * Accept a positive integer or a digit-only positive integer string.
	 * Reject every other value, and reject duplicate IDs after normalization.
	 *
	 * @param array $ordered_ids Raw submitted IDs.
	 * @return int[]|WP_Error
	 */
	private function normalize_submitted_plan_order( array $ordered_ids ) {
		$normalized = array();
		$seen       = array();

		foreach ( $ordered_ids as $raw ) {
			if ( is_int( $raw ) && $raw > 0 ) {
				$id = $raw;
			} elseif ( is_string( $raw ) && ctype_digit( $raw ) && (int) $raw > 0 ) {
				$id = (int) $raw;
			} else {
				$id = 0;
			}

			if ( $id <= 0 || isset( $seen[ $id ] ) ) {
				return new WP_Error(
					'invalid_plan_order',
					__( 'Plan order must contain distinct positive integer IDs.', 'tutorpress-pmpro' ),
					array( 'status' => 400 )
				);
			}

			$seen[ $id ]  = true;
			$normalized[] = $id;
		}

		return $normalized;
	}

	/**
	 * Sort subscription plans. Display order is stored in _tutorpress_pmpro_levels.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function sort_subscription_plans( $request ) {
		$authorized = $this->authorize_subscription_object( $request );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
		$ordered_ids = $request->get_param( 'ordered_ids' );
		if ( ! $object_id || ! is_array( $ordered_ids ) ) {
			return new WP_Error( 'invalid_params', __( 'object_id and ordered_ids are required.', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}

		// Detect post type and get appropriate meta key
		$object_info = $this->detect_object_type( $object_id );

		$ordered_ids = $this->normalize_submitted_plan_order( $ordered_ids );
		if ( is_wp_error( $ordered_ids ) ) {
			return $ordered_ids;
		}

		$read = \TUTORPRESS_PMPRO\PMPro_Level_Removal_State::get_object_state( $object_id );
		if ( 'ok' !== $read['result'] ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to reorder subscription plans.', 'tutorpress-pmpro' ), $read['result'], 500 );
		}

		$stored = get_post_meta( $object_id, '_tutorpress_pmpro_levels', true );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = self::merge_display_order( $stored, $ordered_ids );
		foreach ( $merged as $plan_id ) {
			$ownership = $this->validate_level_reverse_ownership( $plan_id, $object_id );
			if ( is_wp_error( $ownership ) ) {
				return $ownership;
			}
		}
		if ( array_map( 'intval', array_values( $stored ) ) !== $merged ) {
			$written = update_post_meta( $object_id, '_tutorpress_pmpro_levels', $merged );
			if ( ! $written ) {
				$reread = get_post_meta( $object_id, '_tutorpress_pmpro_levels', true );
				$reread = array_map( 'intval', array_values( is_array( $reread ) ? $reread : array() ) );
				if ( $reread !== $merged ) {
					return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to reorder subscription plans.', 'tutorpress-pmpro' ), 'order_persist_failed', 500 );
				}
			}
		}

		// Best-effort: ensure reverse meta (use appropriate meta key based on post type)
        if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
			foreach ( $merged as $lid ) {
				update_pmpro_membership_level_meta( $lid, $object_info['meta_key'], $object_id );
			}
		}

        // Sync associations to match the ordered IDs
        if ( class_exists( '\TUTORPRESS_PMPRO\PMPro_Association' ) ) {
            \TUTORPRESS_PMPRO\PMPro_Association::sync_course_level_associations( $object_id, $merged );
        }

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $merged, __( 'Subscription plans reordered.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Stored canonical IDs, then unique ascending reverse-only level IDs.
	 *
	 * @since 1.0.9
	 *
	 * @param int    $object_id   Course or bundle ID.
	 * @param string $reverse_key Reverse ownership meta key.
	 * @return int[]|WP_Error Candidate IDs, or a 500 database error.
	 */
	private function collect_subscription_level_ids( $object_id, $reverse_key ) {
		global $wpdb;

		$canonical = array();
		$mapped    = get_post_meta( $object_id, '_tutorpress_pmpro_levels', true );
		if ( is_array( $mapped ) && ! empty( $mapped ) ) {
			foreach ( $mapped as $value ) {
				$canonical[] = (int) $value;
			}
		}

		$wpdb->last_error = '';
		$rows             = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT levels.id FROM {$wpdb->pmpro_membership_levels} AS levels INNER JOIN {$wpdb->pmpro_membership_levelmeta} AS levelmeta ON levelmeta.pmpro_membership_level_id = levels.id WHERE levelmeta.meta_key = %s AND levelmeta.meta_value = %s ORDER BY levels.id ASC",
				$reverse_key,
				(string) (int) $object_id
			)
		);
		if ( '' !== $wpdb->last_error ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to retrieve PMPro membership levels.', 'tutorpress-pmpro' ), 'database_error', 500 );
		}

		$seen         = array_fill_keys( $canonical, true );
		$reverse_only = array();
		foreach ( (array) $rows as $row_id ) {
			$id = (int) $row_id;
			if ( isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ]    = true;
			$reverse_only[] = $id;
		}
		sort( $reverse_only, SORT_NUMERIC );

		return array_merge( $canonical, $reverse_only );
	}

	/**
	 * Drop retired and unlinked level IDs from one object's plan list.
	 *
	 * A non-ok removal-state read exposes no object-specific plans.
	 * An ok read with no marked IDs leaves the original list unchanged.
	 *
	 * @since 1.0.9
	 *
	 * @param int   $object_id Course or bundle ID.
	 * @param array $level_ids Candidate level IDs.
	 * @return array Visible level IDs.
	 */
	private function filter_visible_level_ids( $object_id, $level_ids ) {
		$marked = \TUTORPRESS_PMPRO\PMPro_Level_Removal_State::get_marked( $object_id );
		if ( 'ok' !== $marked['result'] ) {
			return array();
		}
		if ( empty( $marked['level_ids'] ) ) {
			return $level_ids;
		}
		return array_values( array_diff( array_map( 'intval', (array) $level_ids ), array_map( 'intval', $marked['level_ids'] ) ) );
	}

	/**
	 * Reject an update of a marked pair or an unreadable removal map.
	 *
	 * Call only for a positive object ID. An ok read with a null pair proceeds.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle ID.
	 * @param int $level_id  PMPro level ID.
	 * @return WP_Error|null Error response, or null when the update may proceed.
	 */
	private function block_marked_level_update( $object_id, $level_id ) {
		$read = \TUTORPRESS_PMPRO\PMPro_Level_Removal_State::get_pair( $object_id, $level_id );
		if ( 'ok' !== $read['result'] ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to update PMPro level.', 'tutorpress-pmpro' ), $read['result'], 500 );
		}
		if ( is_array( $read['pair'] ) && in_array( $read['pair']['state'], array( \TUTORPRESS_PMPRO\PMPro_Level_Removal_State::STATE_RETIRED, \TUTORPRESS_PMPRO\PMPro_Level_Removal_State::STATE_UNLINKED ), true ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to update PMPro level.', 'tutorpress-pmpro' ), 'conflict', 409 );
		}
		return null;
	}

	/**
	 * Validate a supplied recurring limit before mapping or writes.
	 *
	 * Omission returns null. A nonnegative integer or digit-only string returns
	 * that integer. Any other supplied value is invalid.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int|null|WP_Error
	 */
	private function validate_supplied_recurring_limit( $request ) {
		if ( ! $request->has_param( 'recurring_limit' ) ) {
			return null;
		}

		$params = $request->get_params();
		$raw    = array_key_exists( 'recurring_limit', $params ) ? $params['recurring_limit'] : null;
		if ( is_int( $raw ) && $raw >= 0 ) {
			return $raw;
		}
		if ( is_string( $raw ) && '' !== $raw && ctype_digit( $raw ) ) {
			return (int) $raw;
		}

		return new WP_Error(
			'invalid_recurring_limit',
			__( 'Recurring limit must be a nonnegative integer.', 'tutorpress-pmpro' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Require the level's reverse key to match the accepted course or bundle.
	 *
	 * The object helper must already have accepted $object_id. This method does not write.
	 *
	 * @param int $plan_id   PMPro level ID.
	 * @param int $object_id Accepted course or bundle ID.
	 * @return true|WP_Error
	 */
	private function validate_level_reverse_ownership( $plan_id, $object_id ) {
		if ( ! function_exists( 'pmpro_getLevel' ) || ! function_exists( 'get_pmpro_membership_level_meta' ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Paid Memberships Pro is not available.', 'tutorpress-pmpro' ), 'pmpro_not_available', 400 );
		}
		if ( ! pmpro_getLevel( $plan_id ) ) {
			return new WP_Error( 'level_not_found', __( 'PMPro level not found.', 'tutorpress-pmpro' ), array( 'status' => 404 ) );
		}

		$post_type = get_post_type( $object_id );
		if ( 'course-bundle' === $post_type ) {
			$expected_key = 'tutorpress_bundle_id';
			$opposite_key = 'tutorpress_course_id';
		} elseif ( 'courses' === $post_type ) {
			$expected_key = 'tutorpress_course_id';
			$opposite_key = 'tutorpress_bundle_id';
		} else {
			return new WP_Error( 'invalid_course', __( 'Invalid course ID.', 'tutorpress' ), array( 'status' => 404 ) );
		}

		$expected = (int) get_pmpro_membership_level_meta( $plan_id, $expected_key, true );
		$opposite = (int) get_pmpro_membership_level_meta( $plan_id, $opposite_key, true );
		if ( $opposite > 0 || $expected !== (int) $object_id ) {
			return new WP_Error(
				'ownership_conflict',
				__( 'This membership level does not belong to the supplied object.', 'tutorpress-pmpro' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Authorize one course or bundle for an object-scoped subscription write.
	 *
	 * Editor sources read the URL capture only and are not wired in this step.
	 * Request and generic-sort resolution share one raw object_id ?? course_id.
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $source  request, editor_course, or editor_bundle.
	 * @return true|WP_Error
	 */
	public function authorize_subscription_object( $request, $source = 'request' ) {
		$object_id = (int) $this->resolve_subscription_object_id( $request, $source );
		if ( $object_id <= 0 ) {
			return new WP_Error(
				'missing_object_id',
				__( 'Object ID is required (course_id or object_id).', 'tutorpress-pmpro' ),
				array( 'status' => 400 )
			);
		}

		if ( 'editor_bundle' === $source ) {
			$validation = TutorPress_Subscription_Utils::validate_bundle_id( $object_id );
		} elseif ( 'editor_course' === $source ) {
			$validation = TutorPress_Subscription_Utils::validate_course_id( $object_id );
		} else {
			$object_info = $this->detect_object_type( $object_id );
			$validation  = call_user_func( $object_info['validate'], $object_id );
		}
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		if ( ! current_user_can( 'edit_post', $object_id ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access this endpoint.', 'tutorpress-pmpro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Resolve one raw object ID without absint().
	 *
	 * @param WP_REST_Request $request Request.
	 * @param string          $source  request, editor_course, or editor_bundle.
	 * @return mixed Raw ID, or null when the selected parameter is absent.
	 */
	private function resolve_subscription_object_id( $request, $source ) {
		if ( 'editor_course' === $source || 'editor_bundle' === $source ) {
			$url_params = $request->get_url_params();
			$key        = 'editor_course' === $source ? 'course_id' : 'bundle_id';
			return array_key_exists( $key, $url_params ) ? $url_params[ $key ] : null;
		}

		return $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' );
	}

	/**
	 * Detect post type and get appropriate validation function for an object ID.
	 *
	 * @since 1.7.0
	 * @param int $object_id The course or bundle ID.
	 * @return array {
	 *     @type string   $post_type Post type ('courses' or 'course-bundle').
	 *     @type callable $validate  Validation function to call.
	 *     @type string   $meta_key  Meta key for level association ('tutorpress_course_id' or 'tutorpress_bundle_id').
	 * }
	 */
	private function detect_object_type( $object_id ) {
		$post_type = get_post_type( $object_id );
		
		if ( 'course-bundle' === $post_type ) {
			return array(
				'post_type' => 'course-bundle',
				'validate'  => array( 'TutorPress_Subscription_Utils', 'validate_bundle_id' ),
				'meta_key'  => 'tutorpress_bundle_id',
				'label'     => 'bundle',
			);
		}
		
		// Default to course
		return array(
			'post_type' => 'courses',
			'validate'  => array( 'TutorPress_Subscription_Utils', 'validate_course_id' ),
			'meta_key'  => 'tutorpress_course_id',
			'label'     => 'course',
		);
	}

	/**
	 * Log a message if TP_PMPRO_LOG is enabled.
	 *
	 * @param string $message The message to log.
	 * @return void
	 */
	private function log( $message ) {
		if ( defined( 'TP_PMPRO_LOG' ) && TP_PMPRO_LOG ) {
			error_log( $message );
		}
	}

	/**
	 * Occurrence-counted merge of stored display ids and sanitized submitted ids.
	 *
	 * @param array $stored    Stored level ids. Nonpositive values stay in place.
	 * @param array $submitted Sanitized submitted ids. Unmatched ids are appended.
	 * @return array<int, int> Merged ids.
	 */
	private static function merge_display_order( array $stored, array $submitted ): array {
		$stored    = array_map( 'intval', array_values( $stored ) );
		$submitted = array_map( 'intval', array_values( $submitted ) );
		$open      = array();
		foreach ( $stored as $index => $id ) {
			$open[ (string) $id ][] = $index;
		}
		$marked = $matched = $unmatched = array();
		foreach ( $submitted as $id ) {
			$key = (string) $id;
			if ( ! empty( $open[ $key ] ) ) {
				$marked[ array_shift( $open[ $key ] ) ] = true;
				$matched[]                              = $id;
			} else {
				$unmatched[] = $id;
			}
		}
		$merged = array();
		$cursor = 0;
		foreach ( $stored as $index => $id ) {
			$merged[] = isset( $marked[ $index ] ) ? $matched[ $cursor++ ] : $id;
		}
		return array_merge( $merged, $unmatched );
	}

}



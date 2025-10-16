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
if ( file_exists( __DIR__ . '/../class-pmpro-mapper.php' ) ) {
	require_once __DIR__ . '/../class-pmpro-mapper.php';
}
// Association helper
if ( file_exists( __DIR__ . '/../class-pmpro-association.php' ) ) {
    require_once __DIR__ . '/../class-pmpro-association.php';
}

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

			// Update existing subscription plan
			register_rest_route(
				$this->namespace,
				'/' . $this->rest_base . '/(?P<id>[\d]+)',
				[
					[
						'methods' => WP_REST_Server::EDITABLE,
						'callback' => [ $this, 'update_subscription_plan' ],
						'permission_callback' => function( $request ) {
							$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
							if ( $object_id && current_user_can( 'edit_post', $object_id ) ) {
								return true;
							}
							return $this->check_permission( $request );
						},
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
						'permission_callback' => function( $request ) {
							$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
							if ( $object_id && current_user_can( 'edit_post', $object_id ) ) {
								return true;
							}
							return $this->check_permission( $request );
						},
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
					'permission_callback' => function( $request ) {
						$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
						if ( $object_id && current_user_can( 'edit_post', $object_id ) ) {
							return true;
						}
						return $this->check_permission( $request );
					},
					'args' => [
						'id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
					],
				],
			]
		);

		// Sort subscription plans for a course/bundle (no longer stores order in post meta)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sort',
			[
				[
					'methods' => WP_REST_Server::CREATABLE,
					'callback' => [ $this, 'sort_subscription_plans' ],
					'permission_callback' => function( $request ) {
						$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
						if ( $object_id && current_user_can( 'edit_post', $object_id ) ) {
							return true;
						}
						return $this->check_permission( $request );
					},
					'args' => [
						'object_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
						'ordered_ids' => [ 'required' => true, 'type' => 'array' ],
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
		$selling_option = get_post_meta( $course_id, '_tutor_course_selling_option', true );
		error_log( '[TP-PMPRO] get_course_subscriptions filter: course=' . $course_id . ' selling_option=' . ( $selling_option ? $selling_option : 'empty' ) . ' plans_before_filter=' . count( $plans ) );
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
				error_log( '[TP-PMPRO] get_course_subscriptions filter_item: plan_id=' . ( isset( $plan['id'] ) ? $plan['id'] : 'unknown' ) . ' plan_type=' . $plan_type . ' selling_option=' . $selling_option . ' match=' . ( $match ? 'yes' : 'no' ) );
				return $match;
			} );
			// Re-index array to ensure clean structure
			$plans = array_values( $plans );
		}
		error_log( '[TP-PMPRO] get_course_subscriptions filter: plans_after_filter=' . count( $plans ) );

		// If course is not published, also include any pending (queued) plans stored in meta
		$status = get_post_status( (int) $course_id );
		if ( 'publish' !== $status ) {
			$pending = get_post_meta( $course_id, '_tutorpress_pmpro_pending_plans', true );
			if ( is_array( $pending ) && ! empty( $pending ) ) {
				$mapper = isset( $mapper ) ? $mapper : new \TutorPress_PMPro_Mapper();
				foreach ( $pending as $pp ) {
					$ui = $mapper->map_pmpro_to_ui( (object) array_merge( array( 'id' => 0 ), $mapper->map_ui_to_pmpro( (array) $pp ) ) );
					if ( is_array( $ui ) ) { $ui['queued'] = true; $ui['status'] = 'pending_publish'; }
					$plans[] = $ui;
				}
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
			$mapper = new \TutorPress_PMPro_Mapper();
			foreach ( $level_ids as $lid ) {
				$level = pmpro_getLevel( $lid );
				if ( ! $level ) continue;
				$plans[] = $mapper->map_pmpro_to_ui( $level );
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

		// Publish guard: do not create PMPro levels for drafts/unpublished content
		$status = get_post_status( (int) $object_id );
		if ( 'publish' !== $status ) {
			// Persist pending plan for later creation on publish
			$pending_key = '_tutorpress_pmpro_pending_plans';
			$pending = get_post_meta( (int) $object_id, $pending_key, true );
			if ( ! is_array( $pending ) ) { $pending = array(); }
			$incoming = $request->get_params();
			$incoming['__ts'] = current_time( 'timestamp' );
			$pending[] = $incoming;
			update_post_meta( (int) $object_id, $pending_key, $pending );

			// Build a UI-shaped payload so the editor can continue without crashing
			$mapper = new \TutorPress_PMPro_Mapper();
			$level_data = $mapper->map_ui_to_pmpro( $request->get_params() );
			$queued_level = (object) array_merge( array( 'id' => 0 ), $level_data );
			$payload = $mapper->map_pmpro_to_ui( $queued_level );
			if ( is_array( $payload ) ) { $payload['queued'] = true; $payload['status'] = 'pending_publish'; }
			return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $payload, __( 'Plan queued: course is not published. Levels will be created on publish.', 'tutorpress-pmpro' ) ) );
		}

		if ( ! function_exists( 'pmpro_insert_or_replace' ) && ! class_exists( 'PMPro_Membership_Level' ) ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Paid Memberships Pro is not available for creating levels.', 'tutorpress-pmpro' ), 'pmpro_not_available', 400 );
		}

		global $wpdb;

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

		// Ensure level has a usable name for one-time plans only: prefer provided name, fall back to course title
		$object_id_for_name = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
		if ( empty( $db_level_data['name'] ) && 'one_time' === $payment_type ) {
			$course_title = $object_id_for_name ? get_the_title( $object_id_for_name ) : '';
			if ( $course_title ) {
				$db_level_data['name'] = sanitize_text_field( sprintf( '%s (One-time)', $course_title ) );
			} else {
				$db_level_data['name'] = sprintf( 'One-time plan for %s', $object_id_for_name ? $object_id_for_name : 'site' );
			}
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

			// Set reverse lookup on PMPro level meta
			if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
				update_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', $object_id );
			} else {
				// Fallback: try PMPro meta function names or generic postmeta on pmpro level table
				try {
					if ( function_exists( 'add_pmpro_membership_level_meta' ) ) {
						add_pmpro_membership_level_meta( $level_id, 'tutorpress_course_id', $object_id );
					}
				} catch ( Exception $e ) {
					// ignore
				}
			}

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

		// Publish guard: if an object_id/course_id is provided and not published, skip PMPro updates
		$object_id = $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' );
		if ( $object_id ) {
			$status = get_post_status( (int) $object_id );
			if ( 'publish' !== $status ) {
				// Return a UI-shaped payload to keep editor stable, marked as queued
				$mapper = new \TutorPress_PMPro_Mapper();
				$incoming = $mapper->map_ui_to_pmpro( $request->get_params() );
				$queued_level = (object) array_merge( (array) $level, $incoming );
				$payload = $mapper->map_pmpro_to_ui( $queued_level );
				if ( is_array( $payload ) ) { $payload['queued'] = true; $payload['status'] = 'pending_publish'; }
				return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $payload, __( 'Plan update queued: course is not published. Updates will apply on publish.', 'tutorpress-pmpro' ) ) );
			}
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

            // Ensure PMPro association row (pmpro_memberships_pages) exists for the course
            if ( class_exists( '\TUTORPRESS_PMPRO\PMPro_Association' ) ) {
                \TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $object_id, $level_id );
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
			$result = $wpdb->update( $wpdb->pmpro_membership_levels, $update_data, [ 'id' => $plan_id ] );
			$updated = $result !== false;
			}
		}

		if ( ! $updated ) {
			return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to update PMPro level.', 'tutorpress-pmpro' ), 'update_failed', 500 );
		}

		// Optionally update mapping (course/bundle association)
		$object_id = $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' );
        if ( $object_id ) {
			// Ensure the level meta is set
			if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
				update_pmpro_membership_level_meta( $plan_id, 'tutorpress_course_id', intval( $object_id ) );
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

		// Return the updated level using mapper
		$level = pmpro_getLevel( $plan_id );
		$payload = $mapper->map_pmpro_to_ui( $level );

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $payload, __( 'PMPro membership level updated.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Delete a PMPro membership level and cleanup mappings.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_subscription_plan( $request ) {
		$plan_id = (int) $request->get_param( 'id' );
		if ( ! $plan_id ) {
			return new WP_Error( 'missing_id', __( 'Plan ID is required.', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}

		if ( function_exists( 'pmpro_deleteMembershipLevel' ) ) {
			$result = pmpro_deleteMembershipLevel( $plan_id );
			if ( $result === false ) {
				return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to delete PMPro level.', 'tutorpress-pmpro' ), 'delete_failed', 500 );
			}
		} else {
			global $wpdb;
			$deleted = $wpdb->delete( $wpdb->pmpro_membership_levels, [ 'id' => $plan_id ], [ '%d' ] );
			if ( $deleted === false ) {
				return TutorPress_Subscription_Utils::format_error_response( __( 'Failed to delete PMPro level.', 'tutorpress-pmpro' ), 'delete_failed', 500 );
			}
		}

		// Cleanup post meta on courses/bundles that referenced this level
		$meta_key = '_tutorpress_pmpro_levels';
		$posts = get_posts( array( 'post_type' => array( tutor()->course_post_type, tutor()->bundle_post_type ), 'numberposts' => -1, 'post_status' => 'any' ) );
		if ( $posts ) {
			foreach ( $posts as $p ) {
				$existing = get_post_meta( $p->ID, $meta_key, true );
				if ( is_array( $existing ) ) {
					$new = array_values( array_diff( $existing, array( $plan_id ) ) );
					update_post_meta( $p->ID, $meta_key, $new );
				}
			}
		}

        // Remove associations in pmpro_memberships_pages
        if ( class_exists( '\TUTORPRESS_PMPRO\PMPro_Association' ) ) {
            \TUTORPRESS_PMPRO\PMPro_Association::remove_associations_for_level( $plan_id );
        }

		return rest_ensure_response( $this->format_response( null, __( 'PMPro membership level deleted.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Duplicate a PMPro membership level and attach it to a course/bundle.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function duplicate_subscription_plan( $request ) {
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

		// Attach to course/bundle if provided
		$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
		if ( $object_id ) {
			$meta_key = '_tutorpress_pmpro_levels';
			$existing = get_post_meta( $object_id, $meta_key, true );
			if ( ! is_array( $existing ) ) $existing = array();
			$existing[] = $new_id;
			update_post_meta( $object_id, $meta_key, array_values( array_unique( $existing ) ) );
			if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
				update_pmpro_membership_level_meta( $new_id, 'tutorpress_course_id', $object_id );
			}
		}

		$mapper = new \TutorPress_PMPro_Mapper();
		$new_level = function_exists( 'pmpro_getLevel' ) ? pmpro_getLevel( $new_id ) : null;
		$payload = $mapper->map_pmpro_to_ui( $new_level ?: (object) array_merge( array( 'id' => $new_id ), $data ) );

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $payload, __( 'PMPro level duplicated.', 'tutorpress-pmpro' ) ) );
	}

	/**
	 * Sort subscription plans for a course/bundle (no longer stores order in post meta)
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function sort_subscription_plans( $request ) {
		$object_id = (int) ( $request->get_param( 'object_id' ) ?? $request->get_param( 'course_id' ) );
		$ordered_ids = $request->get_param( 'ordered_ids' );
		if ( ! $object_id || ! is_array( $ordered_ids ) ) {
			return new WP_Error( 'invalid_params', __( 'object_id and ordered_ids are required.', 'tutorpress-pmpro' ), [ 'status' => 400 ] );
		}

        // Sanitize IDs
		$ordered_ids = array_values( array_filter( array_map( 'absint', $ordered_ids ) ) );

		// Best-effort: ensure reverse meta
        if ( function_exists( 'update_pmpro_membership_level_meta' ) ) {
			foreach ( $ordered_ids as $lid ) {
				update_pmpro_membership_level_meta( $lid, 'tutorpress_course_id', $object_id );
			}
		}

        // Sync associations to match the ordered IDs
        if ( class_exists( '\TUTORPRESS_PMPRO\PMPro_Association' ) ) {
            \TUTORPRESS_PMPRO\PMPro_Association::sync_course_level_associations( $object_id, $ordered_ids );
        }

		return rest_ensure_response( TutorPress_Subscription_Utils::format_success_response( $ordered_ids, __( 'Subscription plans reordered.', 'tutorpress-pmpro' ) ) );
	}

}



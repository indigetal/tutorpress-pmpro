<?php
/**
 * PMPro Earnings Handler
 *
 * Integrates PMPro orders with Tutor Core's revenue sharing system.
 * Creates minimal order records for earning calculations without duplicating PMPro's order management.
 *
 * @package TutorPress_PMPro
 * @since 1.6.0
 */

namespace TUTORPRESS_PMPRO;

use TUTOR\Earnings;
use Tutor\Models\OrderModel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PMPro_Earnings_Handler Class
 *
 * Handles revenue sharing integration between PMPro and Tutor Core.
 *
 * @since 1.6.0
 */
class PMPro_Earnings_Handler {

	/**
	 * Singleton instance
	 *
	 * @var PMPro_Earnings_Handler
	 */
	private static $instance = null;

	/**
	 * Current order ID being processed for earnings (for debug logging).
	 *
	 * @var int|null
	 */
	private $current_earnings_order_id = null;

	/**
	 * Get singleton instance
	 *
	 * @return PMPro_Earnings_Handler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor - Register hooks
	 *
	 * @since 1.6.0
	 */
	private function __construct() {
		// Initial checkout (one-time or first subscription payment).
		add_action( 'pmpro_after_checkout', array( $this, 'handle_checkout_complete' ), 10, 2 );

		// Subscription renewal payments (fired by gateway webhooks/IPNs when renewals are processed).
		add_action( 'pmpro_subscription_payment_completed', array( $this, 'handle_subscription_renewal' ), 10, 1 );

		// Cancellations/Refunds.
		add_action( 'pmpro_updated_order', array( $this, 'handle_order_status_change' ), 10, 2 );

		// Ensure Tutor Core can find course_id for PMPro subscription orders during earnings calculation.
		// For subscription orders, Tutor Core uses these filters to determine the course_id.
		add_filter( 'tutor_subscription_course_by_plan', array( $this, 'filter_subscription_course_by_plan' ), 10, 2 );
		add_filter( 'tutor_get_plan_info', array( $this, 'filter_get_plan_info' ), 10, 2 );

		// Fix: Intercept earnings calculation to correct zero amounts for PMPro subscriptions.
		add_filter( 'tutor_pro_earning_calculator', array( $this, 'fix_subscription_earnings_calculation' ), 999, 1 );

	}

	/**
	 * Handle PMPro checkout completion
	 *
	 * Fires on initial checkout (one-time purchase or first subscription payment).
	 * Creates minimal Tutor order and calculates earnings.
	 *
	 * @since 1.6.0
	 *
	 * @param int    $user_id User ID who checked out.
	 * @param object $morder  PMPro order object.
	 * @return void
	 */
	public function handle_checkout_complete( $user_id, $morder ) {
		// Skip if revenue sharing is disabled.
		if ( ! $this->is_revenue_sharing_enabled() ) {
			return;
		}

		// Check if this PMPro order already has a Tutor order (avoid duplicates).
		global $wpdb;
		$existing_tutor_order = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT order_id FROM {$wpdb->prefix}tutor_ordermeta
				 WHERE meta_key = 'pmpro_order_id' AND meta_value = %d
				 LIMIT 1",
				$morder->id
			)
		);

		if ( $existing_tutor_order ) {
			error_log( '[TP-PMPRO Earnings] PMPro order #' . $morder->id . ' already has Tutor order #' . $existing_tutor_order . ', skipping' );
			return;
		}

		// Get the membership level.
		$level = pmpro_getLevel( $morder->membership_id );
		if ( ! $level ) {
			return;
		}

		// Check if this is a TutorPress-managed course-specific level.
		$course_id = get_pmpro_membership_level_meta( $level->id, 'tutorpress_course_id', true );
		if ( ! $course_id ) {
			// Not a course-specific level (e.g., full-site membership) - skip earnings.
			return;
		}

		// Determine order type (initial checkout only - renewals come through pmpro_subscription_payment_completed).
		$is_subscription = ! empty( $level->cycle_number ) || ! empty( $level->billing_amount );
		$order_type      = $is_subscription ? OrderModel::TYPE_SUBSCRIPTION : OrderModel::TYPE_SINGLE_ORDER;

		// Get the amount paid (use initial_payment for subscriptions, otherwise use total).
		$amount = ! empty( $morder->total ) ? $morder->total : ( $is_subscription ? $level->initial_payment : 0 );

		// Create minimal Tutor order record.
		$tutor_order_id = $this->create_tutor_order(
			array(
				'order_type'     => $order_type,
				'user_id'        => $user_id,
				'course_id'      => $course_id,
				'amount'         => $amount,
				'pmpro_order_id' => $morder->id,
				'pmpro_level_id' => $level->id,
			)
		);

		if ( ! $tutor_order_id ) {
			error_log( '[TP-PMPRO Earnings] Failed to create Tutor order for PMPro order #' . $morder->id );
			return;
		}

		// Calculate and store earnings using Tutor Core.
		$this->calculate_and_store_earnings( $tutor_order_id );

		error_log( sprintf(
			'[TP-PMPRO Earnings] Created %s earnings for user %d, course %d, amount $%s (PMPro order #%d, Tutor order #%d)',
			$order_type,
			$user_id,
			$course_id,
			$amount,
			$morder->id,
			$tutor_order_id
		) );
	}

	/**
	 * Handle subscription renewal payment
	 *
	 * Fires when a subscription renewal payment succeeds.
	 * Creates a new Tutor order with TYPE_RENEWAL.
	 *
	 * @since 1.6.0
	 *
	 * @param object $morder PMPro order object for the renewal.
	 * @return void
	 */
	public function handle_subscription_renewal( $morder ) {
		error_log( '[TP-PMPRO Earnings] handle_subscription_renewal called for PMPro order #' . ( isset( $morder->id ) ? $morder->id : 'unknown' ) );
		
		// Skip if revenue sharing is disabled.
		if ( ! $this->is_revenue_sharing_enabled() ) {
			error_log( '[TP-PMPRO Earnings] Revenue sharing disabled, skipping renewal' );
			return;
		}

		// Get the membership level.
		$level = pmpro_getLevel( $morder->membership_id );
		if ( ! $level ) {
			error_log( '[TP-PMPRO Earnings] Could not get level for membership_id: ' . ( isset( $morder->membership_id ) ? $morder->membership_id : 'unknown' ) );
			return;
		}

		error_log( '[TP-PMPRO Earnings] Processing renewal for level #' . $level->id . ', user #' . ( isset( $morder->user_id ) ? $morder->user_id : 'unknown' ) );

		// Check if this is a TutorPress-managed course-specific level.
		$course_id = get_pmpro_membership_level_meta( $level->id, 'tutorpress_course_id', true );
		if ( ! $course_id ) {
			// Not a course-specific level - skip earnings.
			error_log( '[TP-PMPRO Earnings] Level #' . $level->id . ' is not a course-specific level, skipping renewal earnings' );
			return;
		}

		error_log( '[TP-PMPRO Earnings] Renewal is for course #' . $course_id );

		// Get renewal amount (typically billing_amount, but use total from order if available).
		$amount = ! empty( $morder->total ) ? $morder->total : $level->billing_amount;

		// Create minimal Tutor order record with TYPE_RENEWAL.
		$tutor_order_id = $this->create_tutor_order(
			array(
				'order_type'     => OrderModel::TYPE_RENEWAL,
				'user_id'        => $morder->user_id,
				'course_id'      => $course_id,
				'amount'         => $amount,
				'pmpro_order_id' => $morder->id,
				'pmpro_level_id' => $level->id,
			)
		);

		if ( ! $tutor_order_id ) {
			error_log( '[TP-PMPRO Earnings] Failed to create Tutor renewal order for PMPro order #' . $morder->id );
			return;
		}

		// Calculate and store earnings using Tutor Core.
		$this->calculate_and_store_earnings( $tutor_order_id );

		error_log( sprintf(
			'[TP-PMPRO Earnings] Created renewal earnings for user %d, course %d, amount $%s (PMPro order #%d, Tutor order #%d)',
			$morder->user_id,
			$course_id,
			$amount,
			$morder->id,
			$tutor_order_id
		) );
	}

	/**
	 * Handle order status changes (refunds, cancellations)
	 *
	 * @since 1.6.0
	 *
	 * @param int    $order_id PMPro order ID.
	 * @param object $old_order Old order object (before update).
	 * @return void
	 */
	public function handle_order_status_change( $order_id, $old_order ) {
		// Get updated order.
		$new_order = new \MemberOrder( $order_id );

		// Check if status changed to refunded or cancelled.
		if ( in_array( $new_order->status, array( 'refunded', 'cancelled' ), true ) ) {
			$this->handle_refund_or_cancellation( $new_order );
		}
	}

	/**
	 * Handle refunds and cancellations
	 *
	 * Removes earnings associated with the PMPro order.
	 *
	 * @since 1.6.0
	 *
	 * @param object $morder PMPro order object.
	 * @return void
	 */
	private function handle_refund_or_cancellation( $morder ) {
		global $wpdb;

		// Find associated Tutor order(s).
		$tutor_order_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT order_id FROM {$wpdb->prefix}tutor_ordermeta
				 WHERE meta_key = 'pmpro_order_id' AND meta_value = %d",
				$morder->id
			)
		);

		if ( empty( $tutor_order_ids ) ) {
			return;
		}

		// Delete earnings for each associated Tutor order.
		$earnings = Earnings::get_instance();
		foreach ( $tutor_order_ids as $tutor_order_id ) {
			$earnings->delete_earning_by_order( $tutor_order_id );
			error_log( sprintf(
				'[TP-PMPRO Earnings] Deleted earnings for Tutor order #%d (PMPro order #%d refunded/cancelled)',
				$tutor_order_id,
				$morder->id
			) );
		}
	}

	/**
	 * Create minimal Tutor order record
	 *
	 * Creates a minimal order in Tutor's order tables for earning tracking.
	 * Does NOT create full order UI to avoid conflicts with PMPro.
	 *
	 * Schema aligned with Tutor Core 3.0+ (wp_tutor_orders, wp_tutor_order_items, wp_tutor_ordermeta).
	 *
	 * @since 1.6.0
	 *
	 * @param array $args {
	 *     Order data.
	 *
	 *     @type string $order_type     Order type (single_order, subscription, renewal).
	 *     @type int    $user_id        User ID.
	 *     @type int    $course_id      Course ID.
	 *     @type float  $amount         Amount paid.
	 *     @type int    $pmpro_order_id PMPro order ID (for cross-reference).
	 *     @type int    $pmpro_level_id PMPro level ID (for cross-reference).
	 * }
	 * @return int|false Tutor order ID on success, false on failure.
	 */
	private function create_tutor_order( $args ) {
		global $wpdb;

		$defaults = array(
			'order_type'     => OrderModel::TYPE_SINGLE_ORDER,
			'user_id'        => 0,
			'course_id'      => 0,
			'amount'         => 0,
			'pmpro_order_id' => 0,
			'pmpro_level_id' => 0,
		);

		$args = wp_parse_args( $args, $defaults );

		// Validate required fields.
		if ( empty( $args['user_id'] ) || empty( $args['course_id'] ) || empty( $args['amount'] ) ) {
			return false;
		}

		// Insert minimal order record.
		$current_user_id = get_current_user_id();
		$order_data = array(
			'order_type'        => $args['order_type'],
			'order_status'      => OrderModel::ORDER_COMPLETED,
			'payment_status'    => OrderModel::PAYMENT_PAID,
			'user_id'           => $args['user_id'],
			'total_price'       => $args['amount'],
			'subtotal_price'    => $args['amount'],
			'pre_tax_price'     => $args['amount'],
			'net_payment'       => $args['amount'],
			'coupon_amount'     => 0,
			'discount_amount'   => 0,
			'tax_amount'        => 0,
			'payment_method'    => 'pmpro',
			'created_at_gmt'    => current_time( 'mysql', true ),
			'created_by'        => $current_user_id ? $current_user_id : $args['user_id'],
			'updated_by'        => $current_user_id ? $current_user_id : $args['user_id'],
		);

		$inserted = $wpdb->insert(
			$wpdb->prefix . 'tutor_orders',
			$order_data,
			array(
				'%s', // order_type
				'%s', // order_status
				'%s', // payment_status
				'%d', // user_id
				'%f', // total_price
				'%f', // subtotal_price
				'%f', // pre_tax_price
				'%f', // net_payment
				'%f', // coupon_amount
				'%f', // discount_amount
				'%f', // tax_amount
				'%s', // payment_method
				'%s', // created_at_gmt
				'%d', // created_by
				'%d', // updated_by
			)
		);

		if ( ! $inserted ) {
			error_log( '[TP-PMPRO Earnings] Failed to insert Tutor order. DB Error: ' . $wpdb->last_error );
			return false;
		}

		$order_id = $wpdb->insert_id;
		error_log( '[TP-PMPRO Earnings] Created Tutor order #' . $order_id . ' (type: ' . $args['order_type'] . ', amount: $' . $args['amount'] . ')' );

		// Insert order item (course link).
		$item_data = array(
			'order_id'      => $order_id,
			'item_id'       => $args['course_id'],
			'regular_price' => $args['amount'],
			'sale_price'    => (string) $args['amount'],
		);

		$item_inserted = $wpdb->insert(
			$wpdb->prefix . 'tutor_order_items',
			$item_data,
			array( '%d', '%d', '%f', '%s' )
		);

		if ( ! $item_inserted ) {
			error_log( '[TP-PMPRO Earnings] Failed to insert order item for order #' . $order_id . '. DB Error: ' . $wpdb->last_error );
		} else {
			$item_id = $wpdb->insert_id;
			error_log( '[TP-PMPRO Earnings] Created order item #' . $item_id . ' for course ' . $args['course_id'] . ' in order #' . $order_id );
		}

		// Store cross-reference meta for bidirectional lookup.
		$this->add_order_meta( $order_id, 'pmpro_order_id', $args['pmpro_order_id'], $args['user_id'] );
		$this->add_order_meta( $order_id, 'pmpro_level_id', $args['pmpro_level_id'], $args['user_id'] );

		return $order_id;
	}

	/**
	 * Calculate and store earnings using Tutor Core
	 *
	 * @since 1.6.0
	 *
	 * @param int $tutor_order_id Tutor order ID.
	 * @return void
	 */
	private function calculate_and_store_earnings( $tutor_order_id ) {
		global $wpdb;
		
		$earnings = Earnings::get_instance();

		// Store order_id for debug logging in filter.
		$this->current_earnings_order_id = $tutor_order_id;

		error_log( '[TP-PMPRO Earnings] Calculating earnings for Tutor order #' . $tutor_order_id );

		// Debug: Check if order items exist
		$items_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}tutor_order_items WHERE order_id = %d",
				$tutor_order_id
			)
		);
		error_log( '[TP-PMPRO Earnings] Order items found in database: ' . $items_count );

		// Debug: Check order item data before earnings calculation
		$order_item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, order_id, item_id, regular_price, sale_price FROM {$wpdb->prefix}tutor_order_items WHERE order_id = %d LIMIT 1",
				$tutor_order_id
			)
		);
		if ( $order_item ) {
			error_log( sprintf(
				'[TP-PMPRO Earnings] Order item data: id=%d, item_id=%d, regular_price=%s, sale_price=%s',
				$order_item->id,
				$order_item->item_id,
				$order_item->regular_price,
				$order_item->sale_price
			) );
		}

		// Debug: Check revenue sharing settings
		$revenue_sharing_enabled = tutor_utils()->get_option( 'enable_revenue_sharing' );
		$instructor_commission = tutor_utils()->get_option( 'earning_instructor_commission' );
		$admin_commission = tutor_utils()->get_option( 'earning_admin_commission' );
		error_log( sprintf(
			'[TP-PMPRO Earnings] Revenue sharing: enabled=%s, instructor_rate=%s%%, admin_rate=%s%%',
			$revenue_sharing_enabled ? 'yes' : 'no',
			$instructor_commission,
			$admin_commission
		) );

		// Prepare earnings data (calculates commission split using global settings).
		// Note: This is a void method that populates $earnings->earning_data internally.
		$earnings->prepare_order_earnings( $tutor_order_id );

		// Check if earnings were prepared
		if ( ! empty( $earnings->earning_data ) ) {
			error_log( '[TP-PMPRO Earnings] Earning data prepared: ' . count( $earnings->earning_data ) . ' record(s)' );
			
			// Debug: Log the earning data before storing
			foreach ( $earnings->earning_data as $index => $earning_record ) {
				error_log( sprintf(
					'[TP-PMPRO Earnings] Earning record #%d: course_id=%s, user_id=%s, instructor_amount=%s, admin_amount=%s, course_price_total=%s',
					$index,
					isset( $earning_record['course_id'] ) ? $earning_record['course_id'] : 'N/A',
					isset( $earning_record['user_id'] ) ? $earning_record['user_id'] : 'N/A',
					isset( $earning_record['instructor_amount'] ) ? $earning_record['instructor_amount'] : 'N/A',
					isset( $earning_record['admin_amount'] ) ? $earning_record['admin_amount'] : 'N/A',
					isset( $earning_record['course_price_total'] ) ? $earning_record['course_price_total'] : 'N/A'
				) );
			}
			
			// Store earnings in database.
			// Note: store_earnings() uses the internal earning_data array, takes no parameters.
			try {
				$result = $earnings->store_earnings();
				error_log( '[TP-PMPRO Earnings] store_earnings SUCCESS - last insert ID: ' . $result );
			} catch ( \Exception $e ) {
				error_log( '[TP-PMPRO Earnings] store_earnings FAILED: ' . $e->getMessage() );
			}
		} else {
			error_log( '[TP-PMPRO Earnings] No earning data prepared - $earnings->earning_data is empty' );
		}

		// Clear debug flag.
		$this->current_earnings_order_id = null;
	}

	/**
	 * Check if revenue sharing is enabled
	 *
	 * @since 1.6.0
	 *
	 * @return bool True if enabled, false otherwise.
	 */
	private function is_revenue_sharing_enabled() {
		return (bool) tutor_utils()->get_option( 'enable_revenue_sharing' );
	}

	/**
	 * Fix: Correct zero earnings amounts for PMPro subscription orders
	 *
	 * Tutor Core's get_item_sold_price() sometimes returns 0 for PMPro subscription orders,
	 * causing earnings to be zeroed out. This filter intercepts the earnings calculation
	 * and recalculates amounts using the actual order total when needed.
	 *
	 * @since 1.6.0
	 *
	 * @param array $pro_arg Array with earning calculation data.
	 * @return array Modified array with corrected amounts.
	 */
	public function fix_subscription_earnings_calculation( $pro_arg ) {
		// Only process during earnings calculation for PMPro orders.
		if ( empty( $this->current_earnings_order_id ) ) {
			return $pro_arg;
		}

		// If course_price_grand_total is 0 but we know the order has a valid amount,
		// recalculate instructor_amount and admin_amount using the correct price.
		if ( isset( $pro_arg['course_price_grand_total'] ) && $pro_arg['course_price_grand_total'] == 0 ) {
			// Get the actual order amount from our order.
			global $wpdb;
			$order = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT total_price FROM {$wpdb->prefix}tutor_orders WHERE id = %d",
					$this->current_earnings_order_id
				)
			);
			
			if ( $order && $order->total_price > 0 ) {
				$correct_price = floatval( $order->total_price );
				
				// Recalculate amounts using correct price.
				$pro_arg['course_price_grand_total'] = $correct_price;
				
				// Recalculate instructor and admin amounts if they're also 0.
				if ( $pro_arg['instructor_amount'] == 0 && $pro_arg['admin_amount'] == 0 ) {
					$instructor_rate = isset( $pro_arg['instructor_rate'] ) ? floatval( $pro_arg['instructor_rate'] ) : 0;
					$admin_rate = isset( $pro_arg['admin_rate'] ) ? floatval( $pro_arg['admin_rate'] ) : 100;
					
					$pro_arg['instructor_amount'] = $instructor_rate > 0 ? ( ( $correct_price * $instructor_rate ) / 100 ) : 0;
					$pro_arg['admin_amount'] = $admin_rate > 0 ? ( ( $correct_price * $admin_rate ) / 100 ) : 0;
				}
			}
		}
		
		return $pro_arg;
	}

	/**
	 * Filter: Ensure Tutor Core can find course_id for PMPro subscription orders
	 *
	 * Tutor Core uses this filter to get the course_id from subscription plan/item_id.
	 * For PMPro orders, the item_id in order_items is the course_id.
	 *
	 * @since 1.6.0
	 *
	 * @param int|null $course_id Course ID (null if not found).
	 * @param object   $order     Tutor order object.
	 * @return int|null Course ID.
	 */
	public function filter_subscription_course_by_plan( $course_id, $order ) {
		// Debug logging.
		error_log( sprintf(
			'[TP-PMPRO Earnings] filter_subscription_course_by_plan called: course_id=%s, order_id=%s, payment_method=%s',
			$course_id ? $course_id : 'null',
			isset( $order->id ) ? $order->id : 'N/A',
			isset( $order->payment_method ) ? $order->payment_method : 'N/A'
		) );

		// If course_id already found, return it.
		if ( ! empty( $course_id ) ) {
			error_log( '[TP-PMPRO Earnings] filter_subscription_course_by_plan: course_id already set, returning ' . $course_id );
			return $course_id;
		}

		// Check if this is a PMPro order.
		if ( empty( $order->payment_method ) || $order->payment_method !== 'pmpro' ) {
			error_log( '[TP-PMPRO Earnings] filter_subscription_course_by_plan: Not a PMPro order, returning course_id' );
			return $course_id;
		}

		// For PMPro orders, get course_id from order items.
		// The item_id in tutor_order_items is the course_id.
		global $wpdb;
		$order_item = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT item_id FROM {$wpdb->prefix}tutor_order_items WHERE order_id = %d LIMIT 1",
				$order->id
			)
		);

		if ( ! empty( $order_item->item_id ) ) {
			// Verify this is a valid course.
			$post_type = get_post_type( $order_item->item_id );
			if ( $post_type === tutor()->course_post_type ) {
				error_log( '[TP-PMPRO Earnings] filter_subscription_course_by_plan: Found course_id ' . $order_item->item_id . ' for PMPro order #' . $order->id );
				return (int) $order_item->item_id;
			}
		}

		return $course_id;
	}

	/**
	 * Filter: Prevent Tutor Core from treating PMPro subscriptions as membership plans
	 *
	 * Tutor Core checks if a subscription is a "membership plan" and sets course_id to null if so.
	 * We need to ensure PMPro subscriptions are NOT treated as membership plans.
	 *
	 * @since 1.6.0
	 *
	 * @param object|null $plan_info Plan info object (null if not found).
	 * @param int         $item_id   Item ID (course_id for PMPro orders).
	 * @return object|null Plan info object.
	 */
	public function filter_get_plan_info( $plan_info, $item_id ) {
		// If plan_info already set, return it (let other plugins handle it).
		if ( ! empty( $plan_info ) ) {
			return $plan_info;
		}

		// Check if this is a PMPro course by checking if there's a PMPro level with this course_id.
		global $wpdb;
		$pmpro_level = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT pmpro_membership_level_id FROM {$wpdb->prefix}pmpro_membership_levelmeta
				 WHERE meta_key = 'tutorpress_course_id' AND meta_value = %d
				 LIMIT 1",
				$item_id
			)
		);

		// If this is a PMPro course, return null to indicate it's NOT a Tutor membership plan.
		// This ensures Tutor Core treats it as a course subscription, not a membership plan.
		if ( ! empty( $pmpro_level ) ) {
			error_log( '[TP-PMPRO Earnings] filter_get_plan_info: PMPro course ' . $item_id . ' is NOT a Tutor membership plan' );
			return null;
		}

		return $plan_info;
	}

	/**
	 * Add order meta
	 *
	 * @since 1.6.0
	 *
	 * @param int    $order_id   Tutor order ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param int    $user_id    User ID for created_by/updated_by.
	 * @return void
	 */
	private function add_order_meta( $order_id, $meta_key, $meta_value, $user_id ) {
		global $wpdb;

		$current_user_id = get_current_user_id();
		$creator_id = $current_user_id ? $current_user_id : $user_id;

		$wpdb->insert(
			$wpdb->prefix . 'tutor_ordermeta',
			array(
				'order_id'       => $order_id,
				'meta_key'       => $meta_key,
				'meta_value'     => $meta_value,
				'created_at_gmt' => current_time( 'mysql', true ),
				'created_by'     => $creator_id,
				'updated_by'     => $creator_id,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d' )
		);
	}
}


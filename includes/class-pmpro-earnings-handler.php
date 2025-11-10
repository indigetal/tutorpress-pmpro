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

		// Subscription renewal payments.
		add_action( 'pmpro_subscription_payment_completed', array( $this, 'handle_subscription_renewal' ), 10, 1 );

		// Cancellations/Refunds.
		add_action( 'pmpro_updated_order', array( $this, 'handle_order_status_change' ), 10, 2 );
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

		// Determine order type.
		$is_subscription = ! empty( $level->cycle_number ) || ! empty( $level->billing_amount );
		$order_type      = $is_subscription ? OrderModel::TYPE_SUBSCRIPTION : OrderModel::TYPE_SINGLE_ORDER;

		// Get the amount paid (use initial_payment for subscriptions, otherwise use total).
		$amount = ! empty( $morder->total ) ? $morder->total : $level->initial_payment;

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
		// Skip if revenue sharing is disabled.
		if ( ! $this->is_revenue_sharing_enabled() ) {
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
			// Not a course-specific level - skip earnings.
			return;
		}

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
		$order_data = array(
			'order_type'        => $args['order_type'],
			'order_status'      => OrderModel::ORDER_COMPLETED,
			'payment_status'    => OrderModel::PAYMENT_PAID,
			'user_id'           => $args['user_id'],
			'total_price'       => $args['amount'],
			'subtotal_price'    => $args['amount'],
			'regular_price'     => $args['amount'],
			'coupon_amount'     => 0,
			'discount_amount'   => 0,
			'tax_amount'        => 0,
			'payment_method'    => 'pmpro',
			'created_at_gmt'    => current_time( 'mysql', true ),
			'order_key'         => 'pmpro_' . $args['pmpro_order_id'] . '_' . time(),
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
				'%f', // regular_price
				'%f', // coupon_amount
				'%f', // discount_amount
				'%f', // tax_amount
				'%s', // payment_method
				'%s', // created_at_gmt
				'%s', // order_key
			)
		);

		if ( ! $inserted ) {
			return false;
		}

		$order_id = $wpdb->insert_id;

		// Insert order item (course link).
		$item_data = array(
			'order_id'      => $order_id,
			'item_id'       => $args['course_id'],
			'regular_price' => $args['amount'],
			'sale_price'    => $args['amount'],
			'item_type'     => 'course',
		);

		$wpdb->insert(
			$wpdb->prefix . 'tutor_order_items',
			$item_data,
			array( '%d', '%d', '%f', '%f', '%s' )
		);

		// Store cross-reference meta for bidirectional lookup.
		$this->add_order_meta( $order_id, 'pmpro_order_id', $args['pmpro_order_id'] );
		$this->add_order_meta( $order_id, 'pmpro_level_id', $args['pmpro_level_id'] );

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
		$earnings = Earnings::get_instance();

		// Prepare earnings data (calculates commission split using global settings).
		$earning_data = $earnings->prepare_order_earnings( $tutor_order_id );

		// Store earnings in database.
		if ( $earning_data ) {
			$earnings->store_earnings( $earning_data );
		}
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
	 * Add order meta
	 *
	 * @since 1.6.0
	 *
	 * @param int    $order_id  Tutor order ID.
	 * @param string $meta_key  Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	private function add_order_meta( $order_id, $meta_key, $meta_value ) {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'tutor_ordermeta',
			array(
				'order_id'   => $order_id,
				'meta_key'   => $meta_key,
				'meta_value' => $meta_value,
			),
			array( '%d', '%s', '%s' )
		);
	}
}


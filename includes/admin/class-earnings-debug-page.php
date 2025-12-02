<?php
/**
 * Earnings Debug Page
 *
 * Admin page for testing and debugging earnings cleanup.
 * Only available in development environments.
 *
 * @package TutorPress_PMPro
 * @since 1.6.0
 */

namespace TUTORPRESS_PMPRO\Admin;

use TUTORPRESS_PMPRO\PMPro_Earnings_Handler;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Earnings_Debug_Page Class
 *
 * @since 1.6.0
 */
class Earnings_Debug_Page {

	/**
	 * Initialize the debug page
	 *
	 * @since 1.6.0
	 */
	public static function init() {
		// Add tab to Tutor LMS Tools page.
		add_filter( 'tutor_tool_pages', array( __CLASS__, 'add_tools_tab' ), 10, 1 );
		
		// AJAX handler for cleanup.
		add_action( 'wp_ajax_tpp_cleanup_orphaned_earnings', array( __CLASS__, 'ajax_cleanup_orphaned' ) );
	}

	/**
	 * Add Earnings Debug tab to Tutor Tools page
	 *
	 * @since 1.6.0
	 *
	 * @param array $attr_tools Existing tools tabs.
	 * @return array Modified tools tabs.
	 */
	public static function add_tools_tab( $attr_tools ) {
		$attr_tools['earnings_debug'] = array(
			'label'     => __( 'Commissions Log', 'tutorpress-pmpro' ),
			'slug'      => 'earnings_debug',
			'desc'      => __( 'Debug and maintain revenue sharing earnings and withdrawal data', 'tutorpress-pmpro' ),
			'template'  => 'earnings-debug',
			'view_path' => TUTORPRESS_PMPRO_DIR . 'views/tools/',
			'icon'      => 'tutor-icon-dollar',
			'blocks'    => array(),
		);

		return $attr_tools;
	}

	/**
	 * Get earnings statistics
	 *
	 * @since 1.6.0
	 *
	 * @return array Statistics array.
	 */
	public static function get_earnings_stats() {
		global $wpdb;
		$handler = PMPro_Earnings_Handler::get_instance();

		$total_earnings = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}tutor_earnings"
		);

		$orphaned_count = count( $handler->find_orphaned_earnings() );

		$valid_earnings = $wpdb->get_var(
			"SELECT COUNT(DISTINCT e.order_id)
			 FROM {$wpdb->prefix}tutor_earnings e
			 INNER JOIN {$wpdb->prefix}tutor_ordermeta om 
			 	ON e.order_id = om.order_id 
			 	AND om.meta_key = 'pmpro_order_id'
			 INNER JOIN {$wpdb->prefix}pmpro_membership_orders pmo
			 	ON pmo.id = om.meta_value
			 WHERE pmo.status = 'success'"
		);

		return array(
			'total'    => $total_earnings,
			'valid'    => $valid_earnings,
			'orphaned' => $orphaned_count,
		);
	}

	/**
	 * Render detailed earnings report
	 *
	 * @since 1.6.0
	 *
	 * @return void
	 */
	public static function render_earnings_report() {
		global $wpdb;

		$query = "
			SELECT 
				e.earning_id,
				e.order_id as tutor_order_id,
				e.instructor_amount,
				e.admin_amount,
				e.course_price_total,
				om.meta_value as pmpro_order_id,
				pmo.id as actual_pmpro_id,
				pmo.total as pmpro_total,
				pmo.status as pmpro_status,
				CASE 
					WHEN pmo.id IS NULL THEN 'ORPHANED'
					WHEN pmo.status != 'success' THEN 'INVALID'
					ELSE 'VALID'
				END as earning_status,
				e.created_at
			FROM {$wpdb->prefix}tutor_earnings e
			INNER JOIN {$wpdb->prefix}tutor_ordermeta om 
				ON e.order_id = om.order_id 
				AND om.meta_key = 'pmpro_order_id'
			LEFT JOIN {$wpdb->prefix}pmpro_membership_orders pmo 
				ON pmo.id = om.meta_value
			ORDER BY e.created_at DESC
			LIMIT 50
		";

		$results = $wpdb->get_results( $query );

		if ( empty( $results ) ) {
			echo '<p>No earnings records found.</p>';
			return;
		}

		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Earning ID</th>
					<th>Tutor Order</th>
					<th>PMPro Order</th>
					<th>Instructor</th>
					<th>Admin</th>
					<th>Status</th>
					<th>Created</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->earning_id ); ?></td>
						<td>#<?php echo esc_html( $row->tutor_order_id ); ?></td>
						<td>
							<?php if ( $row->actual_pmpro_id ) : ?>
								#<?php echo esc_html( $row->actual_pmpro_id ); ?>
							<?php else : ?>
								<span style="color: #999;">
									#<?php echo esc_html( $row->pmpro_order_id ); ?> (deleted)
								</span>
							<?php endif; ?>
						</td>
						<td>$<?php echo esc_html( number_format( $row->instructor_amount, 2 ) ); ?></td>
						<td>$<?php echo esc_html( number_format( $row->admin_amount, 2 ) ); ?></td>
						<td>
							<?php if ( $row->earning_status === 'VALID' ) : ?>
								<span class="dashicons dashicons-yes-alt" style="color: green;"></span> Valid
							<?php elseif ( $row->earning_status === 'ORPHANED' ) : ?>
								<span class="dashicons dashicons-warning" style="color: red;"></span> Orphaned
							<?php else : ?>
								<span class="dashicons dashicons-dismiss" style="color: orange;"></span> Invalid
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row->created_at ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description" style="margin-top: 10px;">Showing up to 50 most recent earnings records.</p>
		<?php
	}

	/**
	 * AJAX handler for cleanup orphaned earnings
	 *
	 * @since 1.6.0
	 */
	public static function ajax_cleanup_orphaned() {
		// Verify nonce.
		check_ajax_referer( 'tpp_cleanup_orphaned', 'nonce' );

		// Verify capability.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(
				'message' => esc_html__( 'You do not have sufficient permissions.', 'tutorpress-pmpro' ),
			) );
		}

		// Perform cleanup.
		$handler = PMPro_Earnings_Handler::get_instance();
		$result = $handler->cleanup_orphaned_earnings();

		// Send success response.
		wp_send_json_success( array(
			'message' => $result['message'],
			'deleted' => $result['deleted'],
		) );
	}

	/**
	 * Get withdrawal summary for an instructor (extracted from Withdraw_Debug_Page)
	 *
	 * @since 1.6.0
	 *
	 * @param int $instructor_id Instructor user ID.
	 * @return array|null Withdrawal summary or null if not available.
	 */
	public static function get_instructor_withdrawal_summary( $instructor_id ) {
		// Try both possible namespaces for WithdrawModel
		$withdraw_model_class = null;
		if ( class_exists( '\Tutor\Models\WithdrawModel' ) ) {
			$withdraw_model_class = '\Tutor\Models\WithdrawModel';
		} elseif ( class_exists( '\TUTOR\WithdrawModel' ) ) {
			$withdraw_model_class = '\TUTOR\WithdrawModel';
		}

		if ( $withdraw_model_class ) {
			return call_user_func( array( $withdraw_model_class, 'get_withdraw_summary' ), $instructor_id );
		}

		return null;
	}

	/**
	 * Get raw earnings records for an instructor
	 *
	 * @since 1.6.0
	 *
	 * @param int $instructor_id Instructor user ID.
	 * @return array Earnings records.
	 */
	public static function get_instructor_earnings( $instructor_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT earning_id, order_id, user_id, course_id, order_status, instructor_amount, admin_amount, course_price_total, created_at
				 FROM {$wpdb->prefix}tutor_earnings
				 WHERE user_id = %d
				 ORDER BY created_at DESC
				 LIMIT 20",
				$instructor_id
			)
		);
	}

	/**
	 * Get raw withdrawal records for an instructor
	 *
	 * @since 1.6.0
	 *
	 * @param int $instructor_id Instructor user ID.
	 * @return array Withdrawal records.
	 */
	public static function get_instructor_withdrawals( $instructor_id ) {
		global $wpdb;

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, amount, method_data, status, created_at
				 FROM {$wpdb->prefix}tutor_withdraws
				 WHERE user_id = %d
				 ORDER BY created_at DESC
				 LIMIT 20",
				$instructor_id
			)
		);
	}

	/**
	 * Calculate manual withdrawal summary for an instructor
	 *
	 * @since 1.6.0
	 *
	 * @param int $instructor_id Instructor user ID.
	 * @return array Manual calculation breakdown.
	 */
	public static function get_manual_withdrawal_calculation( $instructor_id ) {
		global $wpdb;

		$maturity_days = function_exists( 'tutor_utils' ) ? tutor_utils()->get_option( 'minimum_days_for_balance_to_be_available' ) : 30;

		$total_income = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(instructor_amount) FROM {$wpdb->prefix}tutor_earnings WHERE order_status='completed' AND user_id=%d",
				$instructor_id
			)
		);

		$total_withdraw = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$wpdb->prefix}tutor_withdraws WHERE status='approved' AND user_id=%d",
				$instructor_id
			)
		);

		$total_pending = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(amount) FROM {$wpdb->prefix}tutor_withdraws WHERE status='pending' AND user_id=%d",
				$instructor_id
			)
		);

		$total_matured = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(instructor_amount) FROM {$wpdb->prefix}tutor_earnings 
				 WHERE order_status='completed' AND user_id=%d AND DATEDIFF(NOW(), created_at) >= %d",
				$instructor_id,
				$maturity_days
			)
		);

		$current_balance = (float) $total_income - (float) $total_withdraw;
		$available_for_withdraw = max( 0, (float) $total_matured - (float) $total_withdraw );

		return array(
			'total_income'           => (float) $total_income,
			'total_withdrawn'        => (float) $total_withdraw,
			'total_pending'          => (float) $total_pending,
			'total_matured'          => (float) $total_matured,
			'maturity_days'          => $maturity_days,
			'current_balance'        => $current_balance,
			'available_for_withdraw' => $available_for_withdraw,
		);
	}
}

// Initialize the debug page.
Earnings_Debug_Page::init();


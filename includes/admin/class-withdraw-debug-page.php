<?php
/**
 * Withdraw Debug Page
 *
 * Admin tool to debug instructor withdrawal/earnings display issues.
 *
 * @package TutorPress_PMPro
 * @since 1.6.0
 */

namespace TUTORPRESS_PMPRO;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Withdraw_Debug_Page Class
 *
 * @since 1.6.0
 */
class Withdraw_Debug_Page {

	/**
	 * Initialize the debug page
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 100 );
	}

	/**
	 * Add admin menu page
	 *
	 * @return void
	 */
	public static function add_menu_page() {
		add_submenu_page(
			'tutor',
			__( 'Withdraw Debug', 'tutorpress-pmpro' ),
			__( 'Withdraw Debug', 'tutorpress-pmpro' ),
			'manage_options',
			'tpp-withdraw-debug',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the debug page
	 *
	 * @return void
	 */
	public static function render_page() {
		global $wpdb;

		// Get instructor user ID from query param or default to current user
		$instructor_id = isset( $_GET['instructor_id'] ) ? (int) $_GET['instructor_id'] : get_current_user_id();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Withdraw Debug Tool', 'tutorpress-pmpro' ); ?></h1>
			
			<form method="get">
				<input type="hidden" name="page" value="tpp-withdraw-debug">
				<label for="instructor_id">Instructor User ID:</label>
				<input type="number" name="instructor_id" id="instructor_id" value="<?php echo esc_attr( $instructor_id ); ?>">
				<button type="submit" class="button">Load Data</button>
			</form>

			<hr>

			<?php
			$user = get_userdata( $instructor_id );
			?>
			<h2>Instructor: <?php echo esc_html( $user->display_name ?? 'Unknown' ); ?> (ID: <?php echo esc_html( $instructor_id ); ?>)</h2>
			<p><strong>User Roles:</strong> <?php echo esc_html( implode( ', ', $user->roles ?? array() ) ); ?></p>
			<p><strong>Blog ID:</strong> <?php echo get_current_blog_id(); ?></p>
			<p><strong>Is Multisite:</strong> <?php echo is_multisite() ? 'Yes' : 'No'; ?></p>
			<p><strong>Table Prefix:</strong> <?php echo esc_html( $wpdb->prefix ); ?></p>
			<p><strong>Tutor LMS Active:</strong> <?php echo function_exists( 'tutor' ) ? 'Yes' : 'No'; ?></p>
			<p><strong>Revenue Sharing Enabled:</strong> <?php echo function_exists( 'tutor_utils' ) && tutor_utils()->get_option( 'enable_revenue_sharing' ) ? 'Yes' : 'No'; ?></p>
			<?php if ( is_multisite() ) : ?>
			<p><strong>Multisite Withdraw Fix Active:</strong> <?php echo class_exists( '\TUTORPRESS_PMPRO\Multisite\Withdraw_Summary_Fix' ) ? 'Yes ✅' : 'No ❌'; ?></p>
			<?php endif; ?>

			<hr>

			<?php if ( is_multisite() && class_exists( '\TUTORPRESS_PMPRO\Multisite\Withdraw_Summary_Fix' ) ) : ?>
			<h3>TutorPress PMPro Multisite Fix (Using Withdraw_Summary_Fix::get_withdraw_summary_multisite)</h3>
			<?php
			$fixed_summary = \TUTORPRESS_PMPRO\Multisite\Withdraw_Summary_Fix::get_withdraw_summary_multisite( $instructor_id );
			if ( $fixed_summary ) {
				echo '<div style="background: #d4edda; border-left: 4px solid #28a745; padding: 12px; margin: 16px 0;">';
				echo '<p style="margin: 0;"><strong>✅ Our multisite fix is working!</strong></p>';
				echo '</div>';
				echo '<table class="wp-list-table widefat">';
				echo '<tr><th>Property</th><th>Value</th></tr>';
				foreach ( $fixed_summary as $key => $value ) {
					echo '<tr>';
					echo '<td><strong>' . esc_html( $key ) . '</strong></td>';
					echo '<td>' . ( is_numeric( $value ) ? '$' . number_format( (float) $value, 2 ) : esc_html( $value ) ) . '</td>';
					echo '</tr>';
				}
				echo '</table>';
			} else {
				echo '<p style="color: red;">Our fix returned no data. This shouldn\'t happen.</p>';
			}
			?>

			<hr>
			<?php endif; ?>

			<h3>Tutor Core Withdraw Summary (Using WithdrawModel::get_withdraw_summary)</h3>
			<?php
			// Try both possible namespaces
			$withdraw_model_class = null;
			if ( class_exists( '\Tutor\Models\WithdrawModel' ) ) {
				$withdraw_model_class = '\Tutor\Models\WithdrawModel';
			} elseif ( class_exists( '\TUTOR\WithdrawModel' ) ) {
				$withdraw_model_class = '\TUTOR\WithdrawModel';
			}

			if ( $withdraw_model_class ) {
				$summary = call_user_func( array( $withdraw_model_class, 'get_withdraw_summary' ), $instructor_id );
				if ( $summary ) {
					echo '<table class="wp-list-table widefat">';
					echo '<tr><th>Property</th><th>Value</th></tr>';
					foreach ( $summary as $key => $value ) {
						echo '<tr>';
						echo '<td><strong>' . esc_html( $key ) . '</strong></td>';
						echo '<td>' . ( is_numeric( $value ) ? '$' . number_format( (float) $value, 2 ) : esc_html( $value ) ) . '</td>';
						echo '</tr>';
					}
					echo '</table>';
					echo '<p><strong>Key Fields:</strong></p>';
					echo '<ul>';
					echo '<li><strong>current_balance</strong>: Total income minus total withdrawn (should show in frontend)</li>';
					echo '<li><strong>available_for_withdraw</strong>: Matured earnings minus withdrawn (what instructor can withdraw now)</li>';
					echo '<li><strong>total_matured</strong>: Earnings older than maturity period</li>';
					echo '</ul>';
				} else {
					echo '<p>No summary data returned.</p>';
				}
			} else {
				echo '<p style="color: red;">WithdrawModel class not found. Tried: \Tutor\Models\WithdrawModel and \TUTOR\WithdrawModel</p>';
				echo '<p>This means Tutor LMS may not be active or is an older version.</p>';
			}
			?>

			<hr>

			<h3>Raw Earnings Query (Direct Database)</h3>
			<?php
			$earnings = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT earning_id, order_id, user_id, course_id, order_status, instructor_amount, admin_amount, course_price_total, created_at
					 FROM {$wpdb->prefix}tutor_earnings
					 WHERE user_id = %d
					 ORDER BY created_at DESC
					 LIMIT 20",
					$instructor_id
				)
			);

			if ( $earnings ) {
				echo '<table class="wp-list-table widefat fixed striped">';
				echo '<thead><tr>';
				echo '<th>Earning ID</th><th>Order ID</th><th>User ID</th><th>Course ID</th><th>Order Status</th><th>Instructor Amount</th><th>Admin Amount</th><th>Total</th><th>Created</th>';
				echo '</tr></thead><tbody>';
				foreach ( $earnings as $earning ) {
					echo '<tr>';
					echo '<td>' . esc_html( $earning->earning_id ) . '</td>';
					echo '<td>' . esc_html( $earning->order_id ) . '</td>';
					echo '<td>' . esc_html( $earning->user_id ) . '</td>';
					echo '<td>' . esc_html( $earning->course_id ) . '</td>';
					echo '<td><strong>' . esc_html( $earning->order_status ) . '</strong></td>';
					echo '<td>$' . esc_html( $earning->instructor_amount ) . '</td>';
					echo '<td>$' . esc_html( $earning->admin_amount ) . '</td>';
					echo '<td>$' . esc_html( $earning->course_price_total ) . '</td>';
					echo '<td>' . esc_html( $earning->created_at ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>No earnings found for this instructor.</p>';
			}
			?>

			<hr>

			<h3>Raw Withdrawals Query (Direct Database)</h3>
			<?php
			$withdrawals = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, amount, method_data, status, created_at
					 FROM {$wpdb->prefix}tutor_withdraws
					 WHERE user_id = %d
					 ORDER BY created_at DESC
					 LIMIT 20",
					$instructor_id
				)
			);

			if ( $withdrawals ) {
				echo '<table class="wp-list-table widefat fixed striped">';
				echo '<thead><tr>';
				echo '<th>ID</th><th>User ID</th><th>Amount</th><th>Status</th><th>Created</th>';
				echo '</tr></thead><tbody>';
				foreach ( $withdrawals as $withdrawal ) {
					echo '<tr>';
					echo '<td>' . esc_html( $withdrawal->id ) . '</td>';
					echo '<td>' . esc_html( $withdrawal->user_id ) . '</td>';
					echo '<td>$' . esc_html( $withdrawal->amount ) . '</td>';
					echo '<td><strong>' . esc_html( $withdrawal->status ) . '</strong></td>';
					echo '<td>' . esc_html( $withdrawal->created_at ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			} else {
				echo '<p>No withdrawals found for this instructor.</p>';
			}
			?>

			<hr>

			<h3>Manual Calculation</h3>
			<?php
			$maturity_days = tutor_utils()->get_option( 'minimum_days_for_balance_to_be_available' );
			
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

			$current_balance = $total_income - $total_withdraw;
			$available_for_withdraw = max( 0, $total_matured - $total_withdraw );

			echo '<table class="wp-list-table widefat">';
			echo '<tr><th>Total Income (completed orders)</th><td>$' . number_format( (float) $total_income, 2 ) . '</td></tr>';
			echo '<tr><th>Total Withdrawn (approved)</th><td>$' . number_format( (float) $total_withdraw, 2 ) . '</td></tr>';
			echo '<tr><th>Total Pending Withdrawals</th><td>$' . number_format( (float) $total_pending, 2 ) . '</td></tr>';
			echo '<tr><th>Total Matured (>= ' . $maturity_days . ' days)</th><td>$' . number_format( (float) $total_matured, 2 ) . '</td></tr>';
			echo '<tr><th><strong>Current Balance</strong></th><td><strong>$' . number_format( $current_balance, 2 ) . '</strong></td></tr>';
			echo '<tr><th><strong>Available for Withdraw</strong></th><td><strong>$' . number_format( $available_for_withdraw, 2 ) . '</strong></td></tr>';
			echo '</table>';

			if ( $current_balance < 0 ) {
				echo '<p style="color: red;"><strong>Note:</strong> Negative balance indicates the instructor has withdrawn more than they earned (likely due to refunds/cancellations after withdrawal).</p>';
			}

			if ( $available_for_withdraw == 0 && $total_income > 0 ) {
				echo '<p style="color: orange;"><strong>Note:</strong> Earnings exist but are not yet mature (must wait ' . $maturity_days . ' days) or have already been withdrawn.</p>';
			}
			?>

		</div>
		<?php
	}
}


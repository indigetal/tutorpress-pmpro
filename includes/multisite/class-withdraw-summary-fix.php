<?php
/**
 * Withdraw Summary Fix
 *
 * Fixes two issues with Tutor Core's withdraw functionality:
 * 1. WithdrawModel::get_withdraw_summary() fails on multisite (uses {$wpdb->prefix}users instead of {$wpdb->users})
 * 2. Price formatting shows no decimals when using PMPro (falls back to number_format_i18n with 0 decimals)
 *
 * @package TutorPress_PMPro
 * @since 1.6.0
 */

namespace TUTORPRESS_PMPRO\Multisite;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Withdraw_Summary_Fix Class
 *
 * @since 1.6.0
 */
class Withdraw_Summary_Fix {

	/**
	 * Initialize the fix
	 *
	 * @return void
	 */
	public static function init() {
		// Override the WithdrawModel class method by loading our wrapper early
		// This fixes both multisite withdraw summary bug AND price formatting for PMPro
		add_action( 'plugins_loaded', array( __CLASS__, 'override_withdraw_model' ), 1 );
	}

	/**
	 * Override WithdrawModel::get_withdraw_summary() for multisite
	 *
	 * @return void
	 */
	public static function override_withdraw_model() {
		// Check if Tutor's WithdrawModel class is loaded
		if ( ! class_exists( '\Tutor\Models\WithdrawModel' ) ) {
			return;
		}

		// Use a PHP trick: define a function in the global namespace that Tutor will call
		// Actually, we can't override a class method this way. Let's use runkit or create a template override.
		
		// The BEST solution: Copy the withdraw.php template to our plugin and modify it
		add_filter( 'tutor_get_template_path', array( __CLASS__, 'override_withdraw_template_path' ), 10, 2 );
	}

	/**
	 * Override the path to the withdraw template
	 *
	 * @param string $template_path Current template path.
	 * @param string $template Template name.
	 * @return string Modified template path.
	 */
	public static function override_withdraw_template_path( $template_path, $template ) {
		error_log( sprintf(
			'[TP-PMPRO Withdraw Fix] Template filter called: template=%s, path=%s',
			$template,
			$template_path
		) );

		// Only override dashboard/withdraw
		if ( strpos( $template, 'dashboard/withdraw' ) === false && strpos( $template, 'dashboard\withdraw' ) === false ) {
			return $template_path;
		}

		// Check if our custom template exists
		$custom_template = TUTORPRESS_PMPRO_DIR . 'templates/tutor/dashboard/withdraw.php';
		if ( file_exists( $custom_template ) ) {
			error_log( sprintf(
				'[TP-PMPRO Withdraw Fix] Using custom withdraw template: %s',
				$custom_template
			) );
			return $custom_template;
		}

		error_log( '[TP-PMPRO Withdraw Fix] Custom template not found, using default' );
		return $template_path;
	}

	/**
	 * Get withdraw summary with multisite-compatible query
	 *
	 * This is a fixed version of WithdrawModel::get_withdraw_summary() that uses
	 * {$wpdb->users} instead of {$wpdb->prefix}users for multisite compatibility.
	 *
	 * @param int $instructor_id Instructor user ID.
	 * @return object|null Summary data object.
	 */
	public static function get_withdraw_summary_multisite( $instructor_id ) {
		global $wpdb;

		$maturity_days = tutor_utils()->get_option( 'minimum_days_for_balance_to_be_available' );

		// This query is identical to Tutor Core's WithdrawModel::get_withdraw_summary()
		// EXCEPT we use {$wpdb->users} instead of {$wpdb->prefix}users for multisite compatibility
		//phpcs:disable WordPress.DB.PreparedSQLPlaceholders.QuotedSimplePlaceholder
		$data = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, display_name, 
                    total_income,
					total_withdraw, 
                    (total_income-total_withdraw) current_balance, 
                    total_matured,
					total_pending,
                    greatest(0, total_matured - total_withdraw) available_for_withdraw 
                
                FROM (
                        SELECT ID,display_name, 
                    COALESCE((SELECT SUM(instructor_amount) FROM {$wpdb->prefix}tutor_earnings WHERE order_status='%s' GROUP BY user_id HAVING user_id=u.ID),0) total_income,
                    
                        COALESCE((
                        SELECT sum(amount) total_withdraw FROM {$wpdb->prefix}tutor_withdraws 
                        WHERE status='%s'
                        GROUP BY user_id
                        HAVING user_id=u.ID
                    ),0) total_withdraw,
					
					COALESCE((
                        SELECT sum(amount) total_pending FROM {$wpdb->prefix}tutor_withdraws 
                        WHERE status='pending'
                        GROUP BY user_id
                        HAVING user_id=u.ID
                    ),0) total_pending,

                    COALESCE((
                        SELECT SUM(instructor_amount) FROM(
                            SELECT user_id, instructor_amount, created_at, DATEDIFF(NOW(),created_at) AS days_old FROM {$wpdb->prefix}tutor_earnings WHERE order_status='%s'
                        ) a
                        WHERE days_old >= %d
                        GROUP BY user_id
                        HAVING user_id = u.ID
                    ),0) total_matured
                    
                FROM {$wpdb->users} u WHERE u.ID=%d
                
                ) a",
				'completed',
				'approved',
				'completed',
				$maturity_days,
				$instructor_id
			)
		);
		//phpcs:enable WordPress.DB.PreparedSQLPlaceholders.QuotedSimplePlaceholder

		return $data;
	}
}


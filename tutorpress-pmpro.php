<?php
/**
 * Plugin Name: TutorPress - PMPro Integration
 * Plugin URI: https://www.paidmembershipspro.com/
 * Description: Integrate Paid Memberships Pro with Tutor LMS via the TutorPress addon.
 * Version: 0.1.67
 * Author: Indigetal WebCraft
 * Text Domain: tutorpress-pmpro
 */

defined( 'ABSPATH' ) || exit;

define( 'TUTORPRESS_PMPRO_VERSION', '0.1.67' );
define( 'TUTORPRESS_PMPRO_FILE', __FILE__ );
define( 'TUTORPRESS_PMPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'TUTORPRESS_PMPRO_BASENAME', plugin_basename( __FILE__ ) );
define( 'TP_PMPRO_LOG', defined( 'WP_DEBUG' ) && WP_DEBUG );

/**
 * Register addon in Tutor's addons list so it can be detected in the Addons UI.
 * This mirrors the minimal shape Tutor expects for addons.
 */
function tutorpress_pmpro_config( $config ) {
    $new_config = array(
        'name'           => __( 'Paid Memberships Pro (TutorPress)', 'tutorpress-pmpro' ),
        'description'    => __( 'Paid Memberships Pro integration for TutorPress (Gutenberg)', 'tutorpress-pmpro' ),
        'depend_plugins' => array( 'paid-memberships-pro/paid-memberships-pro.php' => 'Paid Memberships Pro' ),
    );

    $basic = (array) TUTORPRESS_PMPRO();
    $new_config = array_merge( $new_config, $basic );

    $config[ plugin_basename( TUTORPRESS_PMPRO_DIR . basename( __FILE__ ) ) ] = $new_config;
    return $config;
}
add_filter( 'tutor_addons_lists_config', 'tutorpress_pmpro_config' );

if ( ! function_exists( 'TUTORPRESS_PMPRO' ) ) {
    function TUTORPRESS_PMPRO() {
        $info = array(
            'path'                => TUTORPRESS_PMPRO_DIR,
            'url'                 => plugin_dir_url( __FILE__ ),
            'basename'            => TUTORPRESS_PMPRO_BASENAME,
            'version'             => TUTORPRESS_PMPRO_VERSION,
            'nonce_action'        => 'tutor_nonce_action',
            'nonce'               => '_wpnonce',
            'required_pro_plugin' => false,
            // Required plugin flags
            'requires_tutor'      => true,
            'requires_pmpro'      => true,
            'requires_tutorpress' => true,
        );

        return (object) $info;
    }
}

require_once TUTORPRESS_PMPRO_DIR . 'includes/init.php';
require_once TUTORPRESS_PMPRO_DIR . 'includes/earnings/class-pmpro-earnings-handler.php';
require_once TUTORPRESS_PMPRO_DIR . 'includes/admin/class-earnings-debug-page.php';
require_once TUTORPRESS_PMPRO_DIR . 'includes/admin/class-withdraw-debug-page.php';

// Instantiate the integration.
if ( class_exists( '\\TUTORPRESS_PMPRO\\Init' ) ) {
	new \TUTORPRESS_PMPRO\Init();
}

// Initialize revenue sharing integration (Tutor Core).
// Multisite: Ensure this runs on ALL sites where the plugin is active
if ( class_exists( '\\TUTORPRESS_PMPRO\\PMPro_Earnings_Handler' ) ) {
	\TUTORPRESS_PMPRO\PMPro_Earnings_Handler::get_instance();
	
	// Multisite webhook fix: Register hooks globally so they fire regardless of which site processes the webhook
	if ( is_multisite() ) {
		add_action( 'pmpro_after_checkout', function( $user_id, $morder ) {
			// Determine which site this order belongs to by checking the membership level's site
			$current_blog_id = get_current_blog_id();
			
			error_log( sprintf(
				'[TP-PMPRO Earnings] pmpro_after_checkout fired in blog_id=%d for user=%d order=%d level=%d',
				$current_blog_id,
				$user_id,
				$morder->id,
				$morder->membership_id
			) );
			
			// If we're not on the right site, try to find the correct site
			if ( $current_blog_id === 1 || $current_blog_id === get_main_site_id() ) {
				// We're on the main site - need to find which subsite owns this level
				global $wpdb;
				
				// Search all sites for this membership level
				$sites = get_sites( array( 'number' => 100 ) );
				foreach ( $sites as $site ) {
					switch_to_blog( $site->blog_id );
					
					// Check if this level exists on this site
					$level_exists = $wpdb->get_var( $wpdb->prepare(
						"SELECT id FROM {$wpdb->prefix}pmpro_membership_levels WHERE id = %d",
						$morder->membership_id
					) );
					
					if ( $level_exists ) {
						error_log( sprintf(
							'[TP-PMPRO Earnings] Found level %d on blog_id=%d, processing earnings there',
							$morder->membership_id,
							$site->blog_id
						) );
						
						// Process earnings in the correct site context
						\TUTORPRESS_PMPRO\PMPro_Earnings_Handler::get_instance()->handle_checkout_complete( $user_id, $morder );
						restore_current_blog();
						return;
					}
					
					restore_current_blog();
				}
				
				error_log( sprintf(
					'[TP-PMPRO Earnings] Could not find site for level %d, webhook processed on wrong site',
					$morder->membership_id
				) );
			}
		}, 5, 2 ); // Priority 5 to run before the instance's hook at priority 10
	}
}

// Plugin deactivation - cleanup scheduled tasks.
register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'tpp_auto_cleanup_orphaned_earnings' );
} );

// Multisite: Create empty Tutor cart tables to prevent 500 errors.
require_once TUTORPRESS_PMPRO_DIR . 'includes/multisite/class-cart-table-fix.php';
\TUTORPRESS_PMPRO\Multisite\Cart_Table_Fix::init();

// Multisite: Fix WithdrawModel::get_withdraw_summary() which uses wrong users table.
require_once TUTORPRESS_PMPRO_DIR . 'includes/multisite/class-withdraw-summary-fix.php';
\TUTORPRESS_PMPRO\Multisite\Withdraw_Summary_Fix::init();

// Admin debug tools.
if ( is_admin() ) {
	\TUTORPRESS_PMPRO\Withdraw_Debug_Page::init();
}


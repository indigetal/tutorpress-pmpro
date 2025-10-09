<?php
/**
 * Plugin Name: TutorPress - PMPro Integration
 * Plugin URI: https://www.paidmembershipspro.com/
 * Description: Integrate Paid Memberships Pro with Tutor LMS via the TutorPress addon.
 * Version: 0.1.11
 * Author: Indigetal WebCraft
 * Text Domain: tutorpress-pmpro
 */

defined( 'ABSPATH' ) || exit;

define( 'TUTORPRESS_PMPRO_VERSION', '0.1.11' );
define( 'TUTORPRESS_PMPRO_FILE', __FILE__ );
define( 'TUTORPRESS_PMPRO_DIR', plugin_dir_path( __FILE__ ) );
define( 'TUTORPRESS_PMPRO_BASENAME', plugin_basename( __FILE__ ) );

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

// Instantiate the integration.
if ( class_exists( '\\TUTORPRESS_PMPRO\\Init' ) ) {
    new \TUTORPRESS_PMPRO\Init();
}



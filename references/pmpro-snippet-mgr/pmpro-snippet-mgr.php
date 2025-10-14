<?php
/*
Plugin Name: Paid Memberships Pro - Snippet Manager
Plugin URI:
Description: Addon that compiles a number of useful custom code snippets for Paid Memberships Pro in a user-friendly settings page.
Version: 1.0.02
Author: Brandon Meyer
Author URI: indigetal.com
*/

// Ensure PMPro is installed
if (!defined('PMPRO_DIR')) {
    return;
}

// Include settings page and functions
require_once plugin_dir_path(__FILE__) . 'includes/settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
?>

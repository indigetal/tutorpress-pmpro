<?php
/**
 * Multisite Cart Table Fix
 *
 * Creates empty Tutor cart tables on multisite to prevent 500 errors.
 * Tutor's cart system expects these tables to exist when checking if courses
 * are in the cart, but they're only created when using WooCommerce.
 *
 * @package TutorPress_PMPro
 * @subpackage Multisite
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Multisite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cart_Table_Fix
 *
 * Handles creation of empty Tutor cart tables on multisite installations
 * to prevent fatal errors when PMPro is used as the monetization engine.
 */
class Cart_Table_Fix {

	/**
	 * Initialize the fix.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function init() {
		// Only run on multisite
		if ( ! is_multisite() ) {
			return;
		}

		// Create tables on plugin activation
		register_activation_hook( TUTORPRESS_PMPRO_FILE, array( __CLASS__, 'create_cart_tables' ) );
		
		// Also check/create on wp_loaded (in case tables were deleted)
		add_action( 'wp_loaded', array( __CLASS__, 'create_cart_tables' ), 1 );
	}

	/**
	 * Create empty Tutor cart tables if they don't exist.
	 *
	 * These tables are required by Tutor's cart system (designed for WooCommerce).
	 * When PMPro is the monetization engine, the tables don't exist but Tutor
	 * still tries to query them, causing fatal errors on course pages.
	 *
	 * Creating empty tables allows the queries to succeed (returning no results)
	 * without errors, while our PMPro integration handles the actual purchasing.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public static function create_cart_tables() {
		global $wpdb;

		// Only run on multisite
		if ( ! is_multisite() ) {
			return;
		}

		$charset_collate = $wpdb->get_charset_collate();

		// Create tutor_carts table
		self::create_carts_table( $charset_collate );

		// Create tutor_cart_items table
		self::create_cart_items_table( $charset_collate );
	}

	/**
	 * Create the tutor_carts table.
	 *
	 * @since 1.0.0
	 * @param string $charset_collate Database charset collation.
	 * @return void
	 */
	private static function create_carts_table( $charset_collate ) {
		global $wpdb;

		$cart_table = $wpdb->prefix . 'tutor_carts';
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $cart_table ) );

		if ( ! $table_exists ) {
			$sql = "CREATE TABLE {$cart_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) $charset_collate;";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );

			error_log( sprintf(
				'[TP-PMPRO Multisite] Created empty %s table (blog ID: %d)',
				$cart_table,
				get_current_blog_id()
			) );
		}
	}

	/**
	 * Create the tutor_cart_items table.
	 *
	 * @since 1.0.0
	 * @param string $charset_collate Database charset collation.
	 * @return void
	 */
	private static function create_cart_items_table( $charset_collate ) {
		global $wpdb;

		$cart_items_table = $wpdb->prefix . 'tutor_cart_items';
		$table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $cart_items_table ) );

		if ( ! $table_exists ) {
			$sql = "CREATE TABLE {$cart_items_table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				cart_id bigint(20) unsigned NOT NULL,
				course_id bigint(20) unsigned NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY (id),
				KEY cart_id (cart_id),
				KEY course_id (course_id)
			) $charset_collate;";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );

			error_log( sprintf(
				'[TP-PMPRO Multisite] Created empty %s table (blog ID: %d)',
				$cart_items_table,
				get_current_blog_id()
			) );
		}
	}
}


<?php
/**
 * Handle Pro Assets
 *
 * @package TutorPro\Classes
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 1.0.0
 */

namespace TUTOR_PRO;

use Tutor\Ecommerce\OrderController;
use TUTOR\Input;
use TutorPro\Ecommerce\GuestCheckout\GuestCheckout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue styles & scripts
 */
class Assets {

	/**
	 * Register hooks
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', __CLASS__ . '::frontend_scripts' );
		add_action( 'login_enqueue_scripts', __CLASS__ . '::frontend_scripts' );

		add_action( 'admin_enqueue_scripts', array( $this, 'load_js_translations' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'load_js_translations' ), 100 );
		add_action( 'tutor_course_builder_before_wp_editor_load', array( $this, 'enqueue_tinymce_codesample_asset' ) );
	}

	/**
	 * Load JS translations
	 *
	 * @see https://make.wordpress.org/core/2018/11/09/new-javascript-i18n-support-in-wordpress/
	 *
	 * @since 2.6.0
	 *
	 * @return void
	 */
	public function load_js_translations() {
		wp_set_script_translations( 'tutor-pro-admin', 'tutor-pro', tutor_pro()->languages );
		wp_set_script_translations( 'tutor-pro-front', 'tutor-pro', tutor_pro()->languages );
	}

	/**
	 * Enqueue styles & scripts for the admin side
	 *
	 * @return void
	 */
	public function admin_scripts() {
		wp_enqueue_style( 'tutor-pro-admin', tutor_pro()->url . 'assets/css/admin.css', array(), TUTOR_PRO_VERSION );
		wp_enqueue_script( 'tutor-pro-admin', tutor_pro()->url . 'assets/js/admin.js', array( 'jquery' ), TUTOR_PRO_VERSION, true );

		// Enqueue TinyMCE codesample assets.
		self::enqueue_tinymce_codesample_asset();

		// Enqueue html2canvas and jsPDf.
		$invoice_id   = Input::get( 'invoice', 0, Input::TYPE_INT );
		$current_page = Input::get( 'page' );

		if ( OrderController::PAGE_SLUG === $current_page && $invoice_id ) {
			wp_enqueue_script( 'html2canvas', tutor_pro()->url . 'assets/lib/html2canvas/html2canvas.min.js', array( 'jquery' ), TUTOR_VERSION, true );
			wp_enqueue_script( 'jsPDf', tutor_pro()->url . 'assets/lib/jspdf/jspdf.umd.min.js', array( 'jquery' ), TUTOR_VERSION, true );
		}
	}

	/**
	 * Enqueue style & scripts on the frontend
	 *
	 * @since 3.3.0 Guest checkout js enqueued
	 *
	 * @return void
	 */
	public static function frontend_scripts() {
		self::enqueue_tinymce_codesample_asset();

		wp_enqueue_script( 'tutor-pro-front', tutor_pro()->url . 'assets/js/front.js', array( 'wp-i18n' ), TUTOR_PRO_VERSION, true );

		if ( 'wp-login.php' === $GLOBALS['pagenow'] ) {
			$current_page = tutor_utils()->get_current_page_slug();

			wp_localize_script(
				'tutor-pro-front',
				'_tutorobject',
				array(
					'ajaxurl'      => admin_url( 'admin-ajax.php' ),
					'nonce_key'    => tutor()->nonce,
					tutor()->nonce => wp_create_nonce( tutor()->nonce_action ),
					'current_page' => $current_page,
				)
			);
		}

		// Enqueue html2canvas and jsPDf.
		$invoice_id = Input::get( 'invoice', 0, Input::TYPE_INT );
		if ( get_query_var( 'tutor_dashboard_page' ) === 'purchase_history' && $invoice_id ) {
			wp_enqueue_script( 'html2canvas', tutor_pro()->url . 'assets/lib/html2canvas/html2canvas.min.js', array( 'jquery' ), TUTOR_VERSION, true );
			wp_enqueue_script( 'jsPDf', tutor_pro()->url . 'assets/lib/jspdf/jspdf.umd.min.js', array( 'jquery' ), TUTOR_VERSION, true );
		}

		if ( is_single() && tutor()->course_post_type === get_post_type( get_the_ID() ) ) {
			wp_enqueue_style( 'tutor-pro-course-details', tutor_pro()->url . 'assets/css/course-details.css', array(), TUTOR_VERSION );
		}

		wp_enqueue_style( 'tutor-pro-front', tutor_pro()->url . 'assets/css/front.css', array(), TUTOR_VERSION );

		if ( tutor_utils()->is_monetize_by_tutor() && GuestCheckout::is_enable() ) {
			wp_enqueue_script( 'tutor-pro-guest-checkout', tutor_pro()->url . 'assets/js/guest-checkout.js', array( 'jquery', 'wp-i18n' ), TUTOR_PRO_VERSION, true );
		}
	}

	/**
	 * Load codesample plugin css & js to support
	 * code snippet on the lesson & quiz
	 *
	 * @since v2.0.8
	 */
	public static function enqueue_tinymce_codesample_asset() {
		global $wp_query;
		$query_vars        = $wp_query->query_vars;
		$current_post_type = get_post_type();
		$dashboard_page    = $query_vars['tutor_dashboard_page'] ?? '';
		$page              = Input::get( 'page', '' );
		$editor_pages      = array( 'create-course', 'course-bundle', 'create-bundle', 'tutor_settings', 'tutor-content-bank' );

		if ( in_array( $current_post_type, array( tutor()->course_post_type, tutor()->bundle_post_type ), true ) ||
			in_array( $dashboard_page, $editor_pages, true ) ||
			in_array( $page, $editor_pages, true )
		) {
			if ( ! wp_script_is( 'wp-tinymce-root' ) ) {
				wp_enqueue_script( 'tutor-tiny', includes_url( 'js/tinymce' ) . '/tinymce.min.js', array( 'jquery' ), TUTOR_VERSION, true );
			}
			wp_enqueue_script( 'tutor-tinymce-codesample', tutor_pro()->url . 'assets/lib/codesample/prism.min.js', array( 'jquery' ), TUTOR_VERSION, true );
			wp_enqueue_script( 'tutor-tinymce-code', tutor_pro()->url . 'assets/lib/tinymce/code.plugin.min.js', array( 'jquery' ), TUTOR_VERSION, true );
		}

		wp_enqueue_style( 'tutor-prism-css', tutor_pro()->url . 'assets/lib/codesample/prism.css', array(), TUTOR_VERSION );
		wp_enqueue_script( 'tutor-prism-js', tutor_pro()->url . 'assets/lib/prism/prism.min.js', array( 'jquery' ), TUTOR_VERSION, true );
		wp_enqueue_script( 'tutor-prism-script', tutor_pro()->url . 'assets/lib/prism/script.js', array( 'jquery' ), TUTOR_VERSION, true );

	}
}

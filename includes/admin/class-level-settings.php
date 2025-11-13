<?php
/**
 * Level Settings Management
 *
 * Handles PMPro membership level configuration, including:
 * - Category-wise membership settings UI
 * - Full-site membership model settings
 * - Course-specific level settings
 * - Level highlight/recommendation settings
 * - Plugin options registration
 *
 * @package TutorPress_PMPro
 * @subpackage Admin
 * @since 1.0.0
 */

namespace TUTORPRESS_PMPRO\Admin;

use TUTOR\Input;

/**
 * Level Settings class.
 *
 * Manages the configuration and settings for PMPro membership levels
 * integrated with TutorPress courses and categories.
 *
 * @since 1.0.0
 */
class Level_Settings {

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_hooks() {
		// Level edit page hooks (always registered)
		add_action( 'pmpro_membership_level_after_other_settings', array( $this, 'display_courses_categories' ) );
		add_action( 'pmpro_save_membership_level', array( $this, 'pmpro_settings' ) );

		// Tutor LMS options hook (always registered)
		add_filter( 'tutor/options/attr', array( $this, 'add_options' ) );

		// Conditional hooks (only when PMPro is active monetization method)
		if ( tutor_utils()->has_pmpro( true ) ) {
			// Check if PMPro is the selected monetization engine
			$monetize_by = get_tutor_option( 'monetize_by' );
			if ( 'pmpro' !== $monetize_by ) {
				return;
			}

			// Remove Tutor's price column from courses list table
			add_filter( 'manage_' . tutor()->course_post_type . '_posts_columns', array( $this, 'remove_price_column' ), 11, 1 );

			// Add custom columns to PMPro levels table
			add_action( 'pmpro_membership_levels_table_extra_cols_header', array( $this, 'level_category_list' ) );
			add_action( 'pmpro_membership_levels_table_extra_cols_body', array( $this, 'level_category_list_body' ) );
			add_filter( 'pmpro_membership_levels_table', array( $this, 'outstanding_cat_notice' ) );

			// Enqueue admin styles
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_script' ) );
		}
	}

	/**
	 * Remove Tutor's price column from courses list table.
	 *
	 * When PMPro is the active monetization method, Tutor's price column
	 * is not relevant since pricing is managed through membership levels.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns Columns array.
	 * @return array Modified columns array.
	 */
	public function remove_price_column( $columns = array() ) {
		if ( isset( $columns['price'] ) ) {
			unset( $columns['price'] );
		}
		return $columns;
	}

	/**
	 * Display course categories section on PMPro level edit page.
	 *
	 * Renders the TutorPress-specific settings section on the PMPro membership
	 * level edit page, including:
	 * - Membership model selection (full-site, category-wise, course-specific)
	 * - Category selection for category-wise memberships
	 * - Level highlight/recommendation toggle
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function display_courses_categories() {
		global $wpdb;

		if ( Input::has( 'edit' ) ) {
			$edit = intval( Input::sanitize_request_data( 'edit' ) );
		} else {
			$edit = false;
		}

		// get the level...
		if ( ! empty( $edit ) && $edit > 0 ) {
			$level   = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->pmpro_membership_levels
					WHERE id = %d LIMIT 1",
					$edit
				),
				OBJECT
			);
			$temp_id = $level->id;
		} elseif ( ! empty( $copy ) && $copy > 0 ) {
			$level     = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->pmpro_membership_levels
					WHERE id = %d LIMIT 1",
					$copy
				),
				OBJECT
			);
			$temp_id   = $level->id;
			$level->id = null;
		} elseif ( empty( $level ) ) {
			// didn't find a membership level, let's add a new one...
			$level                    = new \stdClass();
			$level->id                = null;
			$level->name              = null;
			$level->description       = null;
			$level->confirmation      = null;
			$level->billing_amount    = null;
			$level->trial_amount      = null;
			$level->initial_payment   = null;
			$level->billing_limit     = null;
			$level->trial_limit       = null;
			$level->expiration_number = null;
			$level->expiration_period = null;
			$edit                     = -1;
		}

		// defaults for new levels.
		if ( empty( $copy ) && -1 == $edit ) {
			$level->cycle_number = 1;
			$level->cycle_period = 'Month';
		}

		// grab the categories for the given level...
		if ( ! empty( $temp_id ) ) {
			$level->categories = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT c.category_id
					FROM $wpdb->pmpro_memberships_categories c
					WHERE c.membership_id = %d",
					$temp_id
				)
			);
		}

		if ( empty( $level->categories ) ) {
			$level->categories = array();
		}

		$level_categories = $level->categories;
		$highlight        = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_level_highlight', true );

		include_once \TUTORPRESS_PMPRO_DIR . 'views/pmpro-content-settings.php';
	}

	/**
	 * Save TutorPress-specific settings when PMPro level is saved.
	 *
	 * Saves:
	 * - Membership model (full-site, category-wise, course-specific)
	 * - Level highlight/recommendation status
	 *
	 * Security: Admin-only operation with capability check and audit logging.
	 *
	 * @since 1.0.0
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return void
	 */
	public function pmpro_settings( $level_id ) {

		if ( 'pmpro_settings' !== Input::post( 'tutor_action' ) ) {
			return;
		}

		// Admin-only gating: prevent non-admins from modifying membership model and highlight settings
		if ( ! current_user_can( 'manage_options' ) ) {
			// Log security audit trail
			if ( function_exists( 'error_log' ) ) {
				error_log( sprintf(
					'[TP-PMPRO] Unauthorized attempt to modify membership model for level %d by user %d',
					absint( $level_id ),
					get_current_user_id()
				) );
			}
			return;
		}

		$TUTORPRESS_PMPRO_membership_model = Input::post( 'TUTORPRESS_PMPRO_membership_model' );
		$highlight_level              = Input::post( 'TUTORPRESS_PMPRO_level_highlight' );

		if ( $TUTORPRESS_PMPRO_membership_model ) {
			update_pmpro_membership_level_meta( $level_id, 'TUTORPRESS_PMPRO_membership_model', $TUTORPRESS_PMPRO_membership_model );
		}

		if ( $highlight_level && 1 == $highlight_level ) {
			update_pmpro_membership_level_meta( $level_id, 'TUTORPRESS_PMPRO_level_highlight', 1 );
		} else {
			delete_pmpro_membership_level_meta( $level_id, 'TUTORPRESS_PMPRO_level_highlight' );
		}
	}

	/**
	 * Register TutorPress-PMPro plugin options in Tutor LMS settings.
	 *
	 * Adds the following settings:
	 * - Membership-Only Mode: Disable individual course sales
	 * - Money-back guarantee period (days)
	 * - No commitment message
	 *
	 * @since 1.0.0
	 *
	 * @param array $attr Tutor LMS options array.
	 * @return array Modified options array.
	 */
	public function add_options( $attr ) {
		$attr['TUTORPRESS_PMPRO'] = array(
			'label'    => __( 'PMPro-TutorPress', 'tutorpress-pmpro' ),
			'slug'     => 'pm-pro',
			'desc'     => __( 'Paid Membership', 'tutorpress-pmpro' ),
			'template' => 'basic',
			'icon'     => 'tutor-icon-brand-paid-membersip-pro',
			'blocks'   => array(
				array(
					'label'      => '',
					'slug'       => 'pm_pro',
					'block_type' => 'uniform',
					'fields'     => array(
						array(
							'key'     => 'tutorpress_pmpro_membership_only_mode',
							'type'    => 'toggle_switch',
							'label'   => __( 'Membership-Only Mode', 'tutorpress-pmpro' ),
							'label_title' => '',
							'default' => 'off',
							'desc'    => __( 'Enable this to sell courses exclusively through membership plans. Individual course sales will be disabled.', 'tutorpress-pmpro' ),
						),
						array(
							'key'     => 'pmpro_moneyback_day',
							'type'    => 'number',
							'label'   => __( 'Moneyback gurantee in', 'tutorpress-pmpro' ),
							'default' => '0',
							'desc'    => __( 'Days in you gurantee moneyback. Set 0 for no moneyback.', 'tutorpress-pmpro' ),
						),
						array(
							'key'     => 'pmpro_no_commitment_message',
							'type'    => 'text',
							'label'   => __( 'No commitment message', 'tutorpress-pmpro' ),
							'default' => '',
							'desc'    => __( 'Keep empty to hide', 'tutorpress-pmpro' ),
						),
					),
				),
			),
		);

		return $attr;
	}

	/**
	 * Add custom column headers to PMPro levels table.
	 *
	 * Adds "Recommended" and "Type" column headers to the membership levels
	 * list table in PMPro admin.
	 *
	 * @since 1.0.0
	 *
	 * @param array $reordered_levels Reordered levels array (unused).
	 * @return void
	 */
	public function level_category_list( $reordered_levels ) {
		echo '<th>' . esc_html__( 'Recommended', 'tutorpress-pmpro' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'tutorpress-pmpro' ) . '</th>';
	}

	/**
	 * Display custom column content in PMPro levels table.
	 *
	 * Shows:
	 * - Recommended/highlight status (star icon)
	 * - Membership type (Full Site, Category-wise with categories listed, or blank for course-specific)
	 *
	 * @since 1.0.0
	 *
	 * @param object $level PMPro membership level object.
	 * @return void
	 */
	public function level_category_list_body( $level ) {
		$model     = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_membership_model', true );
		$highlight = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_level_highlight', true );

		echo '<td>' . ( esc_html( $highlight ) ? '<img src="' . esc_url( \TUTORPRESS_PMPRO()->url . 'assets/images/star.svg' ) . '"/>' : '' ) . '</td>';

		echo '<td>';

		if ( 'full_website_membership' === $model ) {
			echo '<b>' . esc_html__( 'Full Site Membership', 'tutorpress-pmpro' ) . '</b>';
		} elseif ( 'category_wise_membership' === $model ) {
			echo '<b>' . esc_html__( 'Category Wise Membership', 'tutorpress-pmpro' ) . '</b><br/>';

			$cats = pmpro_getMembershipCategories( $level->id );

			if ( is_array( $cats ) && count( $cats ) ) {
				global $wpdb;
				$cats_str   = \TUTOR\QueryHelper::prepare_in_clause( $cats );
				$terms      = $wpdb->get_results( "SELECT * FROM {$wpdb->terms} WHERE term_id IN (" . $cats_str . ')' );
				$term_links = array_map(
					function( $term ) {
						return '<small>' . $term->name . '</small>';
					},
					$terms
				);

				echo wp_kses_post( implode( ', ', $term_links ) );
			}
		}
		//phpcs:enable

		echo '</td>';
	}

	/**
	 * Display notice for course categories not assigned to any membership level.
	 *
	 * Appends a notice to the PMPro levels table showing which course categories
	 * are not yet assigned to any membership level.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Existing HTML content.
	 * @return string HTML with notice appended.
	 */
	public function outstanding_cat_notice( $html ) {
		global $wpdb;

		// Get all categories from all levels.
		$level_cats = $wpdb->get_col(
			"SELECT cat.category_id 
			FROM {$wpdb->pmpro_memberships_categories} cat 
				INNER JOIN {$wpdb->pmpro_membership_levels} lvl ON lvl.id=cat.membership_id"
		);
		! is_array( $level_cats ) ? $level_cats = array() : 0;

		// Get all categories and check if exist in any level.
		$outstanding = array();
		$course_cats = get_terms( 'course-category', array( 'hide_empty' => false ) );
		foreach ( $course_cats as $cat ) {
			! in_array( $cat->term_id, $level_cats ) ? $outstanding[] = $cat : 0;
		}

		ob_start();

		//phpcs:ignore
		extract( $this->get_pmpro_currency() ); // $currency_symbol, $currency_position
		include \TUTORPRESS_PMPRO_DIR . 'views/outstanding-catagory-notice.php';

		return $html . ob_get_clean();
	}

	/**
	 * Enqueue admin styles on PMPro level pages.
	 *
	 * Loads custom CSS for the membership levels list/edit pages.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function admin_script() {
		$screen = get_current_screen();
		if ( 'memberships_page_pmpro-membershiplevels' === $screen->id ) {
			wp_enqueue_style( 'tutorpress-pmpro', \TUTORPRESS_PMPRO()->url . 'assets/css/pm-pro.css', array(), TUTORPRESS_PMPRO_VERSION );
		}
	}

	/**
	 * Get PMPro currency settings.
	 *
	 * Helper method to retrieve currency symbol and position from PMPro.
	 *
	 * @since 1.0.0
	 * @return array Associative array with 'currency_symbol' and 'currency_position' keys.
	 */
	private function get_pmpro_currency() {
		global $pmpro_currencies, $pmpro_currency;
		$current_currency = $pmpro_currency ? $pmpro_currency : '';
		$currency         = 'USD' === $current_currency ?
								array( 'symbol' => '$' ) :
								( isset( $pmpro_currencies[ $current_currency ] ) ? $pmpro_currencies[ $current_currency ] : null );

		$currency_symbol   = ( is_array( $currency ) && isset( $currency['symbol'] ) ) ? $currency['symbol'] : '';
		$currency_position = ( is_array( $currency ) && isset( $currency['position'] ) ) ? strtolower( $currency['position'] ) : 'left';

		return compact( 'currency_symbol', 'currency_position' );
	}
}


<?php
/**
 * User Notification Preference Manager
 *
 * @package TutorPro
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.1.0
 */

namespace TUTOR_PRO;

use Tutor\Helpers\QueryHelper;
use TUTOR\Input;
use Tutor\Traits\JsonResponse;
use TUTOR\User;
use TUTOR_EMAIL\EmailData;
use TUTOR_EMAIL\EmailNotification;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NotificationPreference Class
 *
 * @since 3.1.0
 */
class NotificationPreference {
	use JsonResponse;

	/**
	 * Notification preference.
	 *
	 * @since 3.1.0
	 *
	 * @var string
	 */
	public $table_name;

	/**
	 * Register hooks and dependencies
	 *
	 * @since 3.1.0
	 *
	 * @param boolean $register_hooks register hooks or not.
	 */
	public function __construct( $register_hooks = true ) {
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'tutor_notification_preferences';

		if ( ! $register_hooks ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'create_table' ) );
		add_filter( 'tutor_is_notification_enabled_for_user', array( $this, 'check_notification_enabled_for_user' ), 10, 5 );

		add_action(
			'tutor_email_addon_loaded',
			function () {

				if ( ! function_exists( 'wp_get_current_user' ) ) {
					include ABSPATH . 'wp-includes/pluggable.php';
				}

				if ( class_exists( 'TUTOR_EMAIL\EmailNotification' ) && User::is_student() ) {
					add_filter( 'tutor_dashboard/nav_items/settings/nav_items', array( $this, 'register_nav' ) );
					add_filter( 'load_dashboard_template_part_from_other_location', array( $this, 'load_template' ) );
					add_action( 'wp_ajax_tutor_save_notification_preference', array( $this, 'ajax_save_notification_preference' ) );
				}
			}
		);
	}

	/**
	 * Filter is notification enabled for user
	 *
	 * @since 3.1.0
	 *
	 * @param bool   $bool is enabled or not.
	 * @param string $notification_type notification type.
	 * @param string $group_name group name.
	 * @param string $trigger_name trigger name.
	 * @param int    $user_id user id.
	 *
	 * @return bool
	 */
	public function check_notification_enabled_for_user( $bool, $notification_type, $group_name, $trigger_name, $user_id ) {
		/**
		 * If preference table not exists
		 * It means user has no preference for queried trigger, default is on.
		 *
		 * Note: This check has been added to avoid error if any check happened before table create. like before admin_init hook fire.
		 * Example: inactive student email event fire on `wp` hook which is fire before admin_init.
		 *
		 * @since 3.1.0
		 */
		if ( ! QueryHelper::table_exists( $this->table_name ) ) {
			return true;
		}

		return $this->is_notification_enabled_for_user( $notification_type, $group_name, $trigger_name, $user_id );
	}

	/**
	 * Create notification preference table.
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	public function create_table() {
		if ( QueryHelper::table_exists( $this->table_name ) ) {
			return;
		}

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
                id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT(20) UNSIGNED NOT NULL,
                notification_type VARCHAR(50) NOT NULL, -- email, push, onsite, sms
                group_name VARCHAR(50) NOT NULL, -- email_to_students, email_to_teachers, email_to_admin
                trigger_name VARCHAR(255) NOT NULL,
                opt_in TINYINT NOT NULL DEFAULT 1,
                FOREIGN KEY (user_id) REFERENCES {$wpdb->users}(id) ON DELETE CASCADE
            ) $charset_collate;";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );
	}

	/**
	 * Register nav menu for settings
	 *
	 * @since 3.1.0
	 *
	 * @param array $tabs setting navigation tabs.
	 *
	 * @return array
	 */
	public static function register_nav( $tabs ) {
		$tabs['notification'] = array(
			'url'   => tutor_utils()->get_tutor_dashboard_page_permalink( 'settings/notification' ),
			'title' => __( 'Notification', 'tutor-pro' ),
			'role'  => false,
		);

		return $tabs;
	}

	/**
	 * Load template for settings
	 *
	 * @since 3.1.0
	 *
	 * @param string $location default file location.
	 *
	 * @return string
	 */
	public static function load_template( $location ) {
		$page_name          = get_query_var( 'pagename' );
		$dashboard_sub_page = get_query_var( 'tutor_dashboard_sub_page' );

		$dashboard_page_id = (int) tutor_utils()->get_option( 'tutor_dashboard_page_id' );
		$dashboard_page    = get_post( $dashboard_page_id );

		// Current page is dashboard & sub page is notification.
		if ( $page_name === $dashboard_page->post_name && 'notification' === $dashboard_sub_page ) {
			$template = tutor_pro()->path . 'templates/notification-preference.php';

			if ( file_exists( $template ) ) {
				$location = $template;
			}
		}

		return $location;
	}

	/**
	 * Get user preferences
	 *
	 * @since 3.1.0
	 *
	 * @param integer $user_id user id.
	 *
	 * @return array
	 */
	public function get_user_preferences( $user_id = 0 ) {
		return QueryHelper::get_all(
			$this->table_name,
			array( 'user_id' => tutor_utils()->get_user_id( $user_id ) ),
			'id',
			-1
		);
	}

	/**
	 * Get enabled email triggers by group key.
	 *
	 * @since 3.1.0
	 *
	 * @param string $group_name email trigger group name.
	 *
	 * @return array
	 */
	public static function get_enabled_email_triggers( $group_name ) {
		$list = tutor_utils()->get_option( $group_name );
		if ( ! is_array( $list ) ) {
			$list = array();
		}

		$list = array_filter( $list, fn( $v, $k ) => 'on' === $v, ARRAY_FILTER_USE_BOTH );
		return $list;
	}


	/**
	 * Check user is opt-in to get notification.
	 * Default is opt-in util user explicitly disabled it.
	 *
	 * @since 3.1.0
	 *
	 * @param array  $preferences save preferences.
	 * @param string $trigger_name trigger name.
	 * @param string $group_name group name.
	 *
	 * @return boolean
	 */
	public static function is_trigger_enabled( $preferences, $trigger_name, $group_name ) {
		$is_enabled = true;
		foreach ( $preferences as $item ) {
			if ( $item->trigger_name === $trigger_name && $item->group_name === $group_name && 0 === (int) $item->opt_in ) {
				$is_enabled = false;
				break;
			}
		}

		return $is_enabled;
	}

	/**
	 * Check user is subscribed a specific notification trigger.
	 *
	 * @since 3.1.0
	 *
	 * @param string $notification_type notification type.
	 * @param string $group_name group name.
	 * @param string $trigger_name trigger name.
	 * @param int    $user_id user id. default current user id.
	 *
	 * @return bool
	 */
	public static function is_notification_enabled_for_user( $notification_type, $group_name, $trigger_name, $user_id = 0 ) {
		global $wpdb;

		$user_id    = tutor_utils()->get_user_id( $user_id );
		$table_name = $wpdb->prefix . 'tutor_notification_preferences';

		$disabled_all = QueryHelper::get_count(
			$table_name,
			array(
				'user_id'      => $user_id,
				'trigger_name' => 'disable_all',
				'opt_in'       => 1,
			)
		);

		if ( $disabled_all ) {
			return false;
		}

		$trigger_record = QueryHelper::get_row(
			$table_name,
			array(
				'user_id'           => $user_id,
				'notification_type' => $notification_type,
				'group_name'        => $group_name,
				'trigger_name'      => $trigger_name,
			),
			'id'
		);

		if ( ! $trigger_record ) {
			/**
			 * User has no preference saved yet. So default is enabled.
			 */
			return true;
		}

		return (bool) $trigger_record->opt_in;
	}

	/**
	 * Exclude email list.
	 *
	 * @since 3.1.0
	 *
	 * @param array $to_emails to emails.
	 * @param array $exclude_emails exclude email.
	 *
	 * @return array excluded to email list.
	 */
	public function exclude_email( $to_emails, $exclude_emails ) {
		return array_values( array_diff( $to_emails, $exclude_emails ) );
	}


	/**
	 * Add or update trigger status
	 *
	 * @since 3.1.0
	 *
	 * @param string  $notification_type notification type.
	 * @param string  $group_name group name.
	 * @param string  $trigger_name trigger name.
	 * @param bool    $opt_in opt-in status.
	 * @param integer $user_id user id. default is current user.
	 * @return void
	 */
	public function add_or_update_trigger_status( $notification_type, $group_name, $trigger_name, $opt_in, $user_id = 0 ) {
		global $wpdb;

		$user_id    = tutor_utils()->get_user_id( $user_id );
		$table_name = $wpdb->prefix . 'tutor_notification_preferences';

		$existing_row = QueryHelper::get_row(
			$table_name,
			array(
				'user_id'      => $user_id,
				'group_name'   => $group_name,
				'trigger_name' => $trigger_name,
			),
			'id'
		);

		if ( $existing_row ) {
			QueryHelper::update(
				$table_name,
				array( 'opt_in' => $opt_in ),
				array(
					'user_id'           => $user_id,
					'notification_type' => $notification_type,
					'group_name'        => $group_name,
					'trigger_name'      => $trigger_name,
				)
			);
		} else {
			QueryHelper::insert(
				$table_name,
				array(
					'user_id'           => $user_id,
					'trigger_name'      => $trigger_name,
					'notification_type' => $notification_type,
					'group_name'        => $group_name,
					'opt_in'            => $opt_in,
				)
			);
		}
	}

	/**
	 * Prepare notification preferences data.
	 *
	 * @since 3.1.0
	 *
	 * @param int $user_id user id.
	 *
	 * @return array
	 */
	public static function prepare_notification_preferences_data( $user_id = 0 ) {
		$user_id = tutor_utils()->get_user_id( $user_id );

		$user_saved_preferences = ( new self( false ) )->get_user_preferences( $user_id );

		$is_all_disabled = false;
		foreach ( $user_saved_preferences as $item ) {
			if ( 'disable_all' === $item->trigger_name && 1 === (int) $item->opt_in ) {
				$is_all_disabled = true;
				break;
			}
		}

		$prepared_list = array();

		$prepared_list['disable_all'] = array(
			'key'   => 'disable_all',
			'label' => __( 'Disable all notification', 'tutor-pro' ),
			'value' => $is_all_disabled ? 'on' : 'off',
		);

		$prepared_list['email'] = array();

		$available_email_trigger_list = ( new EmailData() )->get_recipients();

		$enabled_student_email_triggers = self::get_enabled_email_triggers( EmailNotification::TO_STUDENTS );
		if ( isset( $enabled_student_email_triggers['welcome_student'] ) ) {
			unset( $enabled_student_email_triggers['welcome_student'] );
		}

		// For student.
		foreach ( $enabled_student_email_triggers as $key => $val ) {
			if ( $available_email_trigger_list[ EmailNotification::TO_STUDENTS ][ $key ] ) {
				$item = $available_email_trigger_list[ EmailNotification::TO_STUDENTS ][ $key ];
				$prepared_list['email'][ EmailNotification::TO_STUDENTS ][ $key ] = array(
					'key'   => $key,
					'label' => $item['label'],
					'value' => self::is_trigger_enabled( $user_saved_preferences, $key, EmailNotification::TO_STUDENTS ) ? 'on' : 'off',
				);
			}
		}

		return $prepared_list;
	}

	/**
	 * Save notification preference
	 *
	 * @since 3.1.0
	 *
	 * @return void
	 */
	public function ajax_save_notification_preference() {
		tutor_utils()->check_nonce();

		$inputs = Input::sanitize_array( $_POST['tutor_notification_preference'] ?? [] );//phpcs:ignore
		$user_id = get_current_user_id();

		$disable_all = isset( $inputs['disable_all'] ) && 'on' === $inputs['disable_all'] ? 1 : 0;
		$this->add_or_update_trigger_status( 'all', 'all', 'disable_all', $disable_all, $user_id );

		foreach ( $inputs['email'] as $group_name => $triggers ) {
			foreach ( $triggers as $trigger_name => $status ) {
				$this->add_or_update_trigger_status( 'email', $group_name, $trigger_name, 'on' === $status ? 1 : 0 );
			}
		}

		$this->json_response( __( 'Preference saved successfully', 'tutor-pro' ) );
	}
}

<?php
/**
 * Manage WPML translations
 *
 * @package Tutor\WPMLTranslation
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 1.0.0
 */

namespace TUTOR_WPML;

use Tutor\Helpers\QueryHelper;
use TUTOR\Input;
use TUTOR_EMAIL\EmailNotification;
use TUTOR_PRO\Course_Duplicator;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage WPML integration
 */
class Wpml_Translation {
	/**
	 * Current lang
	 *
	 * @var string
	 */
	private static $wpml_current_lang;

	/**
	 * __construct
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'load_scrips' ) );
		add_action( 'save_post_' . tutor()->course_post_type, array( $this, 'copy_content_from_source' ), 999, 1 );
		add_filter( 'tutor_dashboard_page_id_filter', array( $this, 'tutor_dashboard_page_id_filter' ) );
		add_action( 'tutor_utils/get_pages/before', array( $this, 'enable_wpml_filter_dashboard_page' ) );
		add_action( 'tutor_utils/get_pages/after', array( $this, 'disable_wpml_filter_dashboard_page' ) );

		/**
		 * Apply filters to fix 404 not found issue of frontend
		 * dashboard sub pages
		 *
		 * @since 2.1.7
		 */
		add_filter( 'tutor_determine_is_page', __CLASS__ . '::is_tutor_page' );
		add_filter( 'tutor_determine_is_dashboard_page', __CLASS__ . '::is_tutor_page' );
		add_filter( 'tutor_dashboard_sub_page_template', __CLASS__ . '::filter_sub_page_template' );
		add_filter( 'tutor_should_load_dashboard_styles', __CLASS__ . '::is_tutor_page' );
		add_action( 'wp', __CLASS__ . '::manage_tutor_query_vars' );
		add_filter( 'tutor_dashboard_menu_link', __CLASS__ . '::filter_menu_link' );
		add_filter( 'template_include', __CLASS__ . '::load_assignment_template' );
		add_filter( 'body_class', __CLASS__ . '::add_course_builder_classes' );
		add_filter( 'tutor_load_course_builder_scripts', __CLASS__ . '::load_course_builder_scripts' );

		add_filter( 'tutor_filter_course_archive_page', array( $this, 'filter_course_archive_id' ), 10, 1 );
		add_filter( 'post_type_archive_link', array( $this, 'update_archive_link' ), 10, 2 );
		add_filter( 'tutor_data_tab_base_url', array( $this, 'filter_data_tab_base_url' ) );

		/**
		 * Email template translation
		 */
		add_action( 'tutor_pro_before_prepare_email_template_data', array( $this, 'set_email_translation' ), 10, 1 );
		add_action( 'tutor_pro_after_prepare_template_email_data', array( $this, 'revert_translation' ) );
		add_action( 'tutor_pro_before_save_email_template_data', array( $this, 'save_email_template_option' ) );
		add_filter( 'tutor_pro_email_template_data_option', array( $this, 'set_email_template_option' ) );
		add_filter( 'tutor_pro_user_email_template_option', array( $this, 'set_user_email_template_option' ), 10, 2 );

		add_action( 'tutor_after_student_signup', array( $this, 'save_user_language' ) );
		add_action( 'tutor_after_instructor_signup', array( $this, 'save_user_language' ) );
		add_action( 'wpml_language_has_switched', array( $this, 'update_user_language' ) );
		add_action( 'tutor_after_login_success', array( $this, 'save_user_language' ) );
		add_filter( 'wpml_user_language', array( $this, 'set_recipient_language' ), 10, 2 );

		add_filter( 'icl_ls_languages', array( $this, 'set_dashboard_subpage_url' ) );
		add_filter( 'wpml_show_admin_language_switcher', array( $this, 'set_tutor_settings_ls' ) );
		add_filter( 'modify_get_courses_by_instructor_query', array( $this, 'update_courses_by_instructor_query' ), 10, 6 );
		add_action( 'pre_get_posts', array( $this, 'translate_not_translated_posts' ) );
	}

	/**
	 * Create topic post translation if not translated.
	 *
	 * @since 3.8.0
	 *
	 * @param \WP_Query $query the  post query.
	 *
	 * @return void|\WP_Query
	 */
	public function translate_not_translated_posts( $query ) {
		global $wpdb;
		$topics_query = $query;
		$default_lang = apply_filters( 'wpml_default_language', null );

		$is_tutor_course = isset( $topics_query->query_vars['post_parent'] ) && tutor()->course_post_type === get_post_type( $topics_query->query_vars['post_parent'] );

		if ( isset( $topics_query->query_vars['post_type'] ) && tutor()->topics_post_type === $topics_query->query_vars['post_type'] && $is_tutor_course ) {
			$post_parent  = $topics_query->query_vars['post_parent'];
			$trid         = apply_filters( 'wpml_element_trid', null, $post_parent, 'post_' . tutor()->course_post_type );
			$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . tutor()->course_post_type );

			if ( $translations ) {
				foreach ( $translations as $code => $course_data ) {
					$post_parent = (int) $course_data->element_id ?? 0;
					$topics      = QueryHelper::get_all(
						$wpdb->posts,
						array(
							'post_parent' => $post_parent,
							'post_type'   => tutor()->topics_post_type,
						),
						'menu_order',
						-1,
						'ASC',
					);

					if ( $topics ) {
						foreach ( $topics as $topic ) {
							$topic_id     = (int) $topic->ID;
							$trid         = apply_filters( 'wpml_element_trid', null, $topic_id, 'post_' . tutor()->topics_post_type );
							$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_' . tutor()->topics_post_type );
							if ( ! in_array( $code, array_keys( $translations ), true ) ) {
								$set_language_args = array(
									'element_id'           => $topic_id,
									'element_type'         => 'post_' . tutor()->topics_post_type,
									'trid'                 => 0,
									'language_code'        => $code,
									'source_language_code' => $default_lang,
								);

								do_action( 'wpml_set_element_language_details', $set_language_args );
							}
						}
					}
				}
			}
		}
	}

	/**
	 * Modifies the courses by instructor query.
	 *
	 * @since 3.7.1
	 *
	 * @param string $query the main query to modify.
	 * @param int    $instructor_id the instructor id.
	 * @param string $where_post_status the where clause.
	 * @param string $post_types the post types clause.
	 * @param string $limit_offset the offset and limit clause.
	 * @param string $select_col the selected column clause.
	 *
	 * @return string
	 */
	public function update_courses_by_instructor_query( $query, $instructor_id, $where_post_status, $post_types, $limit_offset, $select_col ) {
		global $wpdb;
		$current_lang = apply_filters( 'wpml_current_language', null );

		if ( ! $current_lang ) {
			return $query;
		}

		//phpcs:disable
		$modified_query = $wpdb->prepare(
			"SELECT $select_col
			FROM 	$wpdb->posts
			LEFT JOIN {$wpdb->usermeta}
					ON $wpdb->usermeta.user_id = %d
					AND $wpdb->usermeta.meta_key = %s
					AND $wpdb->usermeta.meta_value = $wpdb->posts.ID
			LEFT JOIN {$wpdb->prefix}icl_translations wpml_translation
				ON wpml_translation.element_id = $wpdb->posts.ID
				AND wpml_translation.element_type = CONCAT('post_', $wpdb->posts.post_type)
			WHERE	1 = 1 {$where_post_status}
				AND $wpdb->posts.post_type IN ({$post_types})
				AND ($wpdb->posts.post_author = %d OR $wpdb->usermeta.user_id = %d)
				AND wpml_translation.language_code = %s
			ORDER BY $wpdb->posts.post_date DESC $limit_offset",
			$instructor_id,
			'_tutor_instructor_course_id',
			$instructor_id,
			$instructor_id,
			$current_lang
		);
		//phpcs:enable

		return $modified_query;
	}

	/**
	 * Prevent showing language switcher on tutor settings page.
	 *
	 * @since 3.4.1
	 *
	 * @param  bool $allow_ls boolean to show or hide language switcher.
	 *
	 * @return bool
	 */
	public function set_tutor_settings_ls( $allow_ls ) {
		$current_page = Input::get( 'page' );

		if ( 'tutor_settings' === $current_page ) {
			$allow_ls = false;
		}

		return $allow_ls;
	}

	/**
	 * Add subpage url on tutor dashboard url for language switcher.
	 *
	 * @since 3.4.1
	 *
	 * @param array $active_languages the list of active languages of language switcher.
	 *
	 * @return array
	 */
	public function set_dashboard_subpage_url( $active_languages ) {
		global $wp_query;

		$is_dashboard      = self::is_tutor_page( $wp_query->is_page );
		$default_lang      = apply_filters( 'wpml_default_language', null );
		$current_lang      = apply_filters( 'wpml_current_language', null );
		$languages         = $active_languages;
		$dashboard_page_id = tutor_utils()->get_option( 'tutor_dashboard_page_id' );

		if ( $is_dashboard ) {
			$dashboard_page = $wp_query->query_vars['tutor_dashboard_page'] ?? get_query_var( 'attachment' );
			foreach ( $active_languages as $lang ) {
				if ( $current_lang !== $default_lang ) {
					$trid         = apply_filters( 'wpml_element_trid', null, $dashboard_page_id, 'post_page' );
					$translations = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_page' );

					if ( isset( $lang['language_code'] ) && ! isset( $translations[ $lang['language_code'] ] ) ) {
						unset( $languages[ $lang['language_code'] ] );
						continue;
					}

					$dashboard_page_name = get_post_field( 'post_name', $translations[ $lang['language_code'] ]->element_id ) ?? '';
					if ( ! strstr( $lang['url'], $dashboard_page_name ) && $dashboard_page ) {
						$lang['url'] .= $dashboard_page_name . '/';
					}
				}

				if ( isset( $wp_query->query_vars['tutor_dashboard_sub_page'] ) ) {
					$lang['url'] .= $dashboard_page;
					$lang['url'] .= '/' . $wp_query->query_vars['tutor_dashboard_sub_page'];
				} else {
					$lang['url'] .= $dashboard_page;
				}

				$languages[ $lang['language_code'] ] = $lang;
			}
		}

		return $languages;
	}

	/**
	 * Sets the receiver language code for email for tutor users.
	 *
	 * @since 3.2.3
	 *
	 * @param string $lang the language code.
	 * @param string $email the receiver email.
	 *
	 * @return string
	 */
	public function set_recipient_language( $lang, $email ) {
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$user_lang = get_user_meta( $user->ID, '_tutor_wpml_user_language', true );
			return $user_lang;
		}
	}

	/**
	 * Updates user locale meta upon wpml language switch.
	 *
	 * @since 3.2.3
	 *
	 * @return void
	 */
	public function update_user_language() {
		$user_id     = get_current_user_id();
		$wpml_cookie = isset( $_COOKIE['wp-wpml_current_language'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wp-wpml_current_language'] ) ) : apply_filters( 'wpml_current_language', null );
		update_user_meta( $user_id, '_tutor_wpml_user_language', $wpml_cookie );
	}

	/**
	 * Set email template option based on user locale language code.
	 *
	 * @since 3.2.3
	 *
	 * @param array $option default email template option array.
	 * @param int   $user_id the user id.
	 *
	 * @return array
	 */
	public function set_user_email_template_option( $option, $user_id ) {
		$locale     = get_user_meta( $user_id, '_tutor_wpml_user_language', true );
		$admin_lang = apply_filters( 'wpml_default_language', null );

		if ( $locale !== $admin_lang ) {
			$option_key = EmailNotification::EMAIL_TEMPLATE_DATA_OPTION . '_' . $locale;
			$option     = get_option( $option_key );
		}

		return $option;
	}

	/**
	 * Save language of current user being registered or logged in.
	 *
	 * @since 3.2.3
	 *
	 * @param int $user_id the user id.
	 *
	 * @return void
	 */
	public function save_user_language( $user_id ) {
		$user_language = get_user_meta( $user_id, '_tutor_wpml_user_language', true );
		$locale        = get_user_meta( $user_id, 'locale', true );

		if ( empty( $user_language ) && empty( $locale ) ) {
			$wpml_cookie = isset( $_COOKIE['wp-wpml_current_language'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wp-wpml_current_language'] ) ) : apply_filters( 'wpml_current_language', null );
			update_user_meta( $user_id, '_tutor_wpml_user_language', $wpml_cookie );

			$active_languages = apply_filters( 'wpml_active_languages', null );
			foreach ( $active_languages as $lang ) {
				if ( $lang['active'] ) {
					$locale = $lang['default_locale'];
					break;
				}
			}

			update_user_meta( $user_id, 'locale', $locale );

		} else {
			$current_lang = isset( $_COOKIE['wp-wpml_current_language'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wp-wpml_current_language'] ) ) : apply_filters( 'wpml_current_language', null );
			if ( $user_language !== $current_lang ) {
				update_user_meta( $user_id, '_tutor_wpml_user_language', $current_lang );
			}

			$active_languages = apply_filters( 'wpml_active_languages', null );
			foreach ( $active_languages as $lang ) {
				if ( $lang['active'] ) {
					$active_locale = $lang['default_locale'];
					break;
				}
			}

			if ( $locale !== $active_locale ) {
				update_user_meta( $user_id, 'locale', $active_locale );
			}
		}
	}

	/**
	 * Set email template option based on the current language.
	 *
	 * @since 3.2.1
	 *
	 * @param mixed $email_option the initial email option.
	 *
	 * @return mixed
	 */
	public function set_email_template_option( $email_option ) {
		$current_lang = apply_filters( 'wpml_current_language', null );
		$admin_lang   = apply_filters( 'wpml_default_language', null );

		if ( $current_lang !== $admin_lang ) {
			$option_key   = EmailNotification::EMAIL_TEMPLATE_DATA_OPTION . '_' . $current_lang;
			$email_option = get_option( $option_key );
		}

		return $email_option;
	}

	/**
	 * Save email template option based on the current language.
	 *
	 * @since 3.2.1
	 *
	 * @return void
	 */
	public function save_email_template_option() {
		do_action( 'wpml_multilingual_options', EmailNotification::EMAIL_TEMPLATE_DATA_OPTION );
	}

	/**
	 * Revert any language translation after email template data is prepared.
	 *
	 * @since 3.2.1
	 *
	 * @return void
	 */
	public function revert_translation() {
		do_action( 'wpml_restore_language_from_email' );
	}

	/**
	 * Set the current language based on user email preference.
	 *
	 * @since 3.2.1
	 *
	 * @param string $user_email the user email.
	 *
	 * @return void
	 */
	public function set_email_translation( $user_email ) {
		do_action( 'wpml_switch_language_for_email', $user_email );
	}

	/**
	 * Filter pagenum link for backend navbar tabs.
	 *
	 * @since 3.0.0
	 *
	 * @param string $link the data tab base url.
	 * @return string
	 */
	public function filter_data_tab_base_url( $link ) {
		$wpml_current_lang = apply_filters( 'wpml_current_language', null ) . '/';
		if ( strpos( $link, $wpml_current_lang ) ) {
			$link = str_replace( $wpml_current_lang, '', $link );
		}
		return $link;
	}


	/**
	 * Load scripts
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function load_scrips() {
		wp_enqueue_script( 'tutor-wpml-js', TUTOR_WPML()->url . 'assets/js/duplicate.js', array(), TUTOR_PRO_VERSION );
	}

	/**
	 * Filter the course archive page id to get wpml translated id.
	 *
	 * @since 3.0.0
	 *
	 * @param integer $course_archive_page the course archive page id.
	 * @return integer
	 */
	public function filter_course_archive_id( $course_archive_page ) {
		$course_archive_page = apply_filters( 'wpml_object_id', $course_archive_page, get_post_type( $course_archive_page ), true );
		return $course_archive_page;
	}

	/**
	 * Filter course archive page permalink after translation.
	 *
	 * @since 3.0.0
	 *
	 * @param string $link the link to filter.
	 * @param string $post_type the post type.
	 * @return string
	 */
	public function update_archive_link( string $link, string $post_type ) {

		if ( tutor()->course_post_type === $post_type ) {
			$current_lang = apply_filters( 'wpml_current_language', null );
			$default_lang = apply_filters( 'wpml_default_language', null );

			if ( $current_lang !== $default_lang ) {
				$course_archive_page = tutor_utils()->get_option( 'course_archive_page' );
				if ( $course_archive_page && '-1' !== $course_archive_page ) {
					$link = get_permalink( $course_archive_page );
				}
			}
		}

		return $link;
	}

	/**
	 * Copy content from source
	 *
	 * @since 1.0.0
	 *
	 * @param int $target_course_id course id.
	 *
	 * @return void
	 */
	public function copy_content_from_source( $target_course_id ) {

		$icl_trid           = Input::post( 'icl_trid', '' );
		$source_copied_meta = get_post_meta( $target_course_id, 'tutor_wpml_source_copied', false );

		if ( '' === $icl_trid || $source_copied_meta ) {
			return;
		}

		update_post_meta( $target_course_id, 'tutor_wpml_source_copied', true );

		global $wpdb;
		$icl_translation_of = Input::post( 'icl_translation_of', '' );

		$source_course_id = '' !== $icl_translation_of ?
			$icl_translation_of :
			$wpdb->get_var(
				$wpdb->prepare(
					"SELECT 
						element_id
                	FROM {$wpdb->prefix}icl_translations
                	WHERE trid=%d AND source_language_code IS NULL
					",
					$icl_trid
				)
			);

		( new Course_Duplicator( false ) )->duplicate_post( $source_course_id, null, 0, $target_course_id );
	}

	/**
	 * Filter tutor dashboard page ID
	 *
	 * @since 1.0.0
	 *
	 * @param int $student_dashboard_page_id dashboard page id.
	 *
	 * @return mixed
	 */
	public function tutor_dashboard_page_id_filter( $student_dashboard_page_id ) {
		global $sitepress;
		if ( isset( $sitepress ) ) {
			$trid                      = apply_filters( 'wpml_element_trid', null, $student_dashboard_page_id, 'post_page' );
			$translations              = apply_filters( 'wpml_get_element_translations', null, $trid, 'post_page' );
			$current_lang              = apply_filters( 'wpml_current_language', null );
			$student_dashboard_page_id = (int) isset( $translations[ $current_lang ] ) ? $translations[ $current_lang ]->element_id : null;
		}

		return $student_dashboard_page_id;
	}

	/**
	 * Enable WPML filter dashboard
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function enable_wpml_filter_dashboard_page() {
		global $sitepress;
		if ( isset( $sitepress ) ) {
			self::$wpml_current_lang = apply_filters( 'wpml_current_language', null );
			$wpml_default_lang       = apply_filters( 'wpml_default_language', null );
			$sitepress->switch_lang( $wpml_default_lang );
		}
	}

	/**
	 * Disable WPML filter dashboard
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function disable_wpml_filter_dashboard_page() {
		global $sitepress;
		if ( isset( $sitepress ) ) {
			$sitepress->switch_lang( self::$wpml_current_lang );
		}
	}

	/**
	 * Fallback filter method if Tutor could not determine
	 * it is a sub page of dashboard
	 *
	 * @since 2.1.7
	 *
	 * @param bool $is_page default value of filter.
	 *
	 * @return boolean
	 */
	public static function is_tutor_page( $is_page ): bool {
		$current_url = self::current_url_arr();

		// Ignore course contents check only for frontend dashboard pages.
		if ( ! in_array( tutor()->course_post_type, $current_url ) ) {
			if ( ! $is_page ) {
				$attachment = get_query_var( 'attachment' );
				if ( '' !== $attachment ) {
					$is_page = self::is_tutor_sub_page( $attachment );
				} else {
					$is_page = self::is_tutor_sub_page( self::get_sub_page() );
				}
			}
		}
		return (bool) $is_page;
	}

	/**
	 * Filter dashboard sub page template
	 *
	 * @since 2.1.7
	 *
	 * @param string $template default template.
	 *
	 * @return string template file name
	 */
	public static function filter_sub_page_template( $template ) {
		$sub_page = get_query_var( 'attachment' );
		if ( ! $template && self::is_tutor_sub_page( $sub_page ) ) {
			$template = $sub_page;
		}
		return $template;
	}

	/**
	 * Check if current attachment is Tutor's dashboard
	 * sub page
	 *
	 * @since 2.1.7
	 *
	 * @param string $sub_page sub page name.
	 *
	 * @return boolean
	 */
	private static function is_tutor_sub_page( $sub_page ): bool {
		$dashboard_pages = tutor_utils()->tutor_dashboard_permalinks();
		return (bool) tutor_utils()->array_get( $sub_page, $dashboard_pages );
	}

	/**
	 * Setup tutor query vars
	 *
	 * Tutor's frontend pages are dependent on query
	 * vars. These are : pagename, tutor_dashboard_page,
	 * tutor_dashboard_sub_page
	 *
	 * @since 2.1.7
	 *
	 * @return void
	 */
	public static function manage_tutor_query_vars() {
		global $wp_query;
		if ( $wp_query->is_main_query() && ! is_admin() ) {
			$sub_page = self::get_sub_page();
			if ( self::is_tutor_sub_page( $sub_page ) ) {
				self::setup_query_vars();
			}
		}
	}

	/**
	 * Setup missing query vars based on current URL
	 *
	 * @since 2.1.7
	 *
	 * @return void
	 */
	private static function setup_query_vars() {
		global $wp_query;
		$url_arr           = self::current_url_arr();
		$dashboard_page_id = tutor_utils()->get_option( 'tutor_dashboard_page_id' );
		$length            = count( $url_arr );
		$post_type         = get_query_var( 'post_type' );

		if ( 'tutor_assignments' === $post_type ) {
			$wp_query->set( 'attachment', 'tutor-assignments' );
			return;
		}

		if ( self::is_tutor_sub_page( $url_arr[ $length - 2 ] ) && ! self::is_tutor_sub_page( $url_arr[ $length - 1 ] ) ) {
			$wp_query->query(
				array(
					'pagename'                 => get_post_field( 'post_name', $dashboard_page_id ) ?? '',
					'tutor_dashboard_page'     => $url_arr[ $length - 2 ],
					'tutor_dashboard_sub_page' => $url_arr[ $length - 1 ],
				)
			);
			$wp_query->set( 'pagename', get_post_field( 'post_name', $dashboard_page_id ) ?? '' );
			$wp_query->set( 'tutor_dashboard_page', $url_arr[ $length - 2 ] );
			$wp_query->set( 'tutor_dashboard_sub_page', $url_arr[ $length - 1 ] );
			$wp_query->reset_postdata();
		}

		if ( self::is_tutor_sub_page( $url_arr[ $length - 1 ] ) ) {
			$wp_query->query(
				array(
					'pagename'             => get_post_field( 'post_name', $dashboard_page_id ) ?? '',
					'tutor_dashboard_page' => $url_arr[ $length - 1 ],
				)
			);
			$wp_query->set( 'pagename', get_post_field( 'post_name', $dashboard_page_id ) ?? '' );
			$wp_query->set( 'tutor_dashboard_page', $url_arr[ $length - 1 ] );
			$wp_query->reset_postdata();
		}
	}

	/**
	 * Extract sub page from current URL
	 *
	 * @since 2.1.7
	 *
	 * @param string $url URL to extract sub page. If empty
	 * then it will use current URL.
	 *
	 * @return string
	 */
	public static function get_sub_page( string $url = '' ): string {
		$sub_page = '';
		$url_arr  = self::current_url_arr( $url );
		$length   = count( $url_arr );
		if ( self::is_tutor_sub_page( $url_arr[ $length - 2 ] ) && ! self::is_tutor_sub_page( $url_arr[ $length - 1 ] ) ) {
			$sub_page = $url_arr[ $length - 2 ];
		}
		if ( self::is_tutor_sub_page( $url_arr[ $length - 1 ] ) ) {
			$sub_page = $url_arr[ $length - 1 ];
		}
		return $sub_page;
	}

	/**
	 * Convert current URL in array
	 *
	 * @since 2.1.7
	 *
	 * @param string $url URL string, if empty then it will
	 * use current url.
	 *
	 * @return array of URL portions
	 */
	public static function current_url_arr( string $url = '' ) {
		if ( '' === $url ) {
			$current_url = trim( tutor_utils()->get_current_url(), '/' );
		} else {
			$current_url = trim( $url, '/' );
		}

		return array_values( array_filter( explode( '/', $current_url ) ) );
	}

	/**
	 * Filter assignment menu link
	 *
	 * @since 2.1.7
	 *
	 * @param string $link link string.
	 *
	 * @return string
	 */
	public static function filter_menu_link( $link ) {
		$sub_page         = self::get_sub_page( $link );
		$current_language = apply_filters( 'wpml_current_language', null );
		if ( 'assignments' === $sub_page && 'en' !== $current_language ) {
			$link = tutor_utils()->tutor_dashboard_url( 'tutor-assignments' );
		}
		return trailingslashit( $link );
	}

	/**
	 * Explicitly Load assignment template
	 *
	 * @since 2.1.7
	 *
	 * @param string $template default template.
	 *
	 * @return string template path
	 */
	public static function load_assignment_template( $template ) {
		$attachment = get_query_var( 'attachment' );
		if ( 'tutor-assignments' === $attachment ) {
			add_filter(
				'tutor_dashboard_sub_page_template',
				function () {
					return 'assignments';
				}
			);
			add_filter(
				'tutor_should_load_dashboard_styles',
				function () {
					return true;
				}
			);
			self::setup_query_vars();
			$template = tutor()->path . 'templates/dashboard.php';
		}
		return $template;
	}

	/**
	 * Handle filter to load course builder scripts
	 *
	 * @since 2.1.7
	 *
	 * @param mixed $load default value of filter.
	 *
	 * @return bool
	 */
	public static function load_course_builder_scripts( $load ): bool {
		if ( $load ) {
			return $load;
		}
		return self::is_frontend_course_builder();
	}

	/**
	 * Add course builder style classes to the body tag
	 *
	 * @since 2.1.7
	 *
	 * @param array $classes array of classes.
	 *
	 * @return array
	 */
	public static function add_course_builder_classes( array $classes ): array {
		if ( self::is_frontend_course_builder() ) {
			$classes = array_merge( $classes, array( 'tutor-screen-course-builder', 'tutor-screen-course-builder-frontend' ) );
		}
		return $classes;
	}

	/**
	 * Check if course builder scripts required
	 *
	 * @since 2.1.7
	 *
	 * @return boolean
	 */
	private static function is_frontend_course_builder(): bool {
		$attachment = get_query_var( 'attachment' );
		return ! is_admin() && 'create-course' === $attachment;
	}
}

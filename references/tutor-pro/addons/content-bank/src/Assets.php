<?php
/**
 * Manage Assets for content bank.
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank;

use TUTOR\Input;
use Tutor\Options_V2;

/**
 * Assets Class.
 *
 * @since 3.7.0
 */
class Assets {
	/**
	 * Register hooks and dependency
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_script' ) );
		add_filter( 'tutor_localize_data', array( $this, 'extend_localize_data' ) );
	}

	/**
	 * Load admin assets
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function admin_script() {
		$page = Input::get( 'page' );
		if ( Menu::PAGE_SLUG === $page ) {
			wp_enqueue_editor();

			wp_enqueue_media();
			wp_enqueue_script( 'tutor-content-bank-backend', Helper::asset_url( 'js/manage-contents/index.js' ), array( 'wp-i18n', 'wp-date', 'wp-element' ), TUTOR_PRO_VERSION, true );
		}
	}

	/**
	 * Extend localize data
	 *
	 * @since 3.7.0
	 *
	 * @param array $data data.
	 *
	 * @return array
	 */
	public function extend_localize_data( $data ) {
		$page = Input::get( 'page' );
		if ( Menu::PAGE_SLUG !== $page ) {
			return $data;
		}

		$required_options = array(
			'monetize_by',
			'supported_video_sources',
			'chatgpt_enable',
			'hide_admin_bar_for_users',
			'pagination_per_page',
		);

		$full_settings                     = get_option( 'tutor_option', array() );
		$settings                          = Options_V2::get_only( $required_options );
		$settings['chatgpt_key_exist']     = tutor()->has_pro && ! empty( $full_settings['chatgpt_api_key'] ?? '' );
		$settings['youtube_api_key_exist'] = ! empty( $full_settings['lesson_video_duration_youtube_api_key'] ?? '' );

		$supported_video_sources = array();
		$saved_video_source_list = (array) ( $full_settings['supported_video_sources'] ?? array() );

		foreach ( tutor_utils()->get_video_sources( true ) as $value => $label ) {
			if ( in_array( $value, $saved_video_source_list, true ) ) {
				$supported_video_sources[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}

		$new_data                            = array( 'settings' => $settings );
		$new_data['supported_video_sources'] = $supported_video_sources;

		$data = array_merge( $data, $new_data );

		return $data;
	}
}

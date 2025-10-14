<?php
/**
 * Addon Register Handler
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.0.0
 */

namespace TutorPro\ContentBank;

/**
 * AddonRegister Class.
 *
 * @since 3.0.0
 */
class AddonRegister {
	/**
	 * Register hooks and dependencies
	 */
	public function __construct() {
		add_filter( 'tutor_addons_lists_config', array( $this, 'register_addon' ) );
	}

	/**
	 * Register course bundle addon
	 *
	 * @since 2.2.0
	 *
	 * @param array $addons array of addons.
	 *
	 * @return array
	 */
	public static function register_addon( $addons ) {

		$new_addon = array(
			'name'        => __( 'Content Bank', 'tutor-pro' ),
			'description' => __( 'Create content once and use it across multiple courses.', 'tutor-pro' ),
			'path'        => TUTOR_CONTENT_BANK_DIR,
			'basename'    => plugin_basename( TUTOR_CONTENT_BANK_FILE ),
			'url'         => plugin_dir_url( TUTOR_CONTENT_BANK_FILE ),
			'is_new'      => true,
		);

		$addons[ plugin_basename( $new_addon['basename'] ) ] = $new_addon;

		return $addons;
	}
}

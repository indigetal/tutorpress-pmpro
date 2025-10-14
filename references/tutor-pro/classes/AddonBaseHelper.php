<?php
/**
 * Base Addon Helper
 *
 * @package TutorPro\Classes
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TUTOR_PRO;

/**
 * Class AddonBaseHelper
 *
 * @since 3.7.0
 */
abstract class AddonBaseHelper {
	/**
	 * Addon directory
	 *
	 * @var string
	 */
	protected static $addon_dir;

	/**
	 * Addon file
	 *
	 * @var string
	 */
	protected static $addon_file;

	/**
	 * Get addon directory.
	 *
	 * @since 3.7.0
	 *
	 * @return string
	 */
	public static function get_addon_dir() {
		return static::$addon_dir;
	}

	/**
	 * Get addon file.
	 *
	 * @since 3.7.0
	 *
	 * @return string
	 */
	public static function get_addon_file() {
		return static::$addon_file;
	}

	/**
	 * Get asset URL.
	 *
	 * @since 3.7.0
	 *
	 * @param string $url url of assets.
	 *
	 * @return string
	 */
	public static function asset_url( $url = null ) {
		$final_url = plugin_dir_url( self::get_addon_file() ) . 'assets';
		if ( $url ) {
			$final_url .= '/' . $url;
		}
		return $final_url;
	}

	/**
	 * Get view path.
	 *
	 * @since 3.7.0
	 *
	 * @param string $path path.
	 *
	 * @return string
	 */
	public static function view_path( $path = null ) {
		$final_path = self::get_addon_dir() . 'views';
		if ( $path ) {
			$final_path .= '/' . $path;
		}
		return $final_path;
	}

	/**
	 * Get template path.
	 *
	 * @since 3.7.0
	 *
	 * @param string $path path.
	 *
	 * @return string
	 */
	public static function template_path( $path = null ) {
		$final_path = self::get_addon_dir() . 'templates';
		if ( $path ) {
			$final_path .= '/' . $path;
		}
		return $final_path;
	}
}

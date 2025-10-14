<?php
/**
 * TutorPro Tools Content Map Handler.
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

/**
 * Handler Import Content Map.
 */
class ContentMapHandler {
	/**
	 * Tools error option name.
	 *
	 * @since 3.8.1
	 */
	const CONTENT_MAP_OPTION_NAME = 'import_content_map';


	/**
	 * Update import content map.
	 *
	 * @since 3.8.1
	 *
	 * @param string       $key the map key.
	 * @param string|array $data the map data.
	 *
	 * @return void
	 */
	public static function update_content_map( $key, $data ) {

		$map = self::get_content_map();

		if ( isset( $map[ $key ] ) ) {
			$map[ $key ] = $data;
		}

		update_option( self::CONTENT_MAP_OPTION_NAME, $map );

		unset( $map );
	}

	/**
	 * Set import content map.
	 *
	 * @since 3.8.1
	 *
	 * @param string       $key the map key.
	 * @param string|array $data the map data.
	 *
	 * @return void
	 */
	public static function set_content_map( $key, $data ) {

		$map = self::get_content_map();

		$map[ $key ] = $data;

		update_option( self::CONTENT_MAP_OPTION_NAME, $map );

		unset( $map );
	}

	/**
	 * Get the saved map.
	 *
	 * @since 3.8.1
	 *
	 * @return array
	 */
	public static function get_content_map() {
		$map = maybe_unserialize( get_option( self::CONTENT_MAP_OPTION_NAME ) );
		$map = is_array( $map ) ? $map : array();

		return $map;
	}

	/**
	 * Delete the map.
	 *
	 * @since 3.8.1
	 *
	 * @return void
	 */
	public static function clear_map() {
		delete_option( self::CONTENT_MAP_OPTION_NAME );
	}
}

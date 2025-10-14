<?php
/**
 * TutorPro Tools Error Handler.
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

/**
 * Handler Import Export Error.
 */
class ErrorHandler {
	/**
	 * Tools error option name.
	 *
	 * @since 3.8.1
	 */
	const TOOL_ERROR_OPTION_NAME = 'tutor_pro_tools_error';

	/**
	 * Set import export error.
	 *
	 * @since 3.8.1
	 *
	 * @param string $key the error key.
	 * @param string $message the error message.
	 *
	 * @return void
	 */
	public static function set_error( string $key, string $message ) {

		$errors = self::get_errors();

		if ( isset( $errors[ $key ] ) ) {
			$errors[ $key ][] = $message;
		} else {
			$errors[ $key ] = array( $message );
		}

		update_option( self::TOOL_ERROR_OPTION_NAME, $errors );
	}

	/**
	 * Get all the saved errors.
	 *
	 * @since 3.8.1
	 *
	 * @return array
	 */
	public static function get_errors() {
		$errors = get_option( self::TOOL_ERROR_OPTION_NAME, array() );
		$errors = is_array( $errors ) ? $errors : array();

		return $errors;
	}

	/**
	 * Delete the errors.
	 *
	 * @since 3.8.1
	 *
	 * @return void
	 */
	public static function clear_errors() {
		delete_option( self::TOOL_ERROR_OPTION_NAME );
	}
}

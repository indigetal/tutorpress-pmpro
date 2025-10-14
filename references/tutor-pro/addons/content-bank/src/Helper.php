<?php
/**
 * Helper Class for Content Bank
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank;

use Tutor\Helpers\HttpHelper;
use Tutor\Helpers\ValidationHelper;
use TUTOR\Input;
use Tutor\Traits\JsonResponse;
use TUTOR_PRO\AddonBaseHelper;

/**
 * Helper Class.
 *
 * @since 3.7.0
 */
class Helper extends AddonBaseHelper {
	use JsonResponse;

	/**
	 * Addon directory
	 *
	 * @var string
	 */
	protected static $addon_dir = TUTOR_CONTENT_BANK_DIR;

	/**
	 * Addon file
	 *
	 * @var string
	 */
	protected static $addon_file = TUTOR_CONTENT_BANK_FILE;

	/**
	 * Check nonce and capability.
	 *
	 * @since 3.7.0
	 *
	 * @param string $capability Capability to check.
	 *
	 * @return void
	 */
	public static function check_nonce_and_capability( $capability = 'manage_tutor_instructor' ) {
		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability( $capability );
	}

	/**
	 * Check validation.
	 *
	 * @since 3.7.0
	 *
	 * @param array  $rules rules.
	 * @param string $method method.
	 *
	 * @return void
	 */
	public static function check_validation( $rules, $method = HttpHelper::METHOD_POST ) {
		$inputs     = Input::sanitize_array( $method === HttpHelper::METHOD_POST ? $_POST : $_GET ); //phpcs:ignore
		$validation = ValidationHelper::validate( $rules, $inputs );

		$errors = array();
		if ( ! $validation->success ) {
			$errors = $validation->errors;
		}

		if ( count( $errors ) > 0 ) {
			( new self() )->json_response(
				tutor_utils()->error_message( 'validation_error' ),
				$errors,
				HttpHelper::STATUS_UNPROCESSABLE_ENTITY
			);
			return;
		}
	}

	/**
	 * Check assignment addon is enabled or disabled.
	 *
	 * @since 3.7.0
	 *
	 * @return boolean
	 */
	public static function is_assignment_addon_enabled() {
		return tutor_utils()->is_addon_enabled( 'tutor-assignments' );
	}
}

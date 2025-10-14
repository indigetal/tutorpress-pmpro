<?php
/**
 * Content Bank Addon
 *
 * @package TutorPro\Addons
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.0.0
 */

use TutorPro\ContentBank\ContentBank;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once tutor_pro()->path . '/vendor/autoload.php';

define( 'TUTOR_CONTENT_BANK_FILE', __FILE__ );
define( 'TUTOR_CONTENT_BANK_DIR', plugin_dir_path( __FILE__ ) );

ContentBank::get_instance();



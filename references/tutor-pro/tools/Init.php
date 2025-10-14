<?php
/**
 * Initialize Tutor Pro Tools
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

use Tutor\Models\CourseModel;
use TutorPro\Tools\AjaxHandler;
use TutorPro\Tools\Tools;

if ( ! function_exists( 'tutor_pro_tools' ) ) {
	/**
	 * Get the tools object
	 *
	 * @since 3.6.0
	 *
	 * @return Tools
	 */
	function tutor_pro_tools() {
		return Tools::get_instance();
	}
}

add_action(
	'init',
	function () {
		new AjaxHandler( tutor_pro_tools()->exporter, tutor_pro_tools()->importer, new CourseModel() );
	}
);

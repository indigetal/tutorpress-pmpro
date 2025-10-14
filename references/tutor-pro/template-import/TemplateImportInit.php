<?php
/**
 * Init class
 *
 * @package TutorPro\TemplateImporter
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.6.0
 */

namespace TutorPro\TemplateImport;

/**
 * Class TemplateImportInit
 */
final class TemplateImportInit {

	/**
	 * Register hooks
	 *
	 * @since 3.6.0
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Init packages
	 *
	 * @since 3.6.0
	 *
	 * @return void
	 */
	public function init() {
		new TemplateImporter();
	}
}

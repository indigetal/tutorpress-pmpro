<?php
/**
 * Content Bank Main Class
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank;

use AllowDynamicProperties;
use TUTOR\Container;
use TUTOR\Singleton;
use TutorPro\ContentBank\Controllers\CollectionController;
use TutorPro\ContentBank\Controllers\ContentController;
use TutorPro\ContentBank\Controllers\ContentUsageController;
use TutorPro\ContentBank\Extensions\LessonExtender;
use TutorPro\ContentBank\Extensions\QuestionContentExtender;
use TutorPro\ContentBank\Extensions\TopicContentExtender;

defined( 'ABSPATH' ) || exit;

/**
 * Class ContentBank
 *
 * @since 3.7.0
 */
#[AllowDynamicProperties]
final class ContentBank extends Singleton {
	/**
	 * Register dependencies
	 */
	protected function __construct() {
		Container::make( AddonRegister::class );

		if ( ! self::is_enabled() ) {
			return;
		}

		$this->database  = Container::make( Database::class );
		$this->assets    = Container::make( Assets::class );
		$this->menu      = Container::make( Menu::class );
		$this->post_type = Container::make( PostType::class );

		$this->collection_ctrl = Container::make( CollectionController::class );
		$this->content_ctrl    = Container::make( ContentController::class );
		$this->usage_ctrl      = Container::make( ContentUsageController::class );
	}

	/**
	 * Check addon is enabled or not.
	 *
	 * @since 3.7.0.
	 *
	 * @return boolean
	 */
	public static function is_enabled() : bool {
		$basename   = plugin_basename( TUTOR_CONTENT_BANK_FILE );
		$is_enabled = tutor_utils()->is_addon_enabled( $basename );
		return $is_enabled;
	}

	/**
	 * Make instance with dependency
	 * TODO: will be used when linking instead of creating new one.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function make_instance() {
		$classes = array(
			TopicContentExtender::class,
			LessonExtender::class,
			QuestionContentExtender::class,
		);

		foreach ( $classes as $class ) {
			Container::make( $class );
		}
	}
}

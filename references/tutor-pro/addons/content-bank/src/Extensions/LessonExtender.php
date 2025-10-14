<?php
/**
 * Extend tutor lesson with the content bank lesson
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Extensions;

use AllowDynamicProperties;
use TutorPro\ContentBank\Models\ContentModel;
use TutorPro\ContentBank\Models\ContentUsageModel;

/**
 * LessonExtender class
 */
#[AllowDynamicProperties]
class LessonExtender {

	/**
	 * Register hooks
	 *
	 * @since 3.7.0
	 *
	 * @param ContentUsageModel $usage_model Content usage model object.
	 * @param ContentModel      $content_model Content model object.
	 *
	 * @return void
	 */
	public function __construct( ContentUsageModel $usage_model, ContentModel $content_model ) {
		$this->usage_model   = $usage_model;
		$this->content_model = $content_model;

		add_filter( 'tutor_is_lesson_post_type', array( $this, 'is_cb_lesson_post' ), 10, 2 );
	}

	/**
	 * Treat cb-lesson post type as lesson post type
	 *
	 * @since 3.7.0
	 *
	 * @param bool   $is_lesson_post_type Default value.
	 * @param string $post_type Post type string.
	 *
	 * @return boolean
	 */
	public function is_cb_lesson_post( bool $is_lesson_post_type, string $post_type ): bool {
		if ( 'cb-lesson' === $post_type ) {
			$is_lesson_post_type = true;
		}

		return $is_lesson_post_type;
	}
}

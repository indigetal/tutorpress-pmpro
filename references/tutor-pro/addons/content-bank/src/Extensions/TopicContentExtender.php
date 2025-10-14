<?php
/**
 * Manage lesson usages through content bank
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
 * Lesson usage controller class
 */
#[AllowDynamicProperties]
class TopicContentExtender {

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

		// Register hooks.
		add_filter( 'tutor_course_topic_contents_args', array( $this, 'filter_course_topic_contents_args' ) );
	}

	/**
	 * Filter topic content arguments for merging content bank contents.
	 *
	 * @since 3.7.0
	 *
	 * @param array $args args.
	 *
	 * @return array
	 */
	public function filter_course_topic_contents_args( $args ) {
		$topic_id  = $args['post_parent'];
		$course_id = tutor_utils()->get_course_id_by( 'topic', $topic_id );

		$has_cb_contents = $this->usage_model->count(
			array(
				'course_id' => $course_id,
				'topic_id'  => $topic_id,
			)
		);

		if ( ! $has_cb_contents ) {
			return $args;
		}

		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT content_id, content_order, source 
				FROM (
					SELECT ID AS content_id, menu_order AS content_order, 'native' AS source
					FROM {$wpdb->posts} 
					WHERE post_parent=%d
					
					UNION
					
					SELECT content_id, content_order, 'cb' AS source 
					FROM {$wpdb->prefix}tutor_cb_content_usage 
					WHERE course_id=%d AND topic_id=%d
				) AS topic_contents
				ORDER BY content_order ASC",
				$topic_id,
				$course_id,
				$topic_id
			)
		);

		$ids = array_map( fn( $result) => (int) $result->content_id, $results );

		unset( $args['post_parent'] );
		$args['post__in']  = $ids;
		$args['orderby']   = 'post__in';
		$args['post_type'] = array_merge( $args['post_type'], array( ContentModel::LESSON_POST_TYPE ) );

		return $args;
	}
}

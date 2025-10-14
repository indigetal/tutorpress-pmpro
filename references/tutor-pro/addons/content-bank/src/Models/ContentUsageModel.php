<?php
/**
 * Content Usage Model
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Models;

use Tutor\Models\BaseModel;

/**
 * ContentUsageModel Class.
 *
 * @since 3.7.0
 */
class ContentUsageModel extends BaseModel {
	/**
	 * Table name
	 *
	 * @var string
	 */
	protected $table_name = 'tutor_cb_content_usage';

	/**
	 * Add usage.
	 *
	 * @since 3.7.0
	 *
	 * @param array $data data.
	 *
	 * @return int|false false if already exist, otherwise row id
	 */
	public function add_usage( $data ) {
		$exist_args = array(
			'collection_id' => $data['collection_id'] ?? null,
			'course_id'     => $data['course_id'] ?? null,
			'topic_id'      => $data['topic_id'] ?? null,
			'content_id'    => $data['content_id'] ?? null,
		);

		if ( isset( $data['quiz_id'], $data['question_id'] ) ) {
			$exist_args['quiz_id']     = $data['quiz_id'];
			$exist_args['question_id'] = $data['question_id'];
		}

		$exist = $this->count( $exist_args );

		if ( $exist ) {
			return false;
		}

		return $this->create(
			array(
				'user_id'            => get_current_user_id(),
				'object_type'        => $data['object_type'] ?? null,
				'collection_id'      => $data['collection_id'] ?? null,
				'course_id'          => $data['course_id'] ?? null,
				'topic_id'           => $data['topic_id'] ?? null,
				'content_id'         => $data['content_id'] ?? null,
				'copied_post_id'     => $data['copied_post_id'] ?? null,
				'quiz_id'            => $data['quiz_id'] ?? null,
				'question_id'        => $data['question_id'] ?? null,
				'copied_question_id' => $data['copied_question_id'] ?? null,
				'content_order'      => $data['content_order'] ?? null,
			)
		);
	}

}

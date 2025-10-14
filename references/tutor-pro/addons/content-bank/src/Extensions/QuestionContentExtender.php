<?php
/**
 * Manage question usages through content bank
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Extensions;

use Tutor\Helpers\QueryHelper;
use TutorPro\ContentBank\Models\ContentModel;
use TutorPro\ContentBank\Models\ContentUsageModel;

/**
 * QuestionContentExtender Class
 */
class QuestionContentExtender {

	/**
	 * Content usage model object
	 *
	 * @since 3.7.0
	 *
	 * @var ContentUsageModel
	 */
	private $usage_model;

	/**
	 * Content model object
	 *
	 * @since 3.7.0
	 *
	 * @var ContentModel
	 */
	private $content_model;

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

		add_filter( 'tutor_get_questions_by_quiz', array( $this, 'filter_questions_by_quiz' ), 10, 2 );
	}

	/**
	 * Filter get questions by quiz id result.
	 *
	 * @param array $questions questions.
	 * @param int   $quiz_id quid id.
	 *
	 * @return array
	 */
	public function filter_questions_by_quiz( $questions, $quiz_id ) {
		$has_cb_contents = $this->usage_model->count( array( 'quiz_id' => $quiz_id ) );

		if ( ! $has_cb_contents ) {
			return $questions;
		}

		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT *
				FROM(
					SELECT question_id, question_order AS content_order, 'native' AS source
					FROM {$wpdb->prefix}tutor_quiz_questions
					WHERE quiz_id=%d

					UNION

					SELECT question_id, content_order, 'cb' AS source
					FROM {$wpdb->prefix}tutor_cb_content_usage
					WHERE quiz_id=%d
				) questions
				ORDER BY content_order ASC
			",
				$quiz_id,
				$quiz_id
			)
		);

		$question_ids     = array_map( fn( $result) => (int) $result->question_id, $results );
		$question_ids_str = QueryHelper::prepare_in_clause( $question_ids );

		//phpcs:disable
		$questions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}tutor_quiz_questions 
				WHERE question_id IN ({$question_ids_str}) 
				ORDER BY FIELD(question_id, {$question_ids_str})"
			)
		);
		//phpcs:enable

		array_map(
			function( $question ) {
				if ( is_null( $question->content_id ) ) {
					$question->is_cb_question = true;
				}
				return $question;
			},
			$questions
		);

		return $questions;
	}

}

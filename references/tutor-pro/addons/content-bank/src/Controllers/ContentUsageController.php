<?php
/**
 * Manage Content Bank Contents Usages
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Controllers;

use Tutor\Helpers\QueryHelper;
use TUTOR\Input;
use Tutor\Models\QuizModel;
use Tutor\Traits\JsonResponse;
use TutorPro\ContentBank\Helper;
use TutorPro\ContentBank\Models\ContentModel;

/**
 * ContentUsageController Class.
 *
 * @since 3.7.0
 */
class ContentUsageController extends BaseController {
	use JsonResponse;

	/**
	 * Register hooks and dependencies
	 *
	 * @since 3.7.0
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'wp_ajax_tutor_content_bank_add_content_to_course', array( $this, 'ajax_add_content_to_course' ) );
		add_action( 'wp_ajax_tutor_content_bank_remove_content_from_course', array( $this, 'ajax_remove_content_from_course' ) );

		add_action( 'tutor_content_bank_question_added_to_quiz', array( $this, 'handle_content_bank_question_added_to_quiz' ), 10, 2 );
		add_action( 'tutor_content_bank_question_linked_to_quiz', array( $this, 'handle_content_bank_question_linked_to_quiz' ), 10, 2 );
		add_action( 'tutor_deleted_quiz_question_ids', array( $this, 'handle_question_deleted_from_quiz' ) );
	}

	/**
	 * Add content to course like lesson, assignment.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function ajax_add_content_to_course() {
		Helper::check_nonce_and_capability();

		$rules = array(
			'course_id'          => 'required|numeric',
			'topic_id'           => 'required|numeric',
			'content_ids'        => 'if_input|is_array',
			'next_content_order' => 'required|numeric',
		);

		Helper::check_validation( $rules );

		$action        = Input::post( 'action', 'create' );
		$topic_id      = Input::post( 'topic_id', 0, Input::TYPE_INT );
		$content_ids   = Input::post( 'content_ids', array(), Input::TYPE_ARRAY );
		$content_order = Input::post( 'next_content_order', 0, Input::TYPE_INT );
		$content_ids   = array_filter( $content_ids, 'is_numeric' );

		if ( ! tutor_utils()->can_user_manage( 'topic', $topic_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		'link' === $action
			? $this->link_contents( $topic_id, $content_order, $content_ids )
			: $this->create_contents( $topic_id, $content_order, $content_ids );

		$this->json_response( __( 'Successfully added', 'tutor-pro' ) );
	}

	/**
	 * Create course contents from content bank.
	 *
	 * @since 3.7.0
	 *
	 * @param int   $topic_id topic id.
	 * @param int   $content_order content order.
	 * @param array $content_ids content ids.
	 *
	 * @return void
	 */
	public function create_contents( $topic_id, $content_order, $content_ids ) {
		$course_id = tutor_utils()->get_course_id_by( 'topic', $topic_id );
		foreach ( $content_ids as $content_id ) {
			$content = $this->content_model->get_content( $content_id );
			if ( ! $content ) {
				continue;
			}

			$post_type = ( ContentModel::LESSON_POST_TYPE === $content->post_type )
						? tutor()->lesson_post_type
						: tutor()->assignment_post_type;

			$data = array(
				'post_title'   => $content->post_title,
				'post_content' => $content->post_content,
				'post_status'  => $content->post_status,
				'post_author'  => $content->post_author,
				'post_type'    => $post_type,
				'post_parent'  => $topic_id,
				'menu_order'   => $content_order,
			);

			$new_id = wp_insert_post( $data );

			$meta_data = get_post_meta( $content_id );
			$meta_data = is_array( $meta_data ) ? $meta_data : array();

			foreach ( $meta_data as $key => $values ) {
				foreach ( $values as $value ) {
					add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
				}
			}

			if ( tutor()->assignment_post_type === $post_type ) {
				add_post_meta( $new_id, '_tutor_course_id_for_assignments', $course_id );
			}

			$this->content_usage_model->add_usage(
				array(
					'object_type'    => $this->content_model->get_content_type_by_post_type( $content->post_type ),
					'collection_id'  => $content->post_parent,
					'content_id'     => $content->ID,
					'content_order'  => $content_order,
					'course_id'      => $course_id,
					'topic_id'       => $topic_id,
					'copied_post_id' => $new_id,
				)
			);

			$content_order++;
		}
	}

	/**
	 * Link contents
	 *
	 * @since 3.7.0
	 *
	 * @param int   $topic_id topic id.
	 * @param int   $content_order content order.
	 * @param array $content_ids content ids.
	 *
	 * @return void
	 */
	private function link_contents( $topic_id, $content_order, $content_ids ) {
		$course_id = tutor_utils()->get_course_id_by( 'topic', $topic_id );
		foreach ( $content_ids as $content_id ) {
			$content = $this->content_model->get_content( $content_id );
			if ( ! $content ) {
				continue;
			}

			$added = $this->content_usage_model->add_usage(
				array(
					'object_type'   => $this->content_model->get_content_type_by_post_type( $content->post_type ),
					'collection_id' => $content->post_parent,
					'content_id'    => $content->ID,
					'content_order' => $content_order,
					'topic_id'      => $topic_id,
					'course_id'     => $course_id,
				)
			);

			if ( ! $added ) {
				continue;
			}

			$content_order++;
		}
	}

	/**
	 * Remove content bank lesson, assignment content from course.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function ajax_remove_content_from_course() {
		Helper::check_nonce_and_capability();

		$rules = array(
			'topic_id'   => 'required|numeric',
			'content_id' => 'required|numeric',
		);

		Helper::check_validation( $rules );

		$user_id    = get_current_user_id();
		$topic_id   = Input::post( 'topic_id', 0, Input::TYPE_INT );
		$content_id = Input::post( 'content_id', 0, Input::TYPE_INT );

		$course_id  = tutor_utils()->get_course_id_by( 'topic', $topic_id );
		$has_access = tutor_utils()->can_user_edit_course( $user_id, $course_id );
		if ( ! $course_id || ! $has_access ) {
			$this->json_response( tutor_utils()->error_message() );
			return;
		}

		$content = $this->content_model->get_content( $content_id );
		if ( ! $content ) {
			$this->json_response( tutor_utils()->error_message( 'not_found' ) );
		}

		QueryHelper::delete(
			QueryHelper::prepare_table_name( 'tutor_cb_content_usage' ),
			array(
				'course_id'  => $course_id,
				'topic_id'   => $topic_id,
				'content_id' => $content_id,
			)
		);

		$this->json_response( __( 'Successfully deleted!', 'tutor-pro' ) );
	}

	/**
	 * Handle action when content bank question linked in a quiz.
	 *
	 * @since 3.7.0
	 *
	 * @param int    $quiz_id quiz id.
	 * @param object $question question object.
	 *
	 * @return void
	 */
	public function handle_content_bank_question_linked_to_quiz( $quiz_id, $question ) {
		if ( ! isset( $quiz_id, $question->is_cb_question, $question->question_id, $question->question_order ) ) {
			return;
		}

		$content_usage = $this->content_usage_model->get_row(
			array(
				'quiz_id'     => $quiz_id,
				'question_id' => $question->question_id,
			)
		);

		if ( $content_usage ) {
			$this->content_usage_model->update(
				$content_usage->id,
				array(
					'content_order' => $question->question_order,
				)
			);
		} else {
			$question_record = QuizModel::get_question( $question->question_id );
			$content_id      = $question_record->content_id;
			$collection_id   = wp_get_post_parent_id( $content_id );

			$this->content_usage_model->create(
				array(
					'user_id'       => get_current_user_id(),
					'object_type'   => ContentModel::TYPE_QUESTION,
					'collection_id' => $collection_id,
					'quiz_id'       => $quiz_id,
					'question_id'   => $question->question_id,
					'content_id'    => $content_id,
					'content_order' => $question->question_order,
				)
			);
		}
	}

	/**
	 * Handle content bank question added to quiz.
	 *
	 * @param int    $quiz_id quiz id.
	 * @param object $question question object.
	 *
	 * @return void
	 */
	public function handle_content_bank_question_added_to_quiz( $quiz_id, $question ) {
		if ( ! isset( $quiz_id, $question->is_cb_question, $question->question_id, $question->question_order ) ) {
			return;
		}

		$cb_question_id  = $question->question_id;
		$question_record = QuizModel::get_question( $cb_question_id );
		if ( ! $question_record ) {
			return;
		}

		$new_question_id = $question->new_question_id;
		$content_order   = $question->question_order;
		$content_id      = $question_record->content_id;
		$collection_id   = wp_get_post_parent_id( $content_id );
		$topic_id        = wp_get_post_parent_id( $quiz_id );
		$course_id       = wp_get_post_parent_id( $topic_id );

		$this->content_usage_model->add_usage(
			array(
				'object_type'        => ContentModel::TYPE_QUESTION,
				'collection_id'      => $collection_id,
				'content_id'         => $content_id,
				'content_order'      => $content_order,
				'course_id'          => $course_id,
				'topic_id'           => $topic_id,
				'copied_post_id'     => null,
				'quiz_id'            => $quiz_id,
				'question_id'        => $cb_question_id,
				'copied_question_id' => $new_question_id,
			)
		);
	}

	/**
	 * Handle content bank question usage deleted from quiz.
	 *
	 * @since 3.7.0
	 *
	 * @param array $deleted_question_ids deleted question ids.
	 *
	 * @return void
	 */
	public function handle_question_deleted_from_quiz( $deleted_question_ids ) {
		$payload = $_POST['payload'] ?? array(); //phpcs:ignore
		if ( is_string( $payload ) ) {
			$payload = json_decode( wp_unslash( $payload ), true );
		}

		if ( ! isset( $payload['ID'] ) ) {
			return;
		}

		$quiz_id = $payload['ID'];

		QueryHelper::bulk_delete(
			QueryHelper::prepare_table_name( 'tutor_cb_content_usage' ),
			array(
				'object_type' => ContentModel::TYPE_QUESTION,
				'quiz_id'     => $quiz_id,
				'question_id' => array( 'IN', $deleted_question_ids ),
			),
		);
	}
}

<?php
/**
 * Manage Content Bank Contents
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank\Controllers;

use Tutor\Helpers\HttpHelper;
use Tutor\Helpers\QueryHelper;
use Tutor\Helpers\ValidationHelper;
use TUTOR\Input;
use TUTOR\Lesson;
use Tutor\Models\LessonModel;
use Tutor\Models\QuizModel;
use TUTOR\QuizBuilder;
use Tutor\Traits\JsonResponse;
use TUTOR_ASSIGNMENTS\Assignments;
use TutorPro\ContentBank\Helper;
use TutorPro\ContentBank\Models\ContentModel;

/**
 * ContentController Class.
 *
 * @since 3.7.0
 */
class ContentController extends BaseController {
	use JsonResponse;

	/**
	 * Register hooks and dependencies
	 *
	 * @since 3.7.0
	 */
	public function __construct() {
		parent::__construct();

		add_action( 'wp_ajax_tutor_content_bank_contents', array( $this, 'ajax_get_content_bank_contents' ) );
		add_action( 'wp_ajax_tutor_content_bank_content_move', array( $this, 'ajax_move_content_bank_content' ) );
		add_action( 'wp_ajax_tutor_content_bank_content_delete', array( $this, 'ajax_delete_content_bank_content' ) );

		$lesson_cls = new Lesson( false );
		add_action( 'wp_ajax_tutor_content_bank_lesson_save', array( $this, 'ajax_save_lesson' ) );
		add_action( 'save_post_' . ContentModel::LESSON_POST_TYPE, array( $lesson_cls, 'save_lesson_meta' ) );
		add_action( 'wp_ajax_tutor_content_bank_lesson_details', array( $this, 'ajax_get_lesson_details' ) );

		add_action( 'wp_ajax_tutor_content_bank_assignment_save', array( $this, 'ajax_save_assignment' ) );
		add_action( 'wp_ajax_tutor_content_bank_assignment_details', array( $this, 'ajax_get_assignment_details' ) );

		add_action( 'wp_ajax_tutor_content_bank_question_save', array( $this, 'ajax_save_question' ) );
		add_action( 'wp_ajax_tutor_content_bank_question_details', array( $this, 'ajax_get_question_details' ) );

		add_action( 'wp_ajax_tutor_content_bank_content_duplicate', array( $this, 'ajax_duplicate_content' ) );

		add_action( 'wp_ajax_tutor_content_bank_content_synchronize', array( $this, 'ajax_synchronize_content' ) );
	}

	/**
	 * Synchronize course contents with content bank.
	 *
	 * @since 3.7.1
	 */
	public function ajax_synchronize_content() {

		$rules = array(
			'collection_id' => 'required|numeric',
			'courses_ids'   => 'required|is_array',
			'sub_contents'  => 'required|is_array',
		);

		Helper::check_validation( $rules );

		$course_ids    = Input::post( 'courses_ids', array(), Input::TYPE_ARRAY );
		$collection_id = Input::post( 'collection_id', 0, Input::TYPE_NUMERIC );
		$sub_contents  = Input::post( 'sub_contents', array(), Input::TYPE_ARRAY );

		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		if ( ! count( $course_ids ) && ! count( $sub_contents ) ) {
			$this->response_bad_request( __( 'Course ids and sub-contents are required', 'tutor-pro' ) );
		}

		$tools          = tutor_pro_tools();
		$failed_courses = array();
		$errors         = array();

		foreach ( $course_ids as $id ) {
			if ( ! tutor_utils()->can_user_edit_course( get_current_user_id(), $id ) ) {
				$this->response_bad_request( tutor_utils()->error_message() );
			}

			$course = get_post( $id );

			if ( ! $course || get_tutor_post_types( 'course' ) !== $course->post_type ) {
				// translators:%d:course id.
				$failed_courses[] = sprintf( __( 'Invalid course : %d', 'tutor-pro' ), $id );
				continue;
			}

			try {
				$data        = $tools->course_exporter->export( $id, $sub_contents, true );
				$course_data = $tools->helper->deep_maybe_unserialize( $data );
				$course_data = wp_json_encode( $course_data, JSON_PRETTY_PRINT );
				$course_data = json_decode( $course_data, true );
			} catch ( \Exception $e ) {
				$failed_courses[] = $course->post_title;
				continue;
			}

			if ( $course_data ) {
				$import_id = $tools->importer->import_content( array( $course_data ), true, $collection_id, true );

				if ( is_wp_error( $import_id ) ) {
					$failed_courses[] = $course->post_title;
				}
			}
		}

		$errors = $tools->error_handler::get_errors();

		if ( $errors ) {
			$tools->error_handler::clear_errors();
			$this->json_response(
				__( 'Course contents synced successfully', 'tutor-pro' ),
				array(
					'failed_courses' => $failed_courses,
					'errors'         => $errors,
				)
			);
		}

		$this->json_response(
			__( 'Course contents synced successfully', 'tutor-pro' ),
			array(
				'failed_courses' => $failed_courses,
			)
		);
	}

	/**
	 * Get content bank contents via AJAX.
	 *
	 * @since 3.7.0
	 */
	public function ajax_get_content_bank_contents() {
		Helper::check_nonce_and_capability();

		$rules = array(
			'collection_id'  => 'required|numeric',
			'exclude'        => 'if_input|is_array',
			'search'         => 'if_input|min_length:1',
			'order'          => 'if_input|match_string:DESC,ASC',
			'content_types'  => 'if_input|is_array',
			'question_types' => 'if_input|is_array',
		);

		Helper::check_validation( $rules );

		$per_page = max( Input::post( 'per_page', 10, Input::TYPE_INT ), 1 );
		$page     = max( Input::post( 'page', 1, Input::TYPE_INT ), 1 );

		$context       = Input::post( 'context', '' );
		$search        = Input::post( 'search', '' );
		$collection_id = Input::post( 'collection_id', 0, Input::TYPE_INT );
		$order         = Input::post( 'order', 'DESC' );
		$content_types = Input::post( 'content_types', array(), Input::TYPE_ARRAY );
		$exclude       = Input::post( 'exclude', array(), Input::TYPE_ARRAY );

		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
			return;
		}

		$where = array(
			'context'     => $context,
			'post_parent' => $collection_id,
		);

		$post_types = array();
		foreach ( $content_types as $content_type ) {
			$post_types[] = ContentModel::get_post_type_by_content_type( $content_type );
		}

		if ( tutor_utils()->count( $post_types ) ) {
			$where['post_type'] = $post_types;
		}

		if ( tutor_utils()->count( $exclude ) ) {
			$where['ID'] = array( 'NOT IN', $exclude );
		}

		if ( ! Helper::is_assignment_addon_enabled() ) {
			$where['post_type'] = array( 'NOT IN', ContentModel::ASSIGNMENT_POST_TYPE );
		}

		$question_types = Input::post( 'question_types', array(), Input::TYPE_ARRAY );
		if ( tutor_utils()->count( $question_types ) ) {
			$where['question_types'] = $question_types;
		}

		$data = $this->content_model->get_contents(
			$where,
			$search,
			$per_page,
			$page,
			'ID',
			$order,
		);

		$collection                    = $this->collection_model->get_row( array( 'ID' => $collection_id ) );
		$data->collection              = $collection;
		$data->collection->count_stats = $this->content_model->get_count_stats( $collection_id );

		$this->json_response(
			__( 'Contents fetched successfully', 'tutor-pro' ),
			$data
		);
	}

	/**
	 * Move content bank content to another collection via AJAX.
	 *
	 * @since 3.7.0
	 */
	public function ajax_move_content_bank_content() {
		Helper::check_nonce_and_capability();

		$rules = array(
			'content_ids'   => 'required|array',
			'collection_id' => 'required|numeric',
		);

		Helper::check_validation( $rules );

		$content_ids   = Input::post( 'content_ids', array(), Input::TYPE_ARRAY );
		$collection_id = Input::post( 'collection_id', 0, Input::TYPE_INT );

		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
			return;
		}

		$content_ids = array_filter( $content_ids, 'is_numeric' );
		if ( empty( $content_ids ) || ! is_array( $content_ids ) ) {
			$this->response_bad_request( __( 'Invalid content id provided', 'tutor-pro' ) );
			return;
		}

		try {

			QueryHelper::update(
				$this->content_model->get_table_name(),
				array(
					'post_parent' => $collection_id,
				),
				array(
					'ID'        => array( 'IN', $content_ids ),
					'post_type' => array( 'IN', $this->content_model->get_content_post_types() ),
				)
			);

			$this->json_response(
				__( 'Content moved successfully', 'tutor-pro' )
			);
		} catch ( \Throwable $th ) {
			$this->response_bad_request( __( 'Failed to move content', 'tutor-pro' ) );
		}
	}

	/**
	 * Delete content bank content via AJAX.
	 *
	 * @since 3.7.0
	 */
	public function ajax_delete_content_bank_content() {
		Helper::check_nonce_and_capability();

		$rules = array(
			'content_ids'   => 'required|array',
			'collection_id' => 'required|numeric',
		);

		Helper::check_validation( $rules );

		$content_ids   = Input::post( 'content_ids', array(), Input::TYPE_ARRAY );
		$collection_id = Input::post( 'collection_id', 0, Input::TYPE_INT );

		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
			return;
		}

		$content_ids = array_filter( $content_ids, 'is_numeric' );
		if ( empty( $content_ids ) || ! is_array( $content_ids ) ) {
			$this->response_bad_request( __( 'Invalid content id provided', 'tutor-pro' ) );
			return;
		}

		try {
			foreach ( $content_ids as $content_id ) {
				$this->content_model->delete( $content_id );
			}

			$this->json_response(
				__( 'Content deleted successfully', 'tutor-pro' )
			);
		} catch ( \Throwable $th ) {
			$this->response_bad_request( __( 'Failed to delete content', 'tutor-pro' ) );
		}
	}

	/**
	 * Save lesson content via AJAX.
	 *
	 * @since 3.7.0
	 */
	public function ajax_save_lesson() {
		Helper::check_nonce_and_capability();

		$rules = array(
			'collection_id' => 'required|numeric',
			'title'         => 'required',
			'lesson_id'     => 'if_input|numeric',
		);

		Helper::check_validation( $rules );

		$collection_id = Input::post( 'collection_id', 0, Input::TYPE_INT );
		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		$title       = Input::post( 'title' );
		$description = Input::post( 'description', '', Input::TYPE_KSES_POST );
		$lesson_id   = Input::post( 'lesson_id', 0, Input::TYPE_INT );
		$is_update   = $lesson_id ? true : false;

		$is_html_active   = Input::post( 'is_html_active' ) === 'true' ? true : false;
		$raw_html_content = Input::post( 'tutor_lesson_modal_editor', '', Input::TYPE_KSES_POST );
		$post_content     = $is_html_active ? $raw_html_content : $description;

		$lesson_data = array(
			'post_type'      => ContentModel::LESSON_POST_TYPE,
			'post_parent'    => $collection_id,
			'post_title'     => $title,
			'post_name'      => sanitize_title( $title ),
			'post_content'   => $post_content,
			'post_status'    => 'publish',
			'comment_status' => 'open',
			'post_author'    => get_current_user_id(),
		);

		if ( $is_update ) {
			$lesson_data['ID'] = $lesson_id;
			unset( $lesson_data['post_parent'] );
			wp_update_post( $lesson_data );
		} else {
			$lesson_id = wp_insert_post( $lesson_data );
		}

		$response_message = $is_update
							? __( 'Lesson updated successfully', 'tutor-pro' )
							: __( 'Lesson created successfully', 'tutor-pro' );

		$this->json_response( $response_message );
	}

	/**
	 * Get lesson details via AJAX
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function ajax_get_lesson_details() {
		Helper::check_nonce_and_capability();

		$lesson_id = Input::post( 'lesson_id', 0, Input::TYPE_INT );

		$lesson_record = $this->content_model->get_content( $lesson_id, $this->content_model::LESSON_POST_TYPE );

		if ( ! $lesson_record ) {
			$this->response_bad_request( __( 'Invalid lesson id provided', 'tutor-pro' ) );
		}

		$collection_id = $lesson_record->post_parent;
		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		$data = LessonModel::get_lesson_details( $lesson_id );

		$this->json_response(
			__( 'Lesson data fetched successfully', 'tutor-pro' ),
			$data
		);
	}

	/**
	 * Save assignment content via AJAX.
	 *
	 * @since 3.7.0
	 */
	public function ajax_save_assignment() {
		Helper::check_nonce_and_capability();

		if ( ! Helper::is_assignment_addon_enabled() ) {
			$this->response_bad_request( __( 'This action requires the Assignment addon to be enabled', 'tutor-pro' ) );
		}

		$rules = array(
			'collection_id' => 'required|numeric',
			'title'         => 'required',
			'assignment_id' => 'if_input|numeric',
		);

		Helper::check_validation( $rules );

		$collection_id = Input::post( 'collection_id', 0, Input::TYPE_INT );
		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		$user_id           = get_current_user_id();
		$assignment_id     = Input::post( 'assignment_id', 0, Input::TYPE_INT );
		$title             = Input::post( 'title', '' );
		$summary           = Input::post( 'summary', '', Input::TYPE_KSES_POST );
		$assignment_option = tutor_utils()->avalue_dot( 'assignment_option', $_POST ); //phpcs:ignore
		$attachments       = tutor_utils()->avalue_dot( 'attachments', $_POST ); //phpcs:ignore
		$is_update         = $assignment_id ? true : false;

		if ( isset( $assignment_option['time_duration'], $assignment_option['time_duration']['value'] ) ) {
			$assignment_option['time_duration']['value'] = (int) $assignment_option['time_duration']['value'];
		}

		$assignment_data = array(
			'post_type'    => ContentModel::ASSIGNMENT_POST_TYPE,
			'post_status'  => 'publish',
			'post_author'  => $user_id,
			'post_parent'  => $collection_id,
			'post_title'   => $title,
			'post_name'    => sanitize_title( $title ),
			'post_content' => $summary,
		);

		$total_mark = isset( $assignment_option['total_mark'] ) ? sanitize_text_field( $assignment_option['total_mark'] ) : 0;
		$pass_mark  = isset( $assignment_option['pass_mark'] ) ? sanitize_text_field( $assignment_option['pass_mark'] ) : 0;

		// Create or update assignment post.
		if ( ! $is_update ) {
			$assignment_id = wp_insert_post( $assignment_data );

			if ( is_wp_error( $assignment_id ) ) {
				$this->response_bad_request( $assignment_id->get_error_message() );
			}
		} else {
			$assignment_data['ID'] = $assignment_id;
			wp_update_post( $assignment_data );
		}

		// Update assignment options as meta.
		update_post_meta( $assignment_id, 'assignment_option', $assignment_option );

		// Update assignment total_mark & pass_mark meta @since v2.0.0.
		update_post_meta( $assignment_id, '_tutor_assignment_total_mark', $total_mark );
		update_post_meta( $assignment_id, '_tutor_assignment_pass_mark', $pass_mark );

		// Update assignment attachments.
		if ( tutor_utils()->count( $attachments ) ) {
			update_post_meta( $assignment_id, '_tutor_assignment_attachments', $attachments );
		} else {
			delete_post_meta( $assignment_id, '_tutor_assignment_attachments' );
		}

		$response_message = $is_update
							? __( 'Assignment updated successfully', 'tutor-pro' )
							: __( 'Assignment created successfully', 'tutor-pro' );

		$this->json_response( $response_message );
	}

	/**
	 * Get assignment details via AJAX
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function ajax_get_assignment_details() {
		Helper::check_nonce_and_capability();

		if ( ! Helper::is_assignment_addon_enabled() ) {
			$this->response_bad_request( __( 'This action requires the Assignment addon to be enabled', 'tutor-pro' ) );
		}

		$assignment_id     = Input::post( 'assignment_id', 0, Input::TYPE_INT );
		$assignment_record = $this->content_model->get_content( $assignment_id, $this->content_model::ASSIGNMENT_POST_TYPE );

		if ( ! $assignment_record ) {
			$this->response_bad_request( __( 'Invalid id provided', 'tutor-pro' ) );
		}

		$collection_id = $assignment_record->post_parent;
		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		$data = Assignments::get_assignment_details( $assignment_id );

		$this->json_response(
			__( 'Assignment data fetched successfully', 'tutor-pro' ),
			$data
		);
	}

	/**
	 * Save question content via AJAX.
	 *
	 * @since 3.7.0
	 */
	public function ajax_save_question() {
		Helper::check_nonce_and_capability();

		$payload    = $_POST['payload'] ?? array(); //phpcs:ignore
		if ( is_string( $payload ) ) {
			$payload = json_decode( wp_unslash( $payload ), true );
		}

		$errors = array();
		$rules  = array(
			'collection_id'     => 'required|numeric',
			'question_title'    => 'required',
			'question_settings' => 'required|is_array',
			'question_id'       => 'if_input|numeric',
		);

		$validation = ValidationHelper::validate( $rules, $payload );

		if ( ! $validation->success ) {
			$errors = $validation->errors;
		}

		if ( count( $errors ) > 0 ) {
			$this->json_response(
				tutor_utils()->error_message( 'validation_error' ),
				$errors,
				HttpHelper::STATUS_UNPROCESSABLE_ENTITY
			);

			return;
		}

		$collection_id = Input::sanitize( $payload['collection_id'] ?? 0, 0, Input::TYPE_INT );
		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		$questions_table = QueryHelper::prepare_table_name( 'tutor_quiz_questions' );
		$quiz_builder    = new QuizBuilder( false );

		$question_data = $quiz_builder->prepare_question_data( null, $payload );
		$question_type = Input::sanitize( $question_data['question_type'] );
		$is_update     = isset( $payload['question_id'] ) && $payload['question_id'] > 0;

		global $wpdb;

		if ( $is_update ) {
			$question_id     = (int) $payload['question_id'];
			$question_record = QueryHelper::get_row( $questions_table, array( 'question_id' => $question_id ), 'question_id' );
			if ( ! $question_record ) {
				$this->response_bad_request( __( 'Invalid question id', 'tutor-pro' ) );
			}

			// Update the question record.
			$wpdb->update(
				$questions_table,
				$question_data,
				array( 'question_id' => $question_id )
			);

			// Now update it to content in custom post type.
			wp_update_post(
				array(
					'ID'           => $question_record->content_id,
					'post_title'   => $question_data['question_title'],
					'post_content' => $question_data['question_description'],
				)
			);
		} else {
			// Create a content to custom post type.
			$content_id = wp_insert_post(
				array(
					'post_type'    => ContentModel::QUESTION_POST_TYPE,
					'post_parent'  => $collection_id,
					'post_title'   => $question_data['question_title'],
					'post_content' => $question_data['question_description'],
				)
			);

			// Now add it to question table with content id.
			$question_data['content_id'] = $content_id;
			$wpdb->insert( $questions_table, $question_data );
			$question_id = $wpdb->insert_id;
		}

		// Save question's answers.
		$question_answers = $payload['question_answers'] ?? array();

		$quiz_builder->save_question_answers( $question_id, $question_type, $question_answers );

		$deleted_answer_ids = $payload['deleted_answer_ids'] ?? array();
		if ( count( $deleted_answer_ids ) ) {
			$id_str = QueryHelper::prepare_in_clause( $deleted_answer_ids );
			//phpcs:ignore -- sanitized $id_str.
			$wpdb->query( "DELETE FROM {$wpdb->prefix}tutor_quiz_question_answers WHERE answer_id IN (" . $id_str . ')' );
		}

		$response_message = $is_update
							? __( 'Question updated successfully', 'tutor-pro' )
							: __( 'Question created successfully', 'tutor-pro' );

		$this->json_response( $response_message );
	}

	/**
	 * Get question details.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function ajax_get_question_details() {
		Helper::check_nonce_and_capability();

		$content_id = Input::post( 'question_id', 0, Input::TYPE_INT );

		$content_record = $this->content_model->get_content( $content_id, $this->content_model::QUESTION_POST_TYPE );

		if ( ! $content_record ) {
			$this->response_bad_request( __( 'Invalid question id provided', 'tutor-pro' ) );
		}

		$collection_id = $content_record->post_parent;
		if ( ! $this->collection_model->has_collection_access( $collection_id ) ) {
			$this->response_bad_request( tutor_utils()->error_message() );
		}

		$question = $this->content_model->get_question_by_content_id( $content_id );
		if ( ! $question ) {
			$this->response_bad_request( __( 'Invalid content id provided', 'tutor-pro' ) );
		}

		$question->question_answers = QuizModel::get_question_answers( $question->question_id, $question->question_type );
		if ( isset( $question->question_settings ) ) {
			$question->question_settings = maybe_unserialize( $question->question_settings );
		}

		$this->json_response(
			__( 'Question data fetched successfully!', 'tutor-pro' ),
			$question
		);
	}

	/**
	 * Duplicate content bank content.
	 *
	 * @since 3.7.0
	 *
	 * @return void
	 */
	public function ajax_duplicate_content() {
		Helper::check_nonce_and_capability();

		$rules = array(
			'content_ids' => 'required|array',
		);

		Helper::check_validation( $rules );

		$content_ids = Input::post( 'content_ids', array(), Input::TYPE_ARRAY );
		$content_ids = array_filter( $content_ids, 'is_numeric' );

		if ( empty( $content_ids ) || ! is_array( $content_ids ) ) {
			$this->response_bad_request( __( 'Invalid content id provided', 'tutor-pro' ) );
			return;
		}

		foreach ( $content_ids as $content_id ) {
			try {
				$this->content_model->duplicate_content( $content_id );
			} catch ( \Exception $e ) {
				$this->response_bad_request( $e->getMessage() );
			}
		}

		$this->json_response( __( 'Content successfully duplicated', 'tutor-pro' ) );
	}
}

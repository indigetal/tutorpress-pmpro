<?php
/**
 * Quiz Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;
use Tutor\Helpers\ValidationHelper;
use TutorPro\ContentBank\Models\ContentModel;
use TUTOR\Quiz;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Quiz Importer Class.
 */
class QuizImporter {

	/**
	 * Quiz Class Instance.
	 *
	 * @since 3.6.0
	 *
	 * @var Quiz
	 */
	private $quiz;

	/**
	 * Quiz Importer Class Constructor.
	 */
	public function __construct() {
		$this->quiz = new Quiz();
	}

	/**
	 * Set meta data of quiz post type.
	 *
	 * @since 3.6.0
	 *
	 * @param array $quiz_meta the quiz meta data to set.
	 * @param int   $quiz_id the quiz post id.
	 *
	 * @return void
	 */
	public function set_quiz_meta( $quiz_meta, $quiz_id ) {
		$quiz_meta = array_map( fn( $val ) => $val[0], $quiz_meta );
		if ( isset( $quiz_meta['_content_drip_settings'] ) ) {
			update_post_meta( $quiz_id, '_content_drip_settings', $quiz_meta['_content_drip_settings'] );
		}

		if ( isset( $quiz_meta[ QUIZ::META_QUIZ_OPTION ] ) ) {
			update_post_meta( $quiz_id, QUIZ::META_QUIZ_OPTION, $quiz_meta[ QUIZ::META_QUIZ_OPTION ] );
		}
	}

	/**
	 * Flatten all nested quiz question and answer data to a single array.
	 *
	 * @since 3.6.0
	 *
	 * @since 3.7.0 param $question and $answers added.
	 *
	 * @param array $data the quiz question answer data to flatten.
	 * @param int   $content_id the content id for the question.
	 * @param array $question array of question data.
	 * @param array $answers array of answer data.
	 *
	 * @return array
	 */
	public function flatten_quiz_question_answer( $data, $content_id = 0, $question = array(), $answers = array() ) {
		$flatten_content = array();

		if ( $data ) {
			foreach ( $data as $post_id => $question_answer ) {
				$questions = array_column( $question_answer, 'question' );
				$answers   = array_column( $question_answer, 'answers' );
				$answers   = array_merge( ...$answers );
				foreach ( $questions as $questions_data ) {
					$questions_data['quiz_id']     = $post_id;
					$flatten_content['question'][] = $questions_data;
				}

				foreach ( $answers as $answers_data ) {
					$flatten_content['answers'][] = $answers_data;
				}
			}
		}

		if ( $content_id ) {

			$question['content_id']        = $content_id;
			$flatten_content['question'][] = $question;

			foreach ( $answers as $answers_data ) {
				$flatten_content['answers'][] = $answers_data;
			}
		}

		return $flatten_content;
	}

	/**
	 * Prepare quiz question answer data for bulk insertion.
	 *
	 * @since 3.6.0
	 * @since 3.8.1 param $from_course added.
	 *
	 * @param array $quiz_questions_answers the quiz question answer data to prepare.
	 * @param bool  $from_course whether import from course.
	 *
	 * @return array
	 */
	private function prepare_quiz_questions_answers( $quiz_questions_answers, $from_course = false ) {
		$questions       = $quiz_questions_answers['question'];
		$answers         = $quiz_questions_answers['answers'];
		$final_questions = array();
		$final_answers   = array();

		$question_types = array_keys( tutor_utils()->get_question_types() );

		foreach ( $questions as $question ) {
			if ( ! $from_course ) {
				$rules = array(
					'question_mark'     => 'required|numeric',
					'question_title'    => 'required',
					'question_type'     => 'required|match_string:' . implode( ',', $question_types ),
					'question_settings' => 'required',
				);

				$validate = ValidationHelper::validate( $rules, $question );

				if ( ! $validate->success ) {
					continue;
				}
			}

			if ( isset( $question['question_type'] ) ) {
				if ( 'image_matching' === $question['question_type'] ) {
					$question['question_type'] = 'matching';
				}

				if ( 'single_choice' === $question['question_type'] ) {
					$question['question_type'] = 'multiple_choice';
				}
			}

			$question['question_title'] = addslashes( $question['question_title'] );

			if ( isset( $question['answer_explanation'] ) ) {
				$question['answer_explanation'] = addslashes( $question['answer_explanation'] );
			}

			$question_settings = $question['question_settings'];

			if ( isset( $question_settings['question_type'] ) && ! in_array( $question_settings['question_type'], $question_types ) ) {
				continue;
			}

			if ( isset( $question_settings['question_type'] ) ) {
				if ( 'single_choice' === $question_settings['question_type'] ) {
					$question_settings['question_type'] = 'multiple_choice';
				}

				if ( 'image_matching' === $question_settings['question_type'] ) {
					$question_settings['is_image_matching'] = true;
				}
			}

			$question['question_settings'] = maybe_serialize( $question_settings );

			if ( isset( $question['question_id'] ) ) {
				unset( $question['question_id'] );
			}

			array_push( $final_questions, $question );
		}

		foreach ( $answers as $answer ) {
			if ( ! $from_course ) {
				$rules = array(
					'belongs_question_id'   => 'required|numeric',
					'answer_title'          => 'required',
					'question_type'         => '',
					'belongs_question_type' => 'required|match_string:' . implode( ',', $question_types ),
				);

				$validate = ValidationHelper::validate( $rules, $answer );

				if ( ! $validate->success ) {
					continue;
				}
			}

			if ( isset( $answer['belongs_question_type'] ) ) {
				if ( 'single_choice' === $answer['belongs_question_type'] ) {
					$answer['belongs_question_type'] = 'multiple_choice';
				}

				if ( 'image_matching' === $answer['belongs_question_type'] ) {
					$answer['belongs_question_type'] = 'matching';
				}
			}

			if ( isset( $question['answer_title'] ) ) {
				$question['answer_title'] = addslashes( $question['answer_title'] );
			}

			if ( $answer['image_url'] ) {
				$upload_data = Helper::upload_file_by_url( $answer['image_url'] );

				if ( ! is_wp_error( $upload_data ) ) {
					$answer['image_id'] = $upload_data['id'];
				}
			}

			unset( $answer['image_url'] );

			if ( isset( $answer['answer_id'] ) ) {
				unset( $answer['answer_id'] );
			}

			array_push( $final_answers, $answer );
		}

		return array(
			'question' => $final_questions,
			'answers'  => $final_answers,
		);
	}


	/**
	 * Bulk save quiz question and answer into database.
	 *
	 * @since 3.6.0
	 *
	 * @param array $quiz_questions_answers the quiz question answers to save.
	 * @param bool  $from_course is the import for course.
	 * @param int   $parent_id the parent course id.
	 * @param int   $quiz_id the quiz id.
	 *
	 * @return bool|\WP_Error
	 */
	public function save_quiz_questions_answers( $quiz_questions_answers, $from_course = false, $parent_id = 0, $quiz_id = 0 ) {
		global $wpdb;

		$result = true;

		$table_question = "{$wpdb->prefix}tutor_quiz_questions";
		$table_answer   = "{$wpdb->prefix}tutor_quiz_question_answers";

		$questions             = $quiz_questions_answers['question'];
		$answers               = $quiz_questions_answers['answers'];
		$previous_question_ids = array_column( $questions, 'question_id' );
		$previous_answer_ids   = $answers ? array_filter( array_column( $answers, 'answer_id' ) ) : null; // filter to remove null values.
		$question_ids          = array();
		$answer_ids            = array();

		$quiz_questions_answers = $this->prepare_quiz_questions_answers( $quiz_questions_answers, $from_course );

		$answers   = $quiz_questions_answers['answers'];
		$questions = $quiz_questions_answers['question'];

		$courses_map = ContentMapHandler::get_content_map()['courses'] ?? null;

		try {
			$result = QueryHelper::insert_multiple_rows( $table_question, $questions, false, false );
			if ( $result ) {
				$question_ids = $wpdb->get_results(//phpcs:ignore
					"SELECT question_id FROM {$table_question} WHERE question_id >= LAST_INSERT_ID()", //phpcs:ignore
					'ARRAY_N'
				);
				// Flatten ids.
				$question_ids = array_merge( ...$question_ids );
			}
		} catch ( \Exception $e ) {
			return new \WP_Error( 'db_insert_fail', __( 'Error inserting quiz questions', 'tutor-pro' ), $e->getMessage() );
		}

		if ( count( $question_ids ) !== count( $previous_question_ids ) ) {
			return new \WP_Error( 'question_mismatch_error', __( 'Invalid quiz question data', 'tutor-pro' ) );
		}

		if ( $question_ids && $previous_question_ids ) {
			$question_ids = array_combine( $previous_question_ids, $question_ids );
		}

		if ( $answers && $question_ids ) {
			$answers = array_map(
				function ( $val ) use ( $question_ids ) {
					$val['belongs_question_id'] = $question_ids[ $val['belongs_question_id'] ];
					return $val;
				},
				$answers
			);
		}

		if ( $answers ) {
			try {
				$result = QueryHelper::insert_multiple_rows( $table_answer, $answers, false, false );
				if ( $result ) {
					$answer_ids = $wpdb->get_results(//phpcs:ignore
					"SELECT answer_id FROM {$table_answer} WHERE answer_id >= LAST_INSERT_ID()", //phpcs:ignore
						'ARRAY_N'
					);
					// Flatten ids.
					$answer_ids = array_merge( ...$answer_ids );
				}
			} catch ( \Exception $e ) {
				return new \WP_Error( 'db_insert_fail', __( 'Error inserting quiz questions', 'tutor-pro' ), $e->getMessage() );
			}

			if ( count( $answer_ids ) !== count( $previous_answer_ids ) ) {
				return new \WP_Error( 'answer_mismatch_error', __( 'Invalid quiz answer data', 'tutor-pro' ) );
			}

			if ( $answer_ids && $previous_answer_ids ) {
				$answer_ids = array_combine( $previous_answer_ids, $answer_ids );
			}
		}

		if ( $parent_id && $quiz_id && $courses_map ) {
			if ( isset( $courses_map[ $parent_id ] ) ) {
				$map = $courses_map[ $parent_id ];
				if ( isset( $map[ tutor()->quiz_post_type ] ) && isset( $map[ tutor()->quiz_post_type ][ $quiz_id ] ) ) {
					$quiz_map             = $map[ tutor()->quiz_post_type ][ $quiz_id ];
					$quiz_map['question'] = $question_ids;
					$quiz_map['answers']  = $answer_ids;

					$map[ tutor()->quiz_post_type ][ $quiz_id ] = $quiz_map;
				}

				$courses_map[ $parent_id ] = $map;
			}
		}

		ContentMapHandler::update_content_map( 'courses', $courses_map );

		unset( $questions );
		unset( $answers );
		unset( $previous_question_ids );
		unset( $previous_answer_ids );
		unset( $question_ids );
		unset( $answer_ids );
		unset( $courses_map );

		return $result;
	}

	/**
	 * Insert quiz contents.
	 *
	 * @since 3.8.1
	 *
	 * @param array   $content the list of quiz contents.
	 * @param boolean $keep_media_files whether to keep media files.
	 * @param int     $parent_id the parent course id.
	 *
	 * @return int|\WP_Error
	 */
	public function insert_quiz( $content, $keep_media_files = false, $parent_id = 0 ) {
		global $wpdb;

		$previous_quiz_id = $content['ID'] ?? 0;
		$meta             = $content['meta'] ?? null;
		$question_answer  = $content['question_answer'] ?? null;
		$question         = $content['question'] ?? null;
		$answers          = $content['answers'] ?? null;

		$courses_map = ContentMapHandler::get_content_map()['courses'] ?? null;

		$content = Helper::unset_post_data( $content );

		$content_id = wp_insert_post( $content, true, true );

		if ( is_wp_error( $content_id ) ) {
			return $content_id;
		}

		if ( get_post_type( $content_id ) === ContentModel::QUESTION_POST_TYPE ) {
			$quiz_question_answer = $this->flatten_quiz_question_answer( null, $content_id, $question, $answers );

			$result = $this->save_quiz_questions_answers( $quiz_question_answer );
			if ( is_wp_error( $result ) ) {
				ErrorHandler::set_error( $content['post_type'], 'Error adding question answer for quiz : ' . $content['post_title'] );
			}
		}

		if ( $meta && tutor()->quiz_post_type === get_post_type( $content_id ) ) {
			$meta = Helper::prepare_meta( $content_id, $meta, $keep_media_files );
			try {
				QueryHelper::insert_multiple_rows( $wpdb->postmeta, $meta, false, false );
			} catch ( \Throwable $th ) {
				ErrorHandler::set_error( $content['post_type'], 'Error saving meta value for quiz : ' . $content['post_title'] );
			}
		}

		if ( get_tutor_post_types( 'quiz' ) === get_post_type( $content_id ) ) {
			if ( $courses_map && $parent_id ) {
				if ( isset( $courses_map[ $parent_id ] ) ) {
					$map = $courses_map[ $parent_id ];
					$map[ tutor()->quiz_post_type ][ $previous_quiz_id ] = array( 'quiz_id' => $content_id );
					$courses_map[ $parent_id ]                           = $map;
				}
			}

			ContentMapHandler::update_content_map( 'courses', $courses_map );
			unset( $courses_map );

			if ( $question_answer ) {
				$quiz_question_answer = $this->flatten_quiz_question_answer( array( $content_id => $question_answer ) );
				$this->save_quiz_questions_answers( $quiz_question_answer, false, $parent_id, $previous_quiz_id );
			}
		}

		unset( $meta );
		unset( $quiz_question_answer );
		unset( $question );
		unset( $answers );

		return $content_id;
	}
}

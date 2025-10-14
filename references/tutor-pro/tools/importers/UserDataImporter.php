<?php
/**
 * User Data Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * UserDataImporter class
 */
class UserDataImporter {

	/**
	 * Gradebook results table.
	 *
	 * @var string
	 */
	private $gradebook_result_table = 'tutor_gradebooks_results';

	/**
	 * Quiz Attempt table
	 *
	 * @var string
	 */
	private $table_quiz_attempts = 'tutor_quiz_attempts';

	/**
	 * Quiz Attempt answers table
	 *
	 * @var string
	 */
	private $table_quiz_attempts_answers = 'tutor_quiz_attempt_answers';

	/**
	 * Prepare user meta values.
	 *
	 * @since 3.8.1
	 *
	 * @param integer $user_id the user id.
	 * @param array   $user_meta array of user meta.
	 * @param integer $course_id the course id.
	 * @param bool    $user_exist whether user is previous user.
	 *
	 * @return array
	 */
	public function prepare_user_meta( int $user_id, array $user_meta, int $course_id, bool $user_exist = false ) {

		$user_meta = array_map( fn( $val ) => $val[0], $user_meta );

		unset( $user_meta['_tutor_instructor_course_id'] );

		$content_map = ContentMapHandler::get_content_map();
		$courses_map = $content_map['courses'] ?? null;
		$course_map  = $courses_map[ $course_id ] ?? null;

		unset( $content_map );
		unset( $courses_map );

		foreach ( $user_meta as $key => $value ) {
			if ( preg_match( '/_tutor_completed_lesson_id/', $key ) ) {
				$old_key  = $key;
				$meta_key = explode( '_', $key );

				if ( ! is_array( $meta_key ) && ! count( $meta_key ) ) {
					continue;
				}

				$lesson_id = $meta_key[ count( $meta_key ) - 1 ];

				unset( $meta_key[ count( $meta_key ) - 1 ] );

				if ( ! preg_match( '/^\d+$/', $lesson_id ) ) {
					continue;
				}

				$lesson_map = $course_map[ tutor()->lesson_post_type ] ?? null;

				if ( ! $lesson_map || ! isset( $lesson_map[ $lesson_id ] ) ) {
					continue;
				}

				$lesson_id = $lesson_map[ $lesson_id ];
				array_push( $meta_key, $lesson_id );

				unset( $lesson_map );

				$key = implode( '_', $meta_key );

				if ( $user_exist ) {
					update_user_meta( $user_id, $key, $value );
				}

				unset( $user_meta[ $old_key ] );

				$user_meta[ $key ] = $value;
			}
		}

		return $user_meta;
	}

	/**
	 * Create user for tutor enrollment.
	 *
	 * @since 3.8.1
	 *
	 * @param array   $user_data the user data array.
	 * @param array   $user_meta the user meta array.
	 * @param integer $course_id the course id.
	 *
	 * @return int|\WP_Error
	 */
	public function create_user( array $user_data, array $user_meta, int $course_id ) {
		global $wpdb;
		$data             = $user_data['data'] ?? null;
		$previous_user_id = $user_data['ID'] ?? null;
		$user_email       = $data['user_email'] ?? '';
		$roles            = $user_data['roles'] ?? array();
		$caps             = $user_data['caps'] ?? array();
		$meta             = $user_meta;
		$users_map        = ContentMapHandler::get_content_map()['users'] ?? array();

		unset( $user_data['filter'] );
		unset( $user_data['ID'] );
		unset( $user_meta );

		if ( email_exists( $user_email ) ) {

			$user = get_user_by( 'email', $user_email );

			if ( ! $users_map ) {
				ContentMapHandler::set_content_map( 'users', array( $previous_user_id => $user->ID ) );
			} else {
				$users_map[ $previous_user_id ] = $user->ID;
				ContentMapHandler::update_content_map( 'users', $users_map );
			}

			$this->prepare_user_meta( $user->ID, $meta, $course_id, true );

			return $user->ID;
		} else {
			unset( $data['ID'] );

			$data['role'] = '';

			if ( username_exists( $data['user_login'] ) ) {
				$data['user_login'] = tutor_utils()->create_unique_username( $data['user_email'] );
				unset( $data['user_nicename'] );
			}

			$new_user_id = wp_insert_user( $data );

			if ( is_wp_error( $new_user_id ) ) {
				return $new_user_id;
			}

			if ( $meta ) {
				$meta = $this->prepare_user_meta( $new_user_id, $meta, $course_id );
			}

			$data['ID']         = $new_user_id;
			$data['meta_input'] = $meta;

			// wp_insert_user is rehashing an already hashed password.
			$new_user_id = wp_insert_user( $data );

			if ( ! $users_map ) {
				ContentMapHandler::set_content_map( 'users', array( $previous_user_id => $new_user_id ) );
			} else {
				$users_map[ $previous_user_id ] = $new_user_id;
				ContentMapHandler::update_content_map( 'users', $users_map );
			}

			$new_user = new \WP_User( $new_user_id );

			if ( count( $roles ) ) {
				foreach ( $roles as $role ) {
					$new_user->add_role( $role );
				}
			}

			if ( count( $caps ) ) {
				foreach ( $caps as $cap => $grant ) {
					$new_user->add_cap( $cap, $grant );
				}
			}

			unset( $new_user );
			unset( $roles );
			unset( $caps );
			unset( $data );

			return $new_user_id;
		}
	}

	/**
	 * Insert user quiz attempts.
	 *
	 * @since 3.8.1
	 *
	 * @throws \Throwable If insertion error.
	 *
	 * @param array $quiz_attempts the list of quiz attempts.
	 *
	 * @return void
	 */
	public function insert_quiz_attempts( array $quiz_attempts ) {
		global $wpdb;

		$content_map      = ContentMapHandler::get_content_map();
		$attempts_answers = array();
		$courses_map      = $content_map['courses'] ?? null;
		$user_map         = $content_map['users'] ?? null;
		$attempt_id       = $quiz_attempts['attempt_id'] ?? 0;
		$quiz_map         = array();

		unset( $content_map );
		unset( $quiz_attempts['attempt_id'] );

		// Prepare quiz attempts.
		$user_id   = $user_map[ $quiz_attempts['user_id'] ] ?? $quiz_attempts['user_id'];
		$quiz_id   = $quiz_attempts['quiz_id'] ?? 0;
		$course_id = $quiz_attempts['course_id'] ?? 0;

		if ( $courses_map && isset( $courses_map[ $course_id ] ) ) {
			$map = $courses_map[ $course_id ];

			$course_id = $map['course_id'];

			if ( isset( $map[ tutor()->quiz_post_type ] ) && isset( $map[ tutor()->quiz_post_type ][ $quiz_id ] ) ) {
				// this map will be used by the answers array.
				$quiz_map[ $quiz_id ] = $map[ tutor()->quiz_post_type ][ $quiz_id ];

				$quiz_id = $map[ tutor()->quiz_post_type ][ $quiz_id ]['quiz_id'];
			}
		}

		$quiz_attempts['course_id'] = $course_id;
		$quiz_attempts['quiz_id']   = $quiz_id;

		$answers = $quiz_attempts['quiz_attempt_answers'] ?? array();

		unset( $quiz_attempts['quiz_attempt_answers'] );

		// Insert all nested attempt answers in a single array.
		if ( $answers ) {
			array_push( $attempts_answers, ...$answers );
			unset( $answers );
		}

		if ( isset( $quiz_attempts['attempt_info'] ) ) {
			$quiz_attempts['attempt_info'] = maybe_serialize( $quiz_attempts['attempt_info'] );
		}

		if ( $user_id ) {
			$quiz_attempts['user_id'] = $user_id;
		}

		try {
			$attempt_id = QueryHelper::insert( $this->table_quiz_attempts, $quiz_attempts, array( 'attempt_info' => 'wp_kses_post' ) );
		} catch ( \Throwable $th ) {
			throw $th;
		}

		unset( $quiz_attempts );

		if ( count( $attempts_answers ) ) {
			$attempts_answers_count = count( $attempts_answers );

			// Prepare attempt answers.
			for ( $i = 0; $i < $attempts_answers_count; $i++ ) {
				$quiz_id                                   = $attempts_answers[ $i ]['quiz_id'];
				$user_id                                   = $user_map[ $attempts_answers[ $i ]['user_id'] ] ?? $attempts_answers[ $i ]['user_id'];
				$attempts_answers[ $i ]['quiz_attempt_id'] = $attempt_id;
				$map                                       = $quiz_map[ $quiz_id ] ?? null; // quiz map created on the loop above.

				if ( $user_id ) {
					$attempts_answers[ $i ]['user_id'] = $user_id;
				}

				unset( $attempts_answers[ $i ]['attempt_answer_id'] );

				if ( ! $map ) {
					continue;
				}

				$question          = $map['question'] ?? null;
				$answers           = $map['answers'] ?? null;
				$question_id       = $attempts_answers[ $i ]['question_id'];
				$given_answers     = $attempts_answers[ $i ]['given_answer'];
				$new_given_answers = array();
				$new_given_answer  = '';

				if ( isset( $question[ $question_id ] ) ) {
					$question_id                           = $question[ $question_id ];
					$attempts_answers[ $i ]['question_id'] = $question_id;
				} else {
					unset( $attempts_answers[ $i ] );
					continue;
				}

				if ( ! is_array( $given_answers ) ) {
					// Check if the answer has only numeric value, means it is a answer id.
					if ( preg_match( '/^\d+$/', $given_answers ) ) {
						$answer_id        = (int) $given_answers;
						$answer_id        = $answers[ $answer_id ] ?? 0; // swap answer id.
						$new_given_answer = $answer_id;
					} else {
						$new_given_answer = $given_answers;
					}

					$attempts_answers[ $i ]['given_answer'] = $new_given_answer;
				}

				if ( is_array( $given_answers ) && count( $given_answers ) ) {
					// Some question have multiple answers.
					foreach ( $given_answers as $key => $answer ) {
						if ( preg_match( '/^\d+$/', $answer ) ) {
							$answer_id           = (int) $answer;
							$answer_id           = $answers[ $answer_id ] ?? $answer_id;
							$new_given_answers[] = $answer_id;
						} elseif ( isset( $answers[ $key ] ) ) {
							$new_given_answers[ $answers[ $key ] ] = $answer;
						} else {
							$new_given_answers[] = $answer;
						}
					}

					$attempts_answers[ $i ]['given_answer'] = maybe_serialize( $new_given_answers );
				}

				$attempts_answers[ $i ]['quiz_id'] = $map['quiz_id'];
				unset( $new_given_answers );
				unset( $new_given_answer );
				unset( $question );
				unset( $answer );
			}

			try {
				$result = QueryHelper::insert_multiple_rows( $this->table_quiz_attempts_answers, $attempts_answers, false, false );
			} catch ( \Throwable $th ) {
				throw $th;
			}
		}

		unset( $attempts_answers );
		unset( $attempt_ids );
		unset( $quiz_map );
		unset( $user_map );
	}

	/**
	 * Prepare assignment submission meta.
	 *
	 * @since 3.8.1
	 *
	 * @param array $assignment_meta the assignment submission meta.
	 *
	 * @return array|\WP_Error
	 */
	private function prepare_assignment_submission_meta( array $assignment_meta ) {
		$meta        = array_map( fn( $val ) => $val[0], $assignment_meta );
		$attachments = array();

		if ( isset( $meta['uploaded_attachments'] ) ) {
			$attachments = json_decode( $meta['uploaded_attachments'], true );

			foreach ( $attachments as $key => $attachment ) {
				if ( isset( $attachment['url'] ) ) {
					$data = Helper::upload_file_by_url( $attachment['url'] );

					if ( is_wp_error( $data ) ) {
						ErrorHandler::set_error( 'Assignment Submission', $data->get_error_message() );
						continue;
					}

					$assignment_submission_attachment = array(
						'url'           => $attachment['url'],
						'type'          => $data['type']['type'],
						'uploaded_path' => $data['upload_path'],
						'name'          => $data['name'],
					);

					$attachments[ $key ] = $assignment_submission_attachment;
				}
			}

			$meta['uploaded_attachments'] = json_encode( $attachments );
		}

		return $meta;
	}

	/**
	 * Insert tutor course completion data.
	 *
	 * @since 3.8.1
	 *
	 * @throws \Exception If data not found or failed to insert.
	 *
	 * @param array $course_completion the course completion data.
	 *
	 * @return int
	 */
	public function insert_course_completion( array $course_completion ) {
		$completion      = $course_completion['completion'] ?? array();
		$completion_meta = $course_completion['completion_meta'] ?? array();
		$content_map     = ContentMapHandler::get_content_map();
		$courses_map     = $content_map['courses'] ?? null;
		$user_map        = $content_map['users'] ?? null;

		unset( $content_map );
		unset( $course_completion['completion'] );
		unset( $course_completion['completion_meta'] );

		if ( ! $completion ) {
			throw new \Exception( __( 'Course completion data not found', 'tutor-pro' ) );
		}

		$course_id = $courses_map[ $completion['comment_post_ID'] ]['course_id'] ?? $completion['comment_post_ID'];
		$user_id   = $user_map[ $completion['user_id'] ] ?? $completion['user_id'];

		$completion['comment_author']  = $user_id;
		$completion['comment_post_ID'] = $course_id;
		$completion['user_id']         = $user_id;

		unset( $completion['comment_ID'] );

		$comment_id = wp_insert_comment( $completion );

		if ( ! $comment_id ) {
			throw new \Exception( __( 'Error inserting course completion data', 'tutor-pro' ) );
		}

		return $comment_id;
	}

	/**
	 * Insert user assignment submission.
	 *
	 * @since 3.8.1
	 *
	 * @param array $assignment_submission the submission array.
	 *
	 * @return int|\WP_Error
	 */
	public function insert_assignment_submission( array $assignment_submission ) {
		$content_map = ContentMapHandler::get_content_map();
		$courses_map = $content_map['courses'] ?? null;
		$user_map    = $content_map['users'] ?? null;

		unset( $content_map );

		$previous_course_id     = $assignment_submission['comment_parent'] ?? 0;
		$previous_assignment_id = $assignment_submission['comment_post_ID'] ?? 0;
		$assignment_meta        = $assignment_submission['assignment_meta'] ?? null;
		$previous_user_id       = $assignment_submission['user_id'] ?? 0;
		$course_map             = $courses_map[ $previous_course_id ] ?? null;
		$course_id              = 0;
		$assignment_id          = 0;
		$user_id                = 0;

		if ( $course_map ) {
			$course_id      = $course_map['course_id'] ?? $previous_course_id;
			$assignment_map = $course_map[ tutor()->assignment_post_type ] ?? null;
			$assignment_id  = $assignment_map[ $previous_assignment_id ] ?? $previous_assignment_id;
			$user_id        = $user_map[ $previous_user_id ] ?? $previous_user_id;
			unset( $assignment_map );
		}

		unset( $course_map );
		unset( $courses_map );
		unset( $user_map );

		unset( $assignment_submission['comment_ID'] );
		unset( $assignment_submission['comment_parent'] );
		unset( $assignment_submission['comment_post_ID'] );
		unset( $assignment_submission['user_id'] );
		unset( $assignment_submission['assignment_meta'] );

		$assignment_submission['comment_parent']  = $course_id;
		$assignment_submission['comment_post_ID'] = $assignment_id;
		$assignment_submission['user_id']         = $user_id;

		if ( $assignment_meta ) {
			$assignment_meta                       = $this->prepare_assignment_submission_meta( $assignment_meta );
			$assignment_submission['comment_meta'] = $assignment_meta;
		}

		$comment_id = wp_insert_comment( $assignment_submission );

		if ( ! $comment_id ) {
			return new \WP_Error( 'insert_failed', __( 'Error inserting assignment submission', 'tutor-pro' ) );
		}

		return $comment_id;
	}

	/**
	 * Insert gradebook results.
	 *
	 * @since 3.8.1
	 *
	 * @throws \Throwable if data cannot be inserted.
	 *
	 * @param array $gradebook_result the gradebook results list.
	 *
	 * @return void
	 */
	public function insert_gradebook_result( array $gradebook_result ) {
		$content_map = ContentMapHandler::get_content_map();

		$courses_map   = $content_map['courses'] ?? null;
		$user_map      = $content_map['users'] ?? null;
		$gradebook_map = $content_map['gradebooks'] ?? null;

		unset( $content_map );

		foreach ( $gradebook_result as $key => $result ) {
			$previous_gradebook_result_id = $result['gradebook_result_id'];
			unset( $result['gradebook_result_id'] );
			if ( ! isset( $user_map[ $result['user_id'] ] ) ) {
				ErrorHandler::set_error( __( 'Gradebook Results', 'tutor-pro' ), __( 'User id not found for ', 'tutor-pro' ) . $previous_gradebook_result_id );
				unset( $gradebook_result[ $key ] );
				continue;
			}
			$result['user_id'] = $user_map[ $result['user_id'] ];
			if ( isset( $courses_map[ $result['course_id'] ] ) ) {
				$map                 = $courses_map[ $result['course_id'] ];
				$result['course_id'] = $map['course_id'];

				$quiz_map       = $map[ tutor()->quiz_post_type ];
				$assignment_map = $map[ tutor()->assignment_post_type ];

				if ( $result['quiz_id'] ) {
					$result['quiz_id'] = $quiz_map[ $result['quiz_id'] ]['quiz_id'] ?? $result['quiz_id'];
				}

				if ( $result['assignment_id'] ) {
					$result['assignment_id'] = $assignment_map[ $result['assignment_id'] ] ?? $result['assignment_id'];
				}
			}

			$result['gradebook_id'] = $gradebook_map[ $result['gradebook_id'] ] ?? $result['gradebook_id'];

			$gradebook_result[ $key ] = $result;
		}

		try {
			$result = QueryHelper::insert_multiple_rows( $this->gradebook_result_table, $gradebook_result, false, false );
		} catch ( \Throwable $th ) {
			throw $th;
		}
	}
}

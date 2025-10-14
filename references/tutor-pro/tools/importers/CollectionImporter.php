<?php
/**
 * Collection Importer.
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;
use TutorPro\ContentBank\Models\ContentModel;
use WP_Error;

/**
 * Collection Importer Class.
 */
class CollectionImporter {

	/**
	 * Quiz Importer Class Instance.
	 *
	 * @since 3.8.1
	 *
	 * @var QuizImporter
	 */
	private $quiz_importer;


	/**
	 * Collection importer constructor.
	 */
	public function __construct() {
		$this->quiz_importer = new QuizImporter();
	}

	/**
	 * Prepare child contents when importing contents to content bank.
	 *
	 * @since 3.7.1
	 *
	 * @param array $children the child contents array.
	 * @param int   $collection_id the collection id.
	 *
	 * @return array
	 */
	public function prepare_content_bank_question( $children, $collection_id ) {
		$updated_posts = array();

		foreach ( $children as $key => $data ) {
			if ( get_tutor_post_types( 'quiz' ) === $data['post_type'] ) {
				$question_answers = $data['question_answer'] ?? null;
				if ( $question_answers ) {
					foreach ( $question_answers as $question_answer ) {
						$question                 = $question_answer['question'];
						$answers                  = $question_answer['answers'];
						$question['quiz_id']      = 0;
						$post_data['post_title']  = $question['question_title'];
						$post_data['post_type']   = ContentModel::QUESTION_POST_TYPE;
						$post_data['post_status'] = 'draft';
						$post_data['post_parent'] = $collection_id;
						$post_data['question']    = $question;
						$post_data['answers']     = $answers;

						$updated_posts[] = $post_data;
					}
				}
				continue;
			}

			$updated_posts[] = $data;
		}

		return $updated_posts;
	}

	/**
	 * Course data to import
	 *
	 * @since 3.7.1
	 *
	 * @param array $course Course data.
	 * @param bool  $keep_media_files Whether to keep media files or not.
	 * @param int   $collection_id Collection id.
	 * @param bool  $from_course if import from course.
	 *
	 * @return int|WP_Error
	 */
	public function import_content_in_collection( array $course, $keep_media_files, $collection_id, $from_course = false ) {
		$topics = $course['contents'] ?? null;

		if ( empty( $course ) ) {
			return new WP_Error( 400, __( 'Course data is empty', 'tutor-pro' ) );
		}

		if ( ! empty( $topics ) ) {
			foreach ( $topics as $topic ) {
				$topic_contents = $this->prepare_content_bank_question( $topic['children'], $collection_id ) ?? null;
				foreach ( $topic_contents as $k => $content ) {
					$content['post_parent'] = $collection_id;

					if ( ContentModel::QUESTION_POST_TYPE === $content['post_type'] ) {

						$import_id = $this->import_in_collection( $content, false, $from_course );

						if ( is_wp_error( $import_id ) ) {
							ErrorHandler::set_error( $content['post_type'], $content['post_title'] );
						}

						// Free memory for this item.
						unset( $topic_contents[ $k ] );
					} else {
						if ( tutor()->lesson_post_type === $content['post_type'] ) {
							$content['post_type'] = ContentModel::LESSON_POST_TYPE;
						} elseif ( tutor()->assignment_post_type === $content['post_type'] ) {
							$content['post_type'] = ContentModel::ASSIGNMENT_POST_TYPE;
						}

						$import_id = $this->import_in_collection( $content, $keep_media_files );

						if ( is_wp_error( $import_id ) ) {
							ErrorHandler::set_error( $content['post_type'], $content['post_title'] );
						}

						// Free memory for this item.
						unset( $topic_contents[ $k ] );
					}
				}
			}
		} else {
			return new WP_Error( 400, __( 'Course content not available', 'tutor-pro' ) );
		}

		return $course['ID'];
	}

	/**
	 * Import course contents in collection
	 *
	 * @since 3.8.1
	 *
	 * @param array $content Course content post.
	 * @param bool  $keep_media_files Whether to keep media files or not.
	 * @param bool  $from_course if import from course.
	 *
	 * @return int|\WP_Error
	 */
	private function import_in_collection( $content, $keep_media_files = false, $from_course = false ) {
		global $wpdb;

		$post_id = 0;
		if ( ContentModel::QUESTION_POST_TYPE === $content['post_type'] ) {
			$question = $content['question'];
			$answers  = $content['answers'];

			$content = Helper::unset_post_data( $content );

			$post_id = wp_insert_post( $content, true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$question_answer = $this->quiz_importer->flatten_quiz_question_answer( null, $post_id, $question, $answers );
			$this->quiz_importer->save_quiz_questions_answers( $question_answer, $from_course );
		} else {
			$meta             = $content['meta'];
			$thumbnail_url    = $content['thumbnail_url'];
			$attachment_links = $content['attachment_links'];
			$attachment_ids   = array();

			if ( $attachment_links && $keep_media_files ) {
				$attachment_ids = Helper::get_attachment_ids_from_urls( $attachment_links );
			}

			$content = Helper::unset_post_data( $content );

			$post_id = wp_insert_post( $content, true );
			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			if ( $meta ) {
				$meta = Helper::prepare_meta( $post_id, $meta, $keep_media_files );
			}

			if ( $attachment_ids ) {
				if ( ContentModel::ASSIGNMENT_POST_TYPE === get_post_type( $post_id ) ) {
					$meta[] = array(
						'post_id'    => $post_id,
						'meta_key'   => '_tutor_assignment_attachments',
						'meta_value' => maybe_serialize( $attachment_ids ),
					);
				} else {
					$meta[] = array(
						'post_id'    => $post_id,
						'meta_key'   => '_tutor_attachments',
						'meta_value' => maybe_serialize( $attachment_ids ),
					);
				}
			}

			try {
				QueryHelper::insert_multiple_rows( $wpdb->postmeta, $meta, false, false );
			} catch ( \Throwable $th ) {
				ErrorHandler::set_error( $content['post_type'], 'Error saving meta value for content :' . $content['post_title'] );
			}

			// Set thumbnail.
			if ( $thumbnail_url ) {
				Helper::save_post_thumbnail( $thumbnail_url, $post_id );
			}
		}

		return $post_id;
	}
}

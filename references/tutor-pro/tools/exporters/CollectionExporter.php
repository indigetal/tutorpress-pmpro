<?php
/**
 * Content Bank Exporter
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.7.0
 */

namespace TutorPro\Tools;

use Tutor\Models\QuizModel;
use TutorPro\ContentBank\Models\ContentModel;
use TutorPro\ContentBank\Models\CollectionModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle content bank data export
 *
 * @since 3.7.0
 */
class CollectionExporter {

	/**
	 * Export content bank collection
	 *
	 * @since 3.7.0
	 *
	 * @param integer $collection_id Collection id.
	 * @param boolean $keep_media_files To keep media files.
	 *
	 * @throws \Exception For invalid post.
	 *
	 * @return array
	 */
	public static function export( int $collection_id, $keep_media_files = false ) {
		// Course Post.
		$collection_data = get_post( $collection_id );
		if ( ! is_a( $collection_data, 'WP_Post' ) ) {
			throw new \Exception( __( 'Invalid post', 'tutor-pro' ) );
		}

		if ( CollectionModel::POST_TYPE !== $collection_data->post_type ) {
			throw new \Exception( __( 'Invalid collection', 'tutor-pro' ) );
		}

		// Post Meta.
		$collection_data->meta = get_post_meta( $collection_id );

		// Child posts (lessons, quizzes, etc).
		$collection_data->contents = self::get_contents( $collection_id, $keep_media_files );

		return $collection_data;
	}

	/**
	 * Get collection contents
	 *
	 * @since 3.7.0
	 *
	 * @param integer $collection_id Collection id.
	 * @param boolean $keep_media_files Whether to keep media or not.
	 *
	 * @return array
	 */
	public static function get_contents( int $collection_id, bool $keep_media_files = false ) {
		$where    = array(
			'post_parent' => $collection_id,
		);
		$search   = '';
		$per_page = PHP_INT_MAX;

		$contents = ( new ContentModel() )->get_contents( $where, $search, $per_page );

		$data = array();

		if ( is_object( $contents ) && property_exists( $contents, 'data' ) ) {
			$data = $contents->data;
		}

		foreach ( $data as $key => $post ) {
			$post->meta = get_post_meta( $post->ID );

			if ( $keep_media_files ) {
				if ( ContentModel::LESSON_POST_TYPE === $post->post_type ) {
					$post->attachment_links = CourseExporter::get_attachment_links_from_meta( get_post_meta( $post->ID ), '_tutor_attachments' );
					$post->thumbnail_url    = get_the_post_thumbnail_url( $post->ID, 'full' );
				} elseif ( ContentModel::ASSIGNMENT_POST_TYPE === $post->post_type ) {
					$post->attachment_links = CourseExporter::get_attachment_links_from_meta( get_post_meta( $post->ID ), '_tutor_assignment_attachments' );
				}
			}

			if ( ContentModel::QUESTION_POST_TYPE === $post->post_type ) {
				$question = ContentModel::get_question_by_content_id( $post->ID );

				$post->question = $question;
				$post->answers  = QuizModel::get_question_answers( $question->question_id );
			}
			$data[ $key ] = $post;
		}

		return $data;
	}

	/**
	 * Handle content bank's export ajax request
	 *
	 * @since 3.7.0
	 *
	 * @param array    $job_data Export job data.
	 * @param array    $export_content_ids Export content ids.
	 * @param Exporter $exporter Exporter tool instance.
	 *
	 * @return mixed
	 */
	public static function ajax_req_handler( array $job_data, array $export_content_ids, Exporter $exporter ) {
		$is_done     = false;
		$export_data = null;

		$completed_ids = array_merge( $job_data['completed_contents'][ $exporter::TYPE_CONTENT_BANK ]['success'], $job_data['completed_contents'][ $exporter::TYPE_CONTENT_BANK ]['failed'] );

		$remaining_ids = array_diff( $export_content_ids, $completed_ids );
		if ( ! empty( $remaining_ids ) ) {
			$is_failed     = false;
			$id_to_process = array_shift( $remaining_ids );

			try {
				$export_data = $exporter->add_collections( $id_to_process )->export();
			} catch ( \Throwable $th ) {
				$is_failed = true;
			} finally {
				if ( $is_failed ) {
					$job_data['completed_contents'][ $exporter::TYPE_CONTENT_BANK ]['failed'][] = $id_to_process;
				} else {
					$job_data['completed_contents'][ $exporter::TYPE_CONTENT_BANK ]['success'][] = $id_to_process;
				}
			}

			if ( is_null( $export_data ) ) {
				$export_data = $exporter->get_schema();
			}
		} else {
			$is_done = true;
		}

		return (object) array(
			'job_data'    => $job_data,
			'export_data' => $export_data,
			'is_done'     => $is_done,
		);
	}
}

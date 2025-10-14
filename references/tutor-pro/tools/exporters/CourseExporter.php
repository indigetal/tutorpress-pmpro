<?php
/**
 * Course Exporter
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/**
 * Handle a course exporting
 *
 * @since 3.6.0
 */
class CourseExporter {

	/**
	 * List of all sub-file types related to a course export.
	 *
	 * @since 3.8.1
	 *
	 * @var string[]
	 */
	private static array $course_sub_files = array(
		Exporter::COURSE,
		Exporter::ORDERS,
		Exporter::PROGRESS,
		Exporter::REVIEWS,
	);

	/**
	 * Export a course as array
	 *
	 * @since 3.6.0
	 *
	 * @since 3.8.1  Added `$keep_user_data` parameter.
	 *
	 * @throws \Exception Throws exception if course id is invalid.
	 *
	 * @param integer $course_id Course id.
	 * @param array   $content_types Content types to export.
	 *                like lesson post type, quiz post type etc.
	 *
	 * @param bool    $keep_media_files To keep media files.
	 *
	 * @return array Export the entire course as an array
	 */
	public static function export( int $course_id, array $content_types, $keep_media_files = false ) {
		global $wpdb;

		// Course Post.
		$course_data = get_post( $course_id );
		if ( ! is_a( $course_data, 'WP_Post' ) ) {
			throw new \Exception( __( 'Invalid post', 'tutor-pro' ) );
		}

		if ( tutor()->course_post_type !== $course_data->post_type ) {
			throw new \Exception( __( 'Invalid course', 'tutor-pro' ) );
		}

		// Thumbnail.
		$course_data->thumbnail_url = get_the_post_thumbnail_url( $course_id, 'full' );

		// Post Meta.
		$course_data->meta = get_post_meta( $course_id );

		// Taxonomies (categories, tags).
		$course_data->taxonomies = self::get_course_terms( $course_id );

		// Child posts (lessons, topics, quizzes).
		$course_data->contents = ! empty( $content_types ) ? self::get_child_posts( $course_id, $content_types, $keep_media_files ) : array();

		// Get all the linked child posts except topics.
		$course_data->child_posts = QueryHelper::get_all(
			$wpdb->posts,
			array(
				'post_parent' => $course_id,
				'post_type'   => array(
					'NOT IN',
					array( tutor()->topics_post_type, tutor()->enrollment_post_type ),
				),
			),
			'ID'
		);

		// Attachment links.
		$course_data->attachment_links = array();
		if ( $keep_media_files ) {
			$course_data->attachment_links = self::get_attachment_links_from_meta( $course_data->meta, '_tutor_attachments' );
		}

		return $course_data;
	}

	/**
	 * Get all the child posts of a course
	 *
	 * @since 3.6.0
	 *
	 * @param int   $course_id Course id.
	 * @param array $content_types Course content post types.
	 * @param bool  $keep_media_files Whether to keep media files.
	 *
	 * @return array
	 */
	public static function get_child_posts( int $course_id, array $content_types, $keep_media_files ): array {
		$response = array();

		// Get topics.
		$post_args = array(
			'nopaging'               => true,
			'posts_per_page'         => -1,
			'ignore_sticky_posts'    => true,
			'cache_results'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'no_found_rows'          => true,
			'order_by'               => 'none',
		);

		$topics = array();

		$topic_query = tutor_utils()->get_topics( $course_id, $post_args );
		if ( is_a( $topic_query, 'WP_Query' ) ) {
			$topics = $topic_query->get_posts();
		}

		if ( ! count( $topics ) ) {
			return $response;
		}

		foreach ( $topics as $topic ) {
			$child_post_types = $content_types;

			$post_args['post_type'] = $child_post_types;

			$children_query = tutor_utils()->get_course_contents_by_topic( $topic->ID, -1, $post_args );
			if ( is_a( $children_query, 'WP_Query' ) ) {
				$children = $children_query->get_posts();

				foreach ( $children as $child ) {
					// Add meta.
					$child->meta          = get_post_meta( $child->ID );
					$child->child_posts   = get_posts( array( 'post_parent' => $course_id ) );
					$child->thumbnail_url = get_the_post_thumbnail_url( $child->ID, 'full' );

					if ( $keep_media_files ) {
						if ( tutor()->assignment_post_type === $child->post_type ) {
							$child->attachment_links = self::get_attachment_links_from_meta( $child->meta, '_tutor_assignment_attachments' );
						} else {
							$child->attachment_links = self::get_attachment_links_from_meta( $child->meta, '_tutor_attachments' );
						}
					}

					if ( tutor()->quiz_post_type === $child->post_type ) {
						$child->question_answer = self::get_question_answer_by_quiz_id( $child->ID );
					}
				}

				// Add children.
				$topic->children = $children;
			}
		}

		$response = $topics;

		return $response;
	}

	/**
	 * Get question answers of a quiz
	 *
	 * @since 3.6.0
	 *
	 * @param integer $quiz_id Quiz id.
	 *
	 * @return array
	 */
	public static function get_question_answer_by_quiz_id( int $quiz_id ): array {
		global $wpdb;

		$query = "SELECT
                    q.*,
                    a.*
                FROM {$wpdb->prefix}tutor_quiz_questions q
                LEFT JOIN {$wpdb->prefix}tutor_quiz_question_answers a
                    ON q.question_id = a.belongs_question_id
                WHERE q.quiz_id = %d
                ORDER BY q.question_order ASC, a.answer_order ASC";

		$results = $wpdb->get_results( $wpdb->prepare( $query, $quiz_id ) ); //phpcs:ignore

		$organized = array();
		foreach ( $results as $row ) {
			$question_id = $row->question_id;

			// Initialize question if not exists.
			if ( ! isset( $organized[ $question_id ] ) ) {
				$organized[ $question_id ] = array(
					'question' => array(
						'question_id'          => $row->question_id,
						'quiz_id'              => $row->quiz_id,
						'question_title'       => $row->question_title,
						'question_description' => $row->question_description,
						'answer_explanation'   => $row->answer_explanation,
						'question_type'        => $row->question_type,
						'question_mark'        => $row->question_mark,
						'question_settings'    => maybe_unserialize( $row->question_settings ),
						'question_order'       => $row->question_order,
					),
					'answers'  => array(),
				);
			}

			// Add answer.
			$organized[ $question_id ]['answers'][] = array(
				'answer_id'             => $row->answer_id,
				'belongs_question_id'   => $row->belongs_question_id,
				'belongs_question_type' => $row->belongs_question_type,
				'answer_title'          => $row->answer_title,
				'is_correct'            => $row->is_correct,
				'image_id'              => $row->image_id,
				'image_url'             => $row->image_id ? wp_get_attachment_url( $row->image_id ) : '',
				'answer_two_gap_match'  => $row->answer_two_gap_match,
				'answer_view_format'    => $row->answer_view_format,
				'answer_settings'       => maybe_unserialize( $row->answer_settings ),
				'answer_order'          => $row->answer_order,
			);
		}

		// Reset array keys and return.
		return array_values( $organized );
	}

	/**
	 * Get course terms
	 *
	 * Course categories, tags
	 *
	 * @since 3.6.0
	 *
	 * @param integer $course_id Course id.
	 *
	 * @return object {categories, tags}
	 */
	public static function get_course_terms( int $course_id ): object {
		$term_args = array(
			'order_by'   => 'none',
			'hide_empty' => false,
			'object_ids' => $course_id,
			'fields'     => 'all',
		);

		$response = (object) array(
			'categories' => tutor_utils()->get_course_categories( 0, $term_args, true ),
			'tags'       => tutor_utils()->get_course_tags( $term_args ),
		);

		return $response;
	}

	/**
	 * Get attachment links from meta array
	 *
	 * @since 3.6.0
	 *
	 * @param array  $meta_data Array of meta data.
	 * @param string $meta_key Meta key that holds the attachment ids.
	 *
	 * @return array
	 */
	public static function get_attachment_links_from_meta( $meta_data, $meta_key ): array {
		$attachment_links = array();
		if ( is_array( $meta_data ) && count( $meta_data ) ) {
			$attachment_ids = isset( $meta_data[ $meta_key ] ) && is_array( $meta_data[ $meta_key ] ) && ! empty( $meta_data[ $meta_key ] ) ? $meta_data[ $meta_key ][0] : array();
			foreach ( maybe_unserialize( $attachment_ids ) as $attachment_id ) {
				$url = wp_get_attachment_url( $attachment_id );
				if ( $url ) {
					$attachment_links[] = $url;
				}
			}
		}

		return $attachment_links;
	}

	/**
	 * Get the list of course sub-files to export.
	 *
	 * @since 3.8.1
	 *
	 * @param bool $keep_user_data Whether to include all sub-files.
	 * @return string[] List of sub-file identifiers.
	 */
	public function get_sub_files( $keep_user_data ) {

		if ( ! $keep_user_data ) {
			return array( Exporter::COURSE );
		}

		if ( tutor_utils()->is_addon_enabled( 'subscription' ) ) {

			// Need to add plans and subscription before order.
			self::$course_sub_files = Helper::insert_items_before_target( Exporter::ORDERS, self::$course_sub_files, array( Exporter::PLANS, Exporter::SUBSCRIPTIONS ) );
		}

		if ( tutor_utils()->is_addon_enabled( TUTOR_ENROLLMENTS()->basename ) ) {
			array_push( self::$course_sub_files, Exporter::ENROLLMENTS );
		}

		return self::$course_sub_files;
	}

	/**
	 * Check if a given file is considered a course sub-file.
	 *
	 * @since 3.8.1
	 *
	 * @param string $content_type The type of content (e.g., course, bundle).
	 * @param string $sub_file     The sub-file identifier to check.
	 * @return bool True if the file is a course sub-file, false otherwise.
	 */
	public static function is_sub_file( string $content_type, string $sub_file ) {

		return tutor()->course_post_type === $content_type && in_array( $sub_file, self::$course_sub_files );
	}

	/**
	 * Check if the given content key represents a course.
	 *
	 * @since 3.8.1
	 *
	 * @param string $key Content key.
	 * @return bool True if the key matches CourseExporter::COURSE, false otherwise.
	 */
	public static function is_course( string $key ): bool {
		return Exporter::COURSE === $key;
	}
}

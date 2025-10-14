<?php
/**
 * Review Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;
use TUTOR\Input;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ReviewImporter class
 */
class ReviewImporter {

	/**
	 * Insert tutor course reviews.
	 *
	 * @since 3.8.1
	 *
	 * @param array $review_data the course review data.
	 *
	 * @return int|\WP_Error
	 */
	public function insert_reviews( array $review_data ) {

		$content_map = ContentMapHandler::get_content_map();
		$courses_map = $content_map['courses'] ?? null;
		$user_map    = $content_map['users'] ?? null;

		unset( $content_map );

		$reviews     = $review_data['review'] ?? null;
		$review_meta = $review_data['review_meta'] ?? null;

		unset( $review_data['review'] );
		unset( $review_data['review_meta'] );

		$course_id = $reviews['comment_post_ID'] ?? 0;
		$user_id   = $reviews['user_id'] ?? 0;

		$course_map = $courses_map[ $course_id ] ?? null;

		if ( $course_map ) {
			$course_id = $course_map['course_id'] ?? $course_id;
			unset( $courses_map );
		}

		if ( isset( $user_map[ $user_id ] ) ) {
			$user_id = $user_map[ $user_id ];
			unset( $user_map );
		}

		unset( $reviews['comment_ID'] );

		$reviews['comment_post_ID'] = $course_id;
		$reviews['user_id']         = $user_id;

		$review_id = wp_insert_comment( $reviews );

		if ( ! $review_id ) {
			return new \WP_Error( 'review_insert_failed', __( 'Error inserting course review', 'tutor-pro' ) );
		}

		if ( isset( $review_meta['tutor_rating'] ) ) {
			update_comment_meta( $review_id, 'tutor_rating', $review_meta['tutor_rating'][0] ?? 0 );
		}

		unset( $reviews );
		unset( $review_meta );

		return $review_id;
	}
}

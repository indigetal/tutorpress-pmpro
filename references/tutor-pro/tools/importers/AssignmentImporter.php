<?php
/**
 * Assignment Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;
use TUTOR\Input;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assignment Importer Class.
 */
class AssignmentImporter {

	/**
	 * Sets the assignment post meta.
	 *
	 * @since 3.6.0
	 *
	 * @param array $assignment_meta the assignment meta data to set.
	 * @param array $post_id the post id.
	 * @param array $attachment_ids the array of attachment ids.
	 *
	 * @return void
	 */
	public function set_assignment_meta( $assignment_meta, $post_id, $attachment_ids ) {
		$data = array();

		$assignment_meta = array_map( fn( $val ) => $val[0], $assignment_meta );

		if ( isset( $assignment_meta['assignment_option'] ) ) {
			update_post_meta( $post_id, 'assignment_option', maybe_serialize( $assignment_meta['assignment_option'] ) );
		}

		if ( isset( $assignment_meta['_tutor_assignment_total_mark'] ) && isset( $assignment_meta['_tutor_assignment_pass_mark'] ) ) {
			$total_mark = Input::sanitize( $assignment_meta['_tutor_assignment_total_mark'], 0, INPUT::TYPE_NUMERIC );
			$pass_mark  = Input::sanitize( $assignment_meta['_tutor_assignment_pass_mark'], 0, INPUT::TYPE_NUMERIC );

			update_post_meta( $post_id, '_tutor_assignment_total_mark', $total_mark );
			update_post_meta( $post_id, '_tutor_assignment_pass_mark', $pass_mark );

		}

		if ( isset( $assignment_meta['_tutor_course_id_for_assignments'] ) ) {
			$topic_id  = wp_get_post_parent_id( $post_id );
			$course_id = wp_get_post_parent_id( $topic_id );

			update_post_meta( $post_id, '_tutor_course_id_for_assignments', $course_id );
		}

		if ( $attachment_ids ) {
			update_post_meta( $post_id, '_tutor_assignment_attachments', $attachment_ids );
		}

		if ( isset( $assignment_meta['_content_drip_settings'] ) ) {
			$_POST['content_drip_settings'] = $assignment_meta['_content_drip_settings'];
		}

		do_action( 'tutor_assignment_created', $post_id );
	}

	/**
	 * Insert assignment post.
	 *
	 * @since 3.8.1
	 *
	 * @param array $content the assignment post content.
	 * @param bool  $keep_media_files whether to keep media files.
	 * @param int   $parent_id the parent course id.
	 *
	 * @return int|\WP_Error
	 */
	public function insert_assignment( $content, $keep_media_files = false, $parent_id = 0 ) {
		global $wpdb;

		$previous_assignment_id = $content['ID'] ?? 0;
		$meta                   = $content['meta'] ?? null;
		$attachment_links       = $content['attachment_links'] ?? null;
		$attachment_ids         = array();

		$courses_map = ContentMapHandler::get_content_map()['courses'] ?? null;

		$content = Helper::unset_post_data( $content );

		if ( $attachment_links && $keep_media_files ) {
			$attachment_ids = Helper::get_attachment_ids_from_urls( $attachment_links );
		}

		$assignment_id = wp_insert_post( $content, true, true );

		if ( is_wp_error( $assignment_id ) ) {
			return $assignment_id;
		}

		if ( $courses_map && $parent_id ) {
			if ( isset( $courses_map[ $parent_id ] ) ) {
				$map = $courses_map[ $parent_id ];
				$map[ tutor()->assignment_post_type ][ $previous_assignment_id ] = $assignment_id;
				$courses_map[ $parent_id ]                                       = $map;
			}
		}

		if ( $meta ) {
			$meta = Helper::prepare_meta( $assignment_id, $meta, $keep_media_files );
		}

		if ( $attachment_ids ) {
			$meta[] = array(
				'post_id'    => $assignment_id,
				'meta_key'   => '_tutor_assignment_attachments',
				'meta_value' => maybe_serialize( $attachment_ids ),
			);
		}

		try {
			QueryHelper::insert_multiple_rows( $wpdb->postmeta, $meta, false, false );
		} catch ( \Throwable $th ) {
			ErrorHandler::set_error( $content['post_type'], 'Error saving meta value for assignment : ' . $content['post_title'] );
		}

		ContentMapHandler::update_content_map( 'courses', $courses_map );

		unset( $meta );
		unset( $courses_map );

		return $assignment_id;
	}
}

<?php
/**
 * Lesson Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;
use Tutor\Lesson;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lesson Importer class.
 */
class LessonImporter {

	/**
	 * Prepare lesson post meta.
	 *
	 * @since 3.8.1
	 *
	 * @param array $lesson_metas the lesson post meta values.
	 * @param bool  $keep_media_files whether to keep media files.
	 *
	 * @return void
	 */
	public function prepare_lesson_meta( $lesson_metas, $keep_media_files ) {
		$lesson_metas = array_map( fn( $val ) => $val[0], $lesson_metas );

		Helper::reset_post_data( 'video' );

		if ( isset( $lesson_metas['_video'] ) ) {
			$video_data = $lesson_metas['_video'];

			if ( isset( $video_data['source'] ) && 'html5' === $video_data['source'] && $keep_media_files ) {
				$video_url   = $video_data['source_html5'];
				$upload_data = Helper::upload_file_by_url( $video_url );
				if ( ! is_wp_error( $upload_data ) ) {
					$video_data['source_video_id'] = $upload_data['id'];
					$video_data['source_html5']    = $upload_data['url'];
				}
			}

			$_POST['video'] = $video_data;
		}

		Helper::reset_post_data( 'content_drip_settings' );

		if ( isset( $lesson_metas['_content_drip_settings'] ) ) {
			$_POST['content_drip_settings'] = $lesson_metas['_content_drip_settings'];
		}

		Helper::reset_post_data( '_is_preview' );
		// Handle lesson post meta.
		if ( isset( $lesson_metas['_is_preview'] ) ) {
			$_POST['_is_preview'] = $lesson_metas['_is_preview'];
		}
	}

	/**
	 * Insert lesson post.
	 *
	 * @since 3.8.1
	 *
	 * @param array $content the lesson post content.
	 * @param bool  $keep_media_files whether to keep media files.
	 * @param int   $parent_id the parent course id.
	 *
	 * @return int|\WP_Error
	 */
	public function insert_lesson( $content, $keep_media_files = false, $parent_id = 0 ) {
		global $wpdb;

		$previous_lesson_id = $content['ID'] ?? 0;
		$meta               = $content['meta'] ?? null;
		$thumbnail_url      = $content['thumbnail_url'] ?? null;
		$attachment_links   = $content['attachment_links'] ?? null;
		$attachment_ids     = array();

		$courses_map = ContentMapHandler::get_content_map()['courses'] ?? null;

		$content = Helper::unset_post_data( $content );

		if ( $attachment_links && $keep_media_files ) {
			$attachment_ids = Helper::get_attachment_ids_from_urls( $attachment_links );
		}

		$lesson_id = wp_insert_post( $content, true, true );

		if ( is_wp_error( $lesson_id ) ) {
			return $lesson_id;
		}

		if ( $courses_map && $parent_id ) {
			if ( isset( $courses_map[ $parent_id ] ) ) {
				$map = $courses_map[ $parent_id ];
				$map[ tutor()->lesson_post_type ][ $previous_lesson_id ] = $lesson_id;
				$courses_map[ $parent_id ]                               = $map;
			}
		}

		if ( $meta ) {
			$meta = Helper::prepare_meta( $lesson_id, $meta, $keep_media_files );
		}

		if ( $attachment_ids ) {
			$meta[] = array(
				'post_id'    => $lesson_id,
				'meta_key'   => '_tutor_attachments',
				'meta_value' => maybe_serialize( $attachment_ids ),
			);
		}

		try {
			QueryHelper::insert_multiple_rows( $wpdb->postmeta, $meta, false, false );
		} catch ( \Throwable $th ) {
			ErrorHandler::set_error( $content['post_type'], 'Error saving meta value for lesson : ' . $content['post_title'] );
		}

		if ( $thumbnail_url && $keep_media_files ) {
			Helper::save_post_thumbnail( $thumbnail_url, $lesson_id );
		}

		ContentMapHandler::update_content_map( 'courses', $courses_map );

		unset( $meta );
		unset( $courses_map );

		return $lesson_id;
	}
}

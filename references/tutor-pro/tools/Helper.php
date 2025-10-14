<?php
/**
 * Helper
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.7.1
 */

namespace TutorPro\Tools;

use TUTOR\Course;

/**
 * Class Helper
 *
 * @since 3.7.1
 */
class Helper {

	const EXPORT_ZIP_DOWNLOAD_ACTION = 'tutor_pro_export_download';

	const TUTOR_COURSE_REVIEW_TYPE       = 'reviews';
	const TUTOR_COURSE_ENROLLMENTS_TYPE  = 'enrollments';
	const TUTOR_COURSE_PLANS_TYPE        = 'plans';
	const TUTOR_COURSE_ORDERS_TYPE       = 'orders';
	const TUTOR_COURSE_PROGRESS_TYPE     = 'progress';
	const TUTOR_COURSE_SUBSCRIPTION_TYPE = 'subscriptions';


	/**
	 * Recursively unserialize all serialized values in an array
	 *
	 * @since 3.6.0
	 *
	 * @param mixed $data The input data (array or string).
	 *
	 * @return mixed The processed data with unserialized values
	 */
	public static function deep_maybe_unserialize( $data ) {
		// Handle arrays recursively.
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::deep_maybe_unserialize( $value );
			}
			return $data;
		}

		// Handle objects recursively (convert to array first).
		if ( is_object( $data ) ) {
			$data = (array) $data;
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::deep_maybe_unserialize( $value );
			}
			return (object) $data;
		}

		// Handle strings (check if serialized).
		if ( is_string( $data ) ) {
			// Skip if empty or doesn't look serialized.
			if ( empty( $data ) || ! preg_match( '/^[aOs]:[\d]+:/', $data ) ) {
				return $data;
			}

			$unserialized = maybe_unserialize( $data );
			if ( $data !== $unserialized ) {
				return self::deep_maybe_unserialize( $unserialized );
			}
		}

		return $data;
	}


	/**
	 * Get attachment ids from attachment url.
	 *
	 * @since 3.8.0
	 *
	 * @param array $attachment_urls the attachment url list.
	 *
	 * @return array
	 */
	public static function get_attachment_ids_from_urls( array $attachment_urls ) {
		$attachment_ids = array();
		foreach ( $attachment_urls as $url ) {
			if ( $url ) {
				$upload_data = self::upload_file_by_url( $url );

				if ( is_wp_error( $upload_data ) ) {
					continue;
				}
				$attachment_ids[] = $upload_data['id'];
			}
		}

		return $attachment_ids;
	}


	/**
	 * Handle file upload from given url.
	 *
	 * @since 3.6.0
	 *
	 * @param string $file_url the file url.
	 *
	 * @return array|\WP_Error
	 */
	public static function upload_file_by_url( $file_url ) {

		if ( empty( $file_url ) ) {
			return new \WP_Error( 'invalid_file_url', 'Invalid file URL provided.' );
		}

		$upload_dir = wp_upload_dir()['basedir'];

		$parse_url = parse_url( $file_url );

		$base_url = $parse_url['scheme'] . '://' . $parse_url['host'];

		if ( isset( $parse_url['port'] ) ) {
			$base_url .= ':' . $parse_url['port'];
		}

		// Add sub path.
		$base_url .= strstr( $parse_url['path'], 'wp-content', true );

		$file_name       = basename( $file_url );
		$source_dir_url  = str_replace( $file_name, '', $file_url );
		$source_dir_part = str_replace( $base_url . 'wp-content/uploads/', '', $source_dir_url );

		$file_path = trailingslashit( $upload_dir ) . trailingslashit( $source_dir_part ) . $file_name;

		$upload_dir = trailingslashit( $upload_dir ) . trailingslashit( $source_dir_part );

		try {
			if ( ! file_exists( $file_path ) ) {

				if ( ! file_exists( $upload_dir ) ) {
					mkdir( $upload_dir, 0777, true );
				}

				$file_data = file_get_contents( $file_url );
				if ( false !== $file_data ) {
					// Save the image to the uploads directory.
					file_put_contents( $file_path, $file_data );
				} else {
					return new \WP_Error( 'download_failed', 'Failed to download content ' . $file_url );
				}
			}
		} catch ( \Throwable $th ) {
			return new \WP_Error( 'download_failed', 'Failed to download content ' . $file_url, $th->getMessage() );
		}

		$file_type = wp_check_filetype( $file_name );

		$file_url = str_replace( $source_dir_url, site_url( '/wp-content/uploads/' . $source_dir_part ), $file_url );

		$attachment_args = array(
			'guid'           => $file_url,
			'post_mime_type' => $file_type['type'],
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $file_name ),
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment_args, $file_path, 0, true );

		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		if ( wp_attachment_is_image( $attach_id ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attach_data = wp_generate_attachment_metadata( $attach_id, $file_path );
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}

		return array(
			'url'         => $file_url,
			'id'          => $attach_id,
			'name'        => $file_name,
			'upload_path' => trailingslashit( $source_dir_part ) . $file_name,
			'type'        => $file_type,
		);
	}


	/**
	 * Upload and save post thumbnail meta.
	 *
	 * @since 3.8.0
	 *
	 * @param string  $thumbnail_url the thumbnail urls array.
	 * @param integer $post_id the post id to save meta.
	 *
	 * @return bool|\WP_Error
	 */
	public static function save_post_thumbnail( string $thumbnail_url, int $post_id ) {

		$upload_data = self::upload_file_by_url( $thumbnail_url );
		$response    = true;

		if ( ! is_wp_error( $upload_data ) ) {
			$response = set_post_thumbnail( $post_id, $upload_data['id'] );
		}

		return $response;
	}

	/**
	 * Removes not required post data from array.
	 *
	 * @since 3.8.0
	 *
	 * @param array $post_data the post data array to modify.
	 *
	 * @return array
	 */
	public static function unset_post_data( $post_data ) {
		$keys         = array( 'ID', 'filter', 'meta', 'thumbnail_url', 'child_posts', 'attachment_links', 'question', 'answers', 'courses' );
		$updated_data = $post_data;

		foreach ( $keys as $key ) {
			if ( isset( $updated_data[ $key ] ) ) {
				unset( $updated_data[ $key ] );
			}
		}

		return $updated_data;
	}


	/**
	 * Reset global $_POST and $_REQUEST data.
	 *
	 * @since 3.6.0
	 *
	 * @param string $key the key to look for the data.
	 *
	 * @return void
	 */
	public static function reset_post_data( string $key ) {
		if ( isset( $_POST[ $key ] ) ) { //phpcs:ignore
			unset( $_POST[ $key ] );
		}

		if ( isset( $_REQUEST[ $key ] ) ) {
			unset( $_REQUEST[ $key ] );
		}
	}

	/**
	 * Allocates more memory for memory intensive tasks.
	 *
	 * @since 3.8.0
	 *
	 * @return bool
	 */
	public static function increase_memory_limit() {
		add_filter(
			'admin_memory_limit',
			function ( $limit ) {
				return '512M';
			}
		);

		// imports takes a lot of memory.
		$result = wp_raise_memory_limit( 'admin' );

		return $result;
	}


	/**
	 * Prepare post meta
	 *
	 * @since 3.8.0
	 *
	 * @param int   $post_id Post id.
	 * @param array $post_meta Array of post meta key.
	 * @param bool  $keep_media_files whether to keep media files.
	 *
	 * @return array
	 */
	public static function prepare_meta( $post_id, $post_meta, $keep_media_files ): array {
		$meta = array();

		if ( ! tutor_utils()->count( $post_meta ) ) {
			return $meta;
		}

		$post_meta = array_map( fn( $val ) => $val[0], $post_meta );

		$map = ContentMapHandler::get_content_map()['courses'] ?? null;

		unset( $post_meta['_thumbnail_id'] );
		unset( $post_meta['_tutor_attachments'] );
		unset( $post_meta['_tutor_assignment_attachments'] );
		unset( $post_meta['_tutorstarter_schema'] );

		foreach ( $post_meta as $key => $value ) {

			if ( '_video' === $key ) {

				if ( isset( $value['source'] ) && 'html5' === $value['source'] && $keep_media_files ) {
					$video_url   = $value['source_html5'];
					$upload_data = self::upload_file_by_url( $video_url );
					if ( ! is_wp_error( $upload_data ) ) {
						$value['source_video_id'] = $upload_data['id'];
						$value['source_html5']    = $upload_data['url'];
					}
				}
			}

			if ( '_tutor_course_id_for_assignments' === $key && isset( $map[ $value ] ) ) {
				$course_id = $map[ $value ]['course_id'];
				$value     = $course_id;
			}

			$course_additional_meta = array(
				COURSE::COURSE_BENEFITS_META,
				COURSE::COURSE_REQUIREMENTS_META,
				COURSE::COURSE_TARGET_AUDIENCE_META,
				COURSE::COURSE_MATERIAL_INCLUDE_META,
			);

			if ( in_array( $key, $course_additional_meta, true ) ) {
				$value = wp_slash( $value );
			}

			$meta[] = array(
				'post_id'    => $post_id,
				'meta_key'   => $key,
				'meta_value' => maybe_serialize( $value ),
			);
		}

		unset( $map );

		return $meta;
	}

	/**
	 * Generates a secure download URL for a given ZIP file.
	 *
	 * @since 3.8.1
	 *
	 * @param string $file_name The name of the ZIP file to download.
	 * @return string The fully qualified URL for downloading the ZIP file.
	 */
	public static function get_download_url( $file_name ) {

		$download_url = add_query_arg(
			array(
				'action'       => self::EXPORT_ZIP_DOWNLOAD_ACTION,
				'file'         => $file_name,
				tutor()->nonce => wp_create_nonce( tutor()->nonce_action ),
			),
			admin_url( 'admin-post.php' )
		);

		return esc_url_raw( $download_url );
	}

	/**
	 * Forces the download of a ZIP file to the browser.
	 *
	 * @since 3.8.1
	 *
	 * @param string $file_name The full path to the ZIP file on the server.
	 * @return void
	 */
	public static function download_zip( $file_name ) {

		// download the file.
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename=' . basename( $file_name ) );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $file_name ) );
		readfile( $file_name );

		wp_delete_file( $file_name );
		exit;
	}

	/**
	 * Removes files and directories recursively.
	 *
	 * @since 3.8.0
	 *
	 * @param string $dir the directory.
	 *
	 * @return bool
	 */
	public static function remove_files_directory_recursively( $dir ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		$file_system_direct = new \WP_Filesystem_Direct( false );
		$result             = $file_system_direct->delete( $dir, true );
		return $result;
	}

	/**
	 * Insert one or more items into an array before a specified target value.
	 *
	 * @since 3.8.1
	 *
	 * @param string $target           The value to insert items before.
	 * @param array  $original_array   The array to modify.
	 * @param array  $inserted_items   The items to insert.
	 * @return array The modified array with the new items inserted.
	 */
	public static function insert_items_before_target( $target, $original_array, $inserted_items ) {

		$position = array_search( $target, $original_array, true );

		array_splice( $original_array, $position, 0, $inserted_items );

		return $original_array;
	}
}

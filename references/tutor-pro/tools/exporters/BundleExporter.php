<?php
/**
 * Bundle Exporter
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle a bundle exporting
 *
 * @since 3.6.0
 */
class BundleExporter {

	/**
	 * List of all sub-file types related to a course-bundle export.
	 *
	 * @since 3.8.1
	 *
	 * @var string[]
	 */
	private static array $bundle_sub_files = array(
		Exporter::BUNDLE,
		Exporter::ORDERS,
		Exporter::COURSES,
	);

	/**
	 * Export a bundle as array
	 *
	 * @since 3.6.0
	 *
	 * @throws \Exception Throws exception if course id is invalid.
	 *
	 * @param integer $bundle_id Bundle id.
	 *
	 * @return array Export the entire bundle as an array
	 */
	public static function export( int $bundle_id ) {

		// Bundle Post.
		$bundle_data = get_post( $bundle_id );
		if ( ! is_a( $bundle_data, 'WP_Post' ) ) {
			throw new \Exception( esc_html__( 'Invalid bundle id detected', 'tutor-pro' ) );
		}

		if ( tutor()->bundle_post_type !== $bundle_data->post_type ) {
			throw new \Exception( esc_html__( 'Invalid bundle', 'tutor-pro' ) );
		}

		// Thumbnail.
		$bundle_data->thumbnail_url = get_the_post_thumbnail_url( $bundle_id, 'full' );

		// Post Meta.
		$bundle_data->meta = get_post_meta( $bundle_id );

		return $bundle_data;
	}

	/**
	 * Get the list of bundle sub-files to export.
	 *
	 * @since 3.8.1
	 *
	 * @param bool $keep_user_data Whether to include all sub-files.
	 * @return string[] List of sub-file identifiers.
	 */
	public static function get_sub_files( $keep_user_data ) {

		if ( ! $keep_user_data ) {
			return array( Exporter::BUNDLE, Exporter::COURSES );
		}

		if ( tutor_utils()->is_addon_enabled( 'subscription' ) ) {

			// Need to add plans and subscription before order.

			self::$bundle_sub_files = Helper::insert_items_before_target( Exporter::ORDERS, self::$bundle_sub_files, array( Exporter::PLANS, Exporter::SUBSCRIPTIONS ) );
		}

		if ( tutor_utils()->is_addon_enabled( TUTOR_ENROLLMENTS()->basename ) ) {

			// Need to add enrollments before Courses.
			self::$bundle_sub_files = Helper::insert_items_before_target( Exporter::COURSES, self::$bundle_sub_files, array( Exporter::ENROLLMENTS ) );
		}

		return self::$bundle_sub_files;
	}

	/**
	 * Check if a given file is considered a bundle sub-file.
	 *
	 * @since 3.8.1
	 *
	 * @param string $content_type The type of content (e.g., course, bundle).
	 * @param string $sub_file     The sub-file identifier to check.
	 * @return bool True if the file is a course sub-file, false otherwise.
	 */
	public static function is_sub_file( string $content_type, string $sub_file ) {

		return tutor()->bundle_post_type === $content_type && in_array( $sub_file, self::$bundle_sub_files );
	}

	/**
	 * Get the list of exportable content type keys, excluding attachments.
	 *
	 * @since 3.8.1
	 *
	 * @return string[] Array of content type keys that can be exported.
	 */
	public static function get_content_types() {

		$content_types = array();
		$exporter      = tutor_pro_tools()->exporter;

		foreach ( $exporter->get_exportable_sub_contents() as $sub_content ) {
			$content_types[ $sub_content['key'] ] = $sub_content['label'];
		}
		unset( $content_types[ $exporter::TYPE_ATTACHMENTS ] );

		return array_keys( $content_types );
	}

	/**
	 * Check if the given content key represents a bundle.
	 *
	 * @since 3.8.1
	 *
	 * @param string $key Content key.
	 * @return bool True if the key matches BundleExporter::BUNDLE, false otherwise.
	 */
	public static function is_bundle( string $key ): bool {
		return Exporter::BUNDLE === $key;
	}
}

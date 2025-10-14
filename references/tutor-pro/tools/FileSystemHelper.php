<?php
/**
 * TutorPro Exporter
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use Exception;
use ZipArchive;
use FilesystemIterator;
use WP_Filesystem_Base;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * FileSystemHelper
 *
 * @since 3.8.1
 *
 * @package TutorPro
 */
final class FileSystemHelper {

	/**
	 * Creates a zip archive from a source directory.
	 *
	 * @since 3.8.1
	 *
	 * @param string $source_dir The absolute path to the source directory.
	 * @param string $zip_path   The absolute path where the zip file will be created.
	 *
	 * @return string The path to the created zip file.
	 *
	 * @throws Exception If the zip cannot be created, a file cannot be added,
	 *                   or the directory structure is invalid.
	 */
	public static function zip( string $source_dir, string $zip_path ): string {

		$source_dir = rtrim( self::is_real_path( $source_dir ), DIRECTORY_SEPARATOR );
		self::is_dir( $source_dir );
		self::ensure_parent_writable( $zip_path );

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path, ZipArchive::CREATE ) ) {
			throw new Exception(
				sprintf(
				/* translators: %s: file path */
					esc_html__( 'Unable to open zip file for writing: %s', 'tutor-pro' ),
					esc_html( $zip_path )
				)
			);

		}

		$iteration = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iteration as $file_info ) {

			$abs_path   = $file_info->getPathname();
			$local_name = ltrim( str_replace( $source_dir, '', $abs_path ), DIRECTORY_SEPARATOR );
			$local_name = str_replace( DIRECTORY_SEPARATOR, '/', $local_name );

			if ( $file_info->isDir() ) {

				// Add an empty dir entry to.
				$zip->addEmptyDir( $local_name );
			} elseif ( ! $zip->addFile( $abs_path, $local_name ) ) {

				/* translators: %s: file path */
				throw new Exception( sprintf( esc_html__( 'Failed adding file to zip: %s', 'tutor-pro' ), esc_html( $abs_path ) ) );
			}
		}

		if ( ! $zip->close() ) {

			/* translators: %s Zip Path */
			throw new Exception( sprintf( esc_html__( 'Failed to finalize zip: %s', 'tutor-pro' ), esc_html( $zip_path ) ) );
		}

		return $zip_path;
	}


	/**
	 * Validates that a given path exists and returns the real path.
	 *
	 * @since 3.8.1
	 *
	 * @param string $path The path to validate.
	 *
	 * @return string The canonicalized absolute pathname.
	 *
	 * @throws Exception If the path does not exist.
	 */
	private static function is_real_path( string $path ): string {

		$real_path = realpath( $path );

		if ( false === $real_path ) {
			/* translators: %s Path */
			throw new Exception( sprintf( esc_html__( 'Path does not exist: %s', 'tutor-pro' ), esc_html( $path ) ) );
		}
		return $real_path;
	}

	/**
	 * Checks if a given path is a directory.
	 *
	 * @since 3.8.1
	 *
	 * @param string $dir Directory path to check.
	 *
	 * @return void
	 *
	 * @throws Exception If the path is not a directory.
	 */
	private static function is_dir( string $dir ): void {

		if ( ! is_dir( $dir ) ) {
			/* translators: %s Directory Path */
			throw new Exception( sprintf( esc_html__( '"Not a directory: %s', 'tutor-pro' ), esc_html( $dir ) ) );
		}
	}

	/**
	 * Ensures the parent directory of a file path exists and is writable.
	 *
	 * @since 3.8.1
	 *
	 * @param string $target_file Absolute path to the target file.
	 *
	 * @return void
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem instance.
	 *
	 * @throws Exception If the parent directory cannot be created or is not writable.
	 */
	private static function ensure_parent_writable( string $target_file ): void {

		global $wp_filesystem;
		WP_Filesystem();

		$parent = dirname( $target_file );

		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			/* translators: %s Directory Path */
			throw new Exception( sprintf( esc_html__( 'Failed to create directory: %s', 'tutor-pro' ), esc_html( $parent ) ) );
		}

		if ( ! $wp_filesystem->is_writable( $parent ) ) {
			/* translators: %s target file */
			throw new Exception( sprintf( esc_html__( 'Directory not writable: %s', 'tutor-pro' ), esc_html( $parent ) ) );
		}
	}

	/**
	 * Deletes a directory and all its contents recursively.
	 *
	 * @since 3.8.1
	 *
	 * @param string $dir Absolute path to the directory to delete.
	 *
	 * @return void
	 *
	 * @global WP_Filesystem_Base $wp_filesystem WordPress filesystem instance.
	 */
	public static function delete_directory( $dir ) {

		global $wp_filesystem;

		// Create an iterator to recursively traverse all files and directories in $dir.
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );

		foreach ( $iterator as $item ) {

			if ( $item->isDir() ) {
				$wp_filesystem->rmdir( $item->getRealPath() );
			}

			if ( $item->isFile() ) {
				$wp_filesystem->delete( $item->getRealPath() );
			}
		}

		$wp_filesystem->rmdir( $dir );
	}
}

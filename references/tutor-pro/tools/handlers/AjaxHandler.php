<?php
/**
 * TutorPro Exporter
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

use TUTOR\User;
use TUTOR\Input;
use TUTOR\Course;
use Tutor\Options_V2;
use AllowDynamicProperties;
use Tutor\Models\UserModel;
use Tutor\Helpers\HttpHelper;
use Tutor\Models\CourseModel;
use Tutor\Helpers\QueryHelper;
use Tutor\Traits\JsonResponse;
use Tutor\Helpers\ValidationHelper;
use TutorPro\CourseBundle\Models\BundleModel;
use TutorPro\ContentBank\Models\CollectionModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handling export functionality.
 *
 * @since 3.6.0
 */
#[AllowDynamicProperties]
class AjaxHandler {

	use JsonResponse;

	/**
	 * Directory to keep the export file
	 *
	 * @since 3.6.0
	 *
	 * @var string file path
	 */
	private $upload_dir;

	/**
	 * Exporter instance
	 *
	 * @since 3.6.0
	 *
	 * @var Exporter
	 */
	private $exporter;

	/**
	 * User model instance
	 *
	 * @since 3.8.1
	 *
	 * @var UserModel
	 */
	private UserModel $user_model;

	/**
	 * The type of content being processed (e.g., 'course', 'bundle').
	 *
	 * @since 3.8.1
	 *
	 * @var string
	 */
	private $content_type;

	/**
	 * The actual content data associated with the current content type.
	 *
	 * @since 3.8.1
	 *
	 * @var array
	 */
	private $content_data;


	/**
	 * The ID of the parent item currently being processed.
	 *
	 * @since 3.8.1
	 *
	 * @var int
	 */
	private $current_processing_parent_id;

	/**
	 * Unique identifier for the current job or export/import process.
	 *
	 * @since 3.8.1
	 *
	 * @var int
	 */
	private $job_id;

	/**
	 * The ID of the current course within a bundle being processed.
	 *
	 * @since 3.8.1
	 *
	 * @var int
	 */
	private $current_bundle_course_id;

	const SETTINGS = 'settings';

	/**
	 * Instance of the course model for interacting with course data.
	 *
	 * @since 3.6.0
	 *
	 * @var CourseModel
	 */
	private $course_model;

	/**
	 * Register hooks
	 *
	 * @since 3.6.0
	 *
	 * @param Exporter    $exporter Export object.
	 * @param Importer    $importer Import object.
	 * @param CourseModel $course_model Course model object.
	 */
	public function __construct( Exporter $exporter, Importer $importer, CourseModel $course_model ) {

		$this->exporter              = $exporter;
		$this->course_model          = $course_model;
		$this->importer              = $importer;
		$this->user_model            = new UserModel();
		$this->course_exporter       = tutor_pro_tools()->course_exporter;
		$this->bundle_exporter       = tutor_pro_tools()->bundle_exporter;
		$this->subscription_exporter = tutor_pro_tools()->subscription_exporter;

		add_action( 'wp_ajax_tutor_pro_exportable_contents', array( $this, 'ajax_get_exportable_contents' ) );
		add_action( 'wp_ajax_tutor_pro_export', array( $this, 'ajax_export_handler' ) );
		add_action( 'wp_ajax_tutor_pro_export_import_history', array( $this, 'ajax_fetch_history' ) );
		add_action( 'wp_ajax_tutor_pro_import', array( $this, 'ajax_import_handler' ) );
		add_action( 'wp_ajax_tutor_pro_delete_export_import_history', array( $this, 'ajax_delete_export_import_history' ) );
		add_action( 'tutor_pro_export_completed', array( $this, 'update_settings_log' ), 10, 2 );
		add_action( 'admin_post_tutor_pro_export_download', array( $this, 'handle_export_zip_download' ) );

		// Check if the 'init' action has already been fired.
		did_action( 'init' ) ? $this->set_upload_dir() : add_action( 'init', array( $this, 'set_upload_dir' ) );
	}

	/**
	 * Set the upload directory for the tutor-pro plugin
	 *
	 * @since 3.8.1
	 *
	 * @return void
	 */
	public function set_upload_dir() {
		$this->upload_dir = wp_upload_dir()['basedir'] . '/tutor-pro/';
	}

	/**
	 * Ajax handler for exportable contents API
	 *
	 * @since 3.6.0
	 *
	 * @return void send wp_json response
	 */
	public function ajax_get_exportable_contents() {
		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability( 'edit_tutor_course' );
		$default_course_ids = array();

		if ( ! User::is_admin() && tutor_utils()->is_instructor( 0, true ) ) {
			$default_course_ids = array_column( CourseModel::get_courses_by_instructor( 0, Course::course_status_list(), 0, PHP_INT_MAX, false ), 'ID' );
		}

		$course_ids = Input::post( 'course_ids', $default_course_ids, Input::TYPE_ARRAY );
		$context    = Input::post( 'context', '' );

		$contents = $this->get_exportable_content_with_count( $course_ids, $context );

		$this->json_response( __( 'Exportable contents fetched successfully!', 'tutor-pro' ), $contents, HttpHelper::STATUS_OK );
	}

	/**
	 * Get exportable contents with count
	 *
	 * @since 3.6.0
	 *
	 * @since 3.7.1 Course ids param added
	 *
	 * @param array  $course_ids Array of course ids.
	 * @param string $context Export context, content-bank or empty.
	 *
	 * @return array
	 */
	public function get_exportable_content_with_count( array $course_ids = array(), string $context = '' ) {
		$args = array(
			'post_type'              => tutor()->course_post_type,
			'posts_per_page'         => -1,
			'no_found_rows'          => true,     // Skip pagination counting.
			'update_post_term_cache' => false,   // Skip taxonomy term caching.
			'update_post_meta_cache' => false,   // Skip post meta caching.
			'fields'                 => 'ids',
			'post_status'            => 'any',
		);

		if ( count( $course_ids ) ) {
			$args['post__in'] = $course_ids;
		}

		$contents = $this->exporter->get_exportable_content( $context );

		foreach ( $contents as $key => $content ) {
			switch ( $content['key'] ) {
				case tutor()->course_post_type:
					$query = $this->course_model::get_courses_by_args( $args );

					$post_count = is_a( $query, 'WP_Query' ) ? $query->post_count : 0;
					$ids        = is_a( $query, 'WP_Query' ) ? $query->posts : array();

					$label = $content['label'];

					$contents[ $key ]['label'] = $label;
					$contents[ $key ]['ids']   = $ids;
					$contents[ $key ]['count'] = $post_count;

					$sub_contents = $this->get_contextual_sub_content( $context, isset( $content['contents'] ) ? $content['contents'] : array() );

					foreach ( $sub_contents as $k => $sub_content ) {
						$sub_content_count    = $this->course_model::count_course_content( $sub_content['key'], $course_ids );
						$sub_content['count'] = $sub_content_count;

						$sub_contents[ $k ] = $sub_content;
					}

					$contents[ $key ]['contents'] = $sub_contents;
					break;
				case tutor()->bundle_post_type:
					unset( $args['post__in'] );
					$args['post_type'] = tutor()->bundle_post_type;

					$query = $this->course_model::get_courses_by_args( $args );

					$post_count = is_a( $query, 'WP_Query' ) ? $query->post_count : 0;
					$ids        = is_a( $query, 'WP_Query' ) ? $query->posts : array();

					$label = $content['label'];

					$contents[ $key ]['label'] = $label;
					$contents[ $key ]['ids']   = $ids;
					$contents[ $key ]['count'] = $post_count;
					break;
				case $this->exporter::TYPE_CONTENT_BANK:
					unset( $args['post__in'] );
					$args['post_type'] = CollectionModel::POST_TYPE;

					$query = $this->course_model::get_courses_by_args( $args );

					$post_count = is_a( $query, 'WP_Query' ) ? $query->post_count : 0;
					$ids        = is_a( $query, 'WP_Query' ) ? $query->posts : array();

					$label = $content['label'];

					$contents[ $key ]['label'] = $label;
					$contents[ $key ]['ids']   = $ids;
					$contents[ $key ]['count'] = $post_count;
					break;

				default:
					// code...
					break;
			}
		}

		return $contents;
	}

	/**
	 * Handle export
	 *
	 * @since 3.6.0
	 *
	 * @since 3.8.1 User Data Added
	 *
	 * @return void wp_json response
	 */
	public function ajax_export_handler() {
		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability();

		$job_id           = Input::post( 'job_id', 0, Input::TYPE_INT );
		$export_contents  = $_POST['export_contents'] ?? array(); //phpcs:ignore
		$keep_media_files = Input::post( 'keep_media_files', '', Input::TYPE_INT );
		$keep_user_data   = Input::post( 'keep_user_data', '', Input::TYPE_INT );

		$contents = array();
		foreach ( $export_contents as $value ) {
			$contents[] = json_decode( stripslashes( $value ), true );
		}

		// Add Membership Plan contents if user data option is selected.
		if ( $keep_user_data && ! $job_id ) {
			$contents = $this->exporter->append_export_contents( $contents );
		}

		if ( $job_id ) {
			$existing_job = $this->get_export_job( $job_id );
			$contents     = $existing_job['job_requirements'] ?? array();
		}

		if ( ! $job_id && ! $contents ) {
			$this->response_bad_request( __( 'Invalid request!', 'tutor-pro' ) );
		}

		$contents = array_map(
			function ( $content ) {
				$type = Input::sanitize( $content['type'] );
				$ids  = array_map( 'intval', $content['ids'] ?? array() );

				$content['ids']  = $ids;
				$content['type'] = $type;

				return $content;
			},
			$contents
		);

		// Get or create job data.
		$job_data = $this->get_export_job( $job_id ) ?? $this->get_default_job_data( $job_id, $contents );

		$this->job_id = $job_data['job_id'];

		$exporter = $this->exporter;
		$this->exporter->add_job_id( $this->job_id );

		// Process contents in batches.
		foreach ( $contents as $content ) {
			$type = $content['type'] ?? false;
			$ids  = $content['ids'] ?? array();

			// Skip already processed items.
			if ( $exporter::TYPE_SETTINGS === $type && ( $job_data['completed_contents']['settings'] ?? false ) ) {
				continue;
			}

			// Process one item at a time.
			if ( tutor()->course_post_type === $type && ! empty( $ids ) ) {

				$this->prepare_job_data( $job_data, $keep_media_files, $keep_user_data );

				$completed_contents                                = $job_data['completed_contents'][ tutor()->course_post_type ];
				$course_sub_files                                  = $this->course_exporter->get_sub_files( $job_data['keep_user_data'] );
				[$remaining_course_sub_files, $current_sub_file  ] = $this->get_current_and_remaining_sub_files( $completed_contents, $course_sub_files );
				$remaining_ids                                     = $this->get_remaining_ids( $ids, $completed_contents );

				if ( ! empty( $remaining_ids ) ) {
					$is_failed     = false;
					$id_to_process = $exporter::COURSE === $current_sub_file ? array_shift( $remaining_ids ) : $completed_contents['current_processing_id'];
					$sub_contents  = $content['sub_contents'] ?? array();
					$export_data   = null;

					try {
						$export_data = $exporter->add_courses( $id_to_process, $sub_contents, $current_sub_file )->export();

					} catch ( \Throwable $th ) {
						$is_failed = true;
					} finally {
						if ( $is_failed ) {
							$job_data['completed_contents'][ tutor()->course_post_type ]['failed'][] = $id_to_process;
						}
						// if all sub files of a course are exported.
						if ( empty( $remaining_course_sub_files ) ) {
							$job_data['completed_contents'][ tutor()->course_post_type ]['success'][] = $id_to_process;
						}
						$job_data['completed_contents'][ tutor()->course_post_type ]['current_processing_id'] = $id_to_process;
						$job_data['completed_contents'][ tutor()->course_post_type ]['remaining_sub_files']   = $remaining_course_sub_files;
					}

					if ( is_null( $export_data ) ) {
						$export_data = $this->exporter->get_schema();
					}

					try {
						$this->update_export_job( $job_data, tutor()->course_post_type, $export_data );
					} catch ( \Throwable $th ) {
						$this->send_export_response( __( 'Course export failed', 'tutor-pro' ), HttpHelper::STATUS_BAD_REQUEST );
					}

					$this->send_export_response( __( 'Export in progress', 'tutor-pro' ) );
				}
			} elseif ( tutor()->bundle_post_type === $type && ! empty( $ids ) ) {

				$this->prepare_job_data( $job_data, $keep_media_files, $keep_user_data );

				$completed_contents                                = $job_data['completed_contents'][ tutor()->bundle_post_type ];
				$bundle_sub_files                                  = $this->bundle_exporter->get_sub_files( $job_data['keep_user_data'] );
				[$remaining_bundle_sub_files, $current_sub_file  ] = $this->get_current_and_remaining_sub_files( $completed_contents, $bundle_sub_files );
				$remaining_ids                                     = $this->get_remaining_ids( $ids, $completed_contents );

				if ( ! empty( $remaining_ids ) ) {

					$is_failed     = false;
					$id_to_process = $exporter::BUNDLE === $current_sub_file ? array_shift( $remaining_ids ) : $completed_contents['current_processing_id'];
					$export_data   = null;

					try {
						if ( $exporter::COURSES === $current_sub_file ) {

							$next_course_state           = $this->prepare_next_course_in_bundle( $job_data, $id_to_process, $completed_contents, $remaining_bundle_sub_files );
							$current_course_sub_file     = $next_course_state['current_course_sub_file'];
							$remaining_course_sub_files  = $next_course_state['remaining_course_sub_files'];
							$remaining_bundle_course_ids = $next_course_state['remaining_bundle_course_ids'];

							// If no course is added on bundle then skip the course part.
							if ( empty( $remaining_bundle_course_ids ) && $job_data['keep_user_data'] ) {
								$remaining_course_sub_files = array();
								$remaining_bundle_sub_files = array();
							}

							$exporter->set_current_bundle_course_id( $this->current_bundle_course_id );
							$exporter->set_current_course_sub_file( $current_course_sub_file );
						}

						$export_data = $exporter->add_bundles( $id_to_process, $current_sub_file )->export();

					} catch ( \Throwable $th ) {
						$is_failed = true;
					} finally {
						if ( $is_failed ) {
							$job_data['completed_contents'][ tutor()->bundle_post_type ]['failed'][] = $id_to_process;
						}

						if ( $exporter::COURSES === $current_sub_file ) {

							// Shift current course sub file as it is completed.
							array_shift( $remaining_course_sub_files );

							if ( empty( $remaining_course_sub_files ) ) {
								// Shift current bundle course id as it is completed.
								array_shift( $remaining_bundle_course_ids );
								$job_data['completed_contents'][ tutor()->bundle_post_type ][ tutor()->course_post_type ]['success'][] = $this->current_bundle_course_id;
							}

							$remaining_bundle_sub_files = array( $exporter::COURSES );
							if ( empty( $remaining_bundle_course_ids ) && empty( $remaining_course_sub_files ) ) {
								array_shift( $remaining_bundle_sub_files );
							}

							if ( empty( $remaining_bundle_sub_files ) ) {
								$job_data['completed_contents'][ tutor()->bundle_post_type ]['success'][] = $id_to_process;
							}

							$job_data['completed_contents'][ tutor()->bundle_post_type ][ tutor()->course_post_type ]['current_bundle_course_id']    = $this->current_bundle_course_id;
							$job_data['completed_contents'][ tutor()->bundle_post_type ][ tutor()->course_post_type ]['remaining_bundle_course_ids'] = $remaining_bundle_course_ids;
							$job_data['completed_contents'][ tutor()->bundle_post_type ][ tutor()->course_post_type ]['remaining_course_sub_files']  = $remaining_course_sub_files ?? array();
						}

						$job_data['completed_contents'][ tutor()->bundle_post_type ]['current_processing_id'] = $id_to_process;
						$job_data['completed_contents'][ tutor()->bundle_post_type ]['remaining_sub_files']   = $remaining_bundle_sub_files;

					}

					if ( is_null( $export_data ) ) {
						$export_data = $this->exporter->get_schema();
					}

					try {
						$this->update_export_job( $job_data, tutor()->bundle_post_type, $export_data );
					} catch ( \Throwable $th ) {
						$this->send_export_response( __( 'Bundle export failed', 'tutor-pro' ), HttpHelper::STATUS_BAD_REQUEST );
					}

					$this->send_export_response( __( 'Export in progress', 'tutor-pro' ) );
				}
			} elseif ( $exporter::TYPE_CONTENT_BANK === $type && ! empty( $ids ) ) {
				$res = CollectionExporter::ajax_req_handler( $job_data, $ids, $exporter );

				$keep_media_files = $job_data['completed_contents'][ tutor()->course_post_type ]['keep_media_files'] ?? $keep_media_files ?? 0;

				if ( $keep_media_files ) {
					$exporter->add_media_files();
				}

				if ( ! $res->is_done ) {
					$job_data    = $res->job_data;
					$export_data = $res->export_data;
					try {
						$this->update_export_job( $job_data, $exporter::TYPE_CONTENT_BANK, $export_data );
					} catch ( \Throwable $th ) {
						$this->send_export_response( __( 'Content bank data export failed', 'tutor-pro' ), HttpHelper::STATUS_BAD_REQUEST );
					}

					$this->send_export_response( __( 'Export in progress', 'tutor-pro' ) );
				}
			} elseif ( $type === $exporter::TYPE_SETTINGS ) {
				$export_data = $exporter->add_settings()->export();

				$job_data['completed_contents']['settings'] = true;
				try {
					$this->update_export_job( $job_data, $exporter::TYPE_SETTINGS, $export_data );
				} catch ( \Throwable $th ) {
					$this->send_export_response( __( 'Settings export failed', 'tutor-pro' ), HttpHelper::STATUS_BAD_REQUEST );
				}

				$this->send_export_response( __( 'Export in progress', 'tutor-pro' ) );

			} elseif ( $type === $this->exporter::TYPE_MEMBERSHIP_PLANS && ! empty( $ids ) ) {

				$completed_contents                                    = $job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ];
				$membership_sub_files                                  = $this->subscription_exporter->get_sub_files();
				[$remaining_membership_sub_files, $current_sub_file  ] = $this->get_current_and_remaining_sub_files( $completed_contents, $membership_sub_files );
				$remaining_ids = $this->get_remaining_ids( $ids, $completed_contents );

				if ( ! empty( $remaining_ids ) ) {
					$is_failed     = false;
					$id_to_process = $exporter::PLANS === $current_sub_file ? array_shift( $remaining_ids ) : $completed_contents['current_processing_id'];

					$export_data = null;
					try {
						$export_data = $exporter->add_membership_plans( $id_to_process, $current_sub_file )->export();
					} catch ( \Throwable $th ) {
						$is_failed = true;
					} finally {
						if ( $is_failed ) {
							$job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['failed'][]              = $id_to_process;
							$job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['failed']['sub_files'][] = $current_sub_file;
						} else {

							// if all sub files of a membership is exported.
							if ( empty( $remaining_membership_sub_files ) ) {
								$job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['success'][] = $id_to_process;
							}
							$job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['current_processing_id'] = $id_to_process;
							$job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['remaining_sub_files']   = $remaining_membership_sub_files;
						}
					}

					if ( is_null( $export_data ) ) {
						$export_data = $this->exporter->get_schema();
					}

					try {
						$this->update_export_job( $job_data, $this->exporter::TYPE_MEMBERSHIP_PLANS, $export_data );
					} catch ( \Throwable $th ) {
						$this->send_export_response( __( 'Bundle export failed', 'tutor-pro' ), HttpHelper::STATUS_BAD_REQUEST );
					}

					$this->send_export_response( __( 'Export in progress', 'tutor-pro' ) );
				}
			} elseif ( $type === $exporter::GRADE_BOOKS_SETTINGS ) {

				$export_data = $exporter->add_grade_book_settings()->export();

				$job_data['completed_contents'][ $exporter::GRADE_BOOKS_SETTINGS ] = true;
				try {
					$this->update_export_job( $job_data, $exporter::GRADE_BOOKS_SETTINGS, $export_data );
				} catch ( \Throwable $th ) {
					$this->send_export_response( __( 'Settings export failed', 'tutor-pro' ), HttpHelper::STATUS_BAD_REQUEST );
				}

				$this->send_export_response( __( 'Export in progress', 'tutor-pro' ) );
			}
		}

		// If we get here, all items are processed.
		$progress = (int) $this->calculate_progress( $job_data );
		if ( 100 === $progress ) {
			$job_data['job_status'] = 'completed';
		}

		$this->send_export_response( 100 === $progress ? __( 'Export completed', 'tutor-pro' ) : __( 'Export in progress', 'tutor-pro' ) );
	}

	/**
	 * Obtain import data from json file.
	 *
	 * @since 3.8.1
	 *
	 * @throws \Exception Error reading json file.
	 * @throws \Throwable Exception when preparing import contents.
	 *
	 * @param array $file the json file contents.
	 * @param array $job_data the initial job data.
	 * @param int   $collection_id the collection id.
	 *
	 * @return array
	 */
	private function get_import_data_from_json( $file, $job_data, $collection_id ) {
		$contents = $this->read_import_file( $file, $job_data );

		if ( is_wp_error( $contents ) ) {
			throw new \Exception( $contents->get_error_message() );
		}

		$contents = json_decode( $contents, true );

		$this->job_id = $job_data['job_id'] ?? 0;

		if ( $contents ) {
			try {
				$job_data = $this->prepare_import_contents( $contents, $collection_id );
				unset( $contents );
			} catch ( \Throwable $th ) {
				throw $th;
			}
		}

		Helper::increase_memory_limit();

		$data = $this->get_json_data( 'import' );

		return array(
			'data'     => $data,
			'job_data' => $job_data,
		);
	}

	/**
	 * Get job requirements from the zip upload file.
	 *
	 * @throws \Throwable if the json files are not valid.
	 *
	 * @since 3.8.1
	 *
	 * @param array  $directories the array of file directory path.
	 * @param string $path the main path.
	 * @param array  $json_validation_rules rules to validate json.
	 * @param string $import_file_type the import file type.
	 * @param array  $job_requirements the job requirement to update.
	 * @param array  $file_paths the import file json paths.
	 * @param int    $collection_id the collection id.
	 *
	 * @return array
	 */
	private function get_zip_import_job( $directories, $path, $json_validation_rules, $import_file_type = 'courses', $job_requirements = array(), $file_paths = array(), $collection_id = 0 ) {
		$updated_requirements = $job_requirements;
		$paths                = $file_paths;
		foreach ( $directories as $directory ) {
			$file_name = str_replace( $path . '/', '', $directory );
			$json_path = $directory . '/' . $file_name . '.json';
			$files     = glob( "$directory/*" );

			if ( ! in_array( $json_path, $files ) ) {
				throw new \Exception( __( 'The file provided is invalid', 'tutor-pro' ) );
			}

			$paths[ $json_path ] = $import_file_type;

			foreach ( $files as $file_path ) {
				$file_contents = file_get_contents( $file_path );

				if ( $file_contents ) {
					$json_data = json_decode( $file_contents, true );
					$file_type = $json_path === $file_path ? $import_file_type : explode( '.', str_replace( $directory . '/', '', $file_path ) )[0];

					if ( $collection_id && tutor()->course_post_type !== $file_type ) {
						continue;
					}

					$validate = ValidationHelper::validate( $json_validation_rules, $json_data );

					if ( ! $validate->success ) {
						throw new \Exception( __( 'Invalid file provided', 'tutor-pro' ) );
					}

					$file_data   = $json_data['data'] ?? array();
					$import_data = array();
					$requirement = array();

					$requirement['type'] = $file_type;

					if ( tutor()->course_post_type === $file_type ) {
						if ( ! isset( $updated_requirements[ tutor()->course_post_type ] ) ) {
							$requirement['ids'] = array( 1 );
						} else {
							$requirement['ids']   = $updated_requirements[ tutor()->course_post_type ]['ids'];
							$requirement['ids'][] = count( $requirement['ids'] ) + 1;
						}
					} elseif ( tutor()->bundle_post_type === $file_type ) {
						if ( ! isset( $updated_requirements[ tutor()->bundle_post_type ] ) ) {
							$requirement['ids'] = array( 1 );
						} else {
							$requirement['ids']   = $updated_requirements[ tutor()->bundle_post_type ]['ids'];
							$requirement['ids'][] = count( $requirement['ids'] ) + 1;
						}
					} elseif ( $this->exporter::TYPE_MEMBERSHIP_PLANS === $file_type ) {
						if ( ! isset( $updated_requirements[ $this->exporter::TYPE_MEMBERSHIP_PLANS ] ) ) {
							$requirement['ids'] = array( 1 );
						} else {
							$requirement['ids']   = $updated_requirements[ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['ids'];
							$requirement['ids'][] = count( $requirement['ids'] ) + 1;
						}
					} elseif ( 'progress' === $file_type ) {
						if ( ! isset( $updated_requirements[ $file_type ] ) ) {
							$requirement['ids'] = array( 1 );
						} else {
							$requirement['ids']   = $updated_requirements[ $file_type ]['ids'];
							$requirement['ids'][] = count( $requirement['ids'] ) + 1;
						}
					} else {
						$import_data = $file_data[0]['data'][ $file_type ] ?? array();
						if ( ! $import_data ) {
							continue;
						}
						$requirement['ids'] = array_merge( $updated_requirements[ $file_type ]['ids'] ?? array(), array_keys( $import_data ) );
					}
				}

				$updated_requirements[ $file_type ] = $requirement;

				unset( $file_contents );

				if ( $json_path === $file_path ) {
					continue;
				} elseif ( Helper::TUTOR_COURSE_PLANS_TYPE === $file_type ) {
					// Plans should be before order.
					$order_json_path = $directory . '/' . Helper::TUTOR_COURSE_ORDERS_TYPE . '.json';
					if ( isset( $paths[ $order_json_path ] ) ) {
						unset( $paths[ $order_json_path ] );
						$paths[ $file_path ]       = $file_type;
						$paths[ $order_json_path ] = Helper::TUTOR_COURSE_ORDERS_TYPE;
					}
				} else {
					$paths[ $file_path ] = $file_type;
				}
			}
		}

		return array(
			'requirements' => $updated_requirements,
			'file_paths'   => $paths,
		);
	}

	/**
	 * Prepare job data for zip file import.
	 *
	 * @since 3.8.1
	 *
	 * @throws \Throwable If the files provided are invalid.
	 * @throws \Exception If the files provided are invalid.
	 *
	 * @param array $file the zip import file.
	 * @pram  string $upload_dir the zip upload directory.
	 * @param int   $collection_id the collection id.
	 * @param int   $job_id the job id.
	 *
	 * @return array
	 */
	private function prepare_import_zip_job( $file, $upload_dir, $collection_id, $job_id ) {
		$file_paths       = array();
		$job_requirements = array();

		$json_validation_rules = array(
			'schema_version'   => 'required|match_string:2.0.0',
			'exported_at'      => 'required',
			'keep_media_files' => 'required',
			'keep_user_data'   => 'required',
			'data'             => 'required|is_array',
		);

		$zip_upload_path = $upload_dir;

		if ( ! file_exists( $upload_dir ) ) {
			$result = wp_mkdir_p( $upload_dir );

			// Set directory permissions (755 for security).
			if ( file_exists( $upload_dir ) ) {
				chmod( $upload_dir, 0755 );
			}
		}

		$result = unzip_file( $file['tmp_name'], $upload_dir );

		unlink( $file['tmp_name'] );

		if ( is_wp_error( $result ) ) {
			throw new \Exception( $result->get_error_message() );
		}

		if ( QueryHelper::table_exists( 'tutor_gradebooks' ) ) {
			$gradebook_settings_path = $zip_upload_path . '/' . $this->exporter::GRADE_BOOKS_SETTINGS . '.json';

			$gradebook_settings_content = file_exists( $gradebook_settings_path ) ? file_get_contents( $gradebook_settings_path ) : null;
		}

		if ( $gradebook_settings_content ) {
			$json_data = json_decode( $gradebook_settings_content, true );

			$validate = ValidationHelper::validate( $json_validation_rules, $json_data );

			if ( ! $validate->success ) {
				throw new \Exception( __( 'Gradebook Settings file provided is invalid', 'tutor-pro' ) );
			}

			$file_paths[ $gradebook_settings_path ] = $this->exporter::GRADE_BOOKS_SETTINGS;

			$job_requirements[ $this->exporter::GRADE_BOOKS_SETTINGS ] = array( 'type' => $this->exporter::GRADE_BOOKS_SETTINGS );

		}

		$courses_path = $zip_upload_path . '/courses';
		$courses      = glob( "$courses_path/*" ) ?? array();

		$bundle_path = $zip_upload_path . '/' . tutor()->bundle_post_type;

		$bundle_courses = glob( "$bundle_path/*/courses/*" );

		if ( $bundle_courses ) {
			if ( ! file_exists( $courses_path ) ) {
				wp_mkdir_p( $courses_path );

				if ( file_exists( $courses_path ) ) {
					chmod( $courses_path, 0755 );
				}
			}
			foreach ( $bundle_courses as $bundle_courses_path ) {
				$course_id_path     = preg_replace( '#^.*?/courses/(\d+)$#', '$1', $bundle_courses_path );
				$bundle_course_path = str_replace( $course_id_path, '', $bundle_courses_path );
				$final_course_path  = $courses_path . '/' . $course_id_path;

				// Move the courses files under the courses directory.
				$result = rename( $bundle_courses_path, $final_course_path );
				rmdir( $bundle_course_path );
				$courses[] = $final_course_path;
			}
		}

		if ( count( $courses ) ) {

			try {
				$result = $this->get_zip_import_job( $courses, $courses_path, $json_validation_rules, tutor()->course_post_type, $job_requirements, $file_paths, $collection_id );
			} catch ( \Throwable $th ) {
				throw $th;
			}

			$job_requirements = $result['requirements'];
			$file_paths       = $result['file_paths'];
		}

		$bundles = glob( "$bundle_path/*" ) ?? array();

		if ( count( $bundles ) && ! $collection_id ) {

			try {
				$result = $this->get_zip_import_job( $bundles, $bundle_path, $json_validation_rules, tutor()->bundle_post_type, $job_requirements, $file_paths );
			} catch ( \Throwable $th ) {
				throw $th;
			}

			$job_requirements = $result['requirements'];
			$file_paths       = $result['file_paths'];
		}

		$cb_collections = array();

		if ( tutor_utils()->is_addon_enabled( 'content-bank' ) ) {
			$collection_path = $zip_upload_path . '/' . $this->importer::TYPE_CONTENT_BANK;
			$cb_collections  = glob( "$collection_path/*" );
		}

		if ( count( $cb_collections ) ) {
			foreach ( $cb_collections as $cb_path ) {
				$cb_contents = file_get_contents( $cb_path );

				if ( $cb_contents ) {
					$json_data = json_decode( $cb_contents, true );

					$validate = ValidationHelper::validate( $json_validation_rules, $json_data );

					if ( ! $validate->success ) {
						continue;
					}

					$requirement = array();

					$requirement['type'] = $this->exporter::TYPE_CONTENT_BANK;

					if ( ! isset( $job_requirements[ $this->exporter::TYPE_CONTENT_BANK ] ) ) {
						$requirement['ids'] = array( 1 );
					} else {
						$requirement['ids']   = $job_requirements[ $this->exporter::TYPE_CONTENT_BANK ]['ids'];
						$requirement['ids'][] = count( $requirement['ids'] ) + 1;
					}

					$file_paths[ $cb_path ] = $this->exporter::TYPE_CONTENT_BANK;

				}

				$job_requirements[ $this->exporter::TYPE_CONTENT_BANK ] = $requirement;
			}
		}

		$membership_plans_path = $zip_upload_path . '/' . $this->exporter::TYPE_MEMBERSHIP_PLANS;
		$membership_plans      = glob( "$membership_plans_path/*" ) ?? array();

		if ( count( $membership_plans ) && ! $collection_id ) {
			try {
				$result = $this->get_zip_import_job( $membership_plans, $membership_plans_path, $json_validation_rules, $this->exporter::TYPE_MEMBERSHIP_PLANS, $job_requirements, $file_paths );
			} catch ( \Throwable $th ) {
				throw $th;
			}

			$job_requirements = $result['requirements'];
			$file_paths       = $result['file_paths'];
		}

		$settings_path = $zip_upload_path . '/' . $this->exporter::TYPE_SETTINGS . '.json';

		$settings_content = file_exists( $settings_path ) ? file_get_contents( $settings_path ) : null;

		if ( $settings_content ) {
			$json_data = json_decode( $settings_content, true );

			$validate = ValidationHelper::validate( $json_validation_rules, $json_data );

			if ( ! $validate->success ) {
				throw new \Exception( __( 'Settings file provided is invalid', 'tutor-pro' ) );
			}

			$file_paths[ $settings_path ] = $this->exporter::TYPE_SETTINGS;

			$job_requirements[ $this->exporter::TYPE_SETTINGS ] = array( 'type' => $this->exporter::TYPE_SETTINGS );

		}

		$job_data                    = $this->get_default_job_data( $job_id, $job_requirements );
		$job_data['collection_id']   = $collection_id ?? 0;
		$this->job_id                = $job_data['job_id'] ?? 0;
		$job_data['message']         = __( 'Import in progress...', 'tutor-pro' );
		$job_data['file_paths']      = $file_paths;
		$job_data['zip_upload_path'] = $zip_upload_path;

		return $job_data;
	}

	/**
	 * Handle ajax import.
	 *
	 * @since 3.6.0
	 *
	 * @return void wp_json response
	 */
	public function ajax_import_handler() {
		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability();

		global $wp_filesystem;

		// In some cases file system cannot be accessed.
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		$job_id        = Input::post( 'job_id', 0 );
		$collection_id = Input::post( 'collection_id', 0 );
		$job_data      = $this->get_import_job( $job_id );

		$this->job_id    = $job_data['job_id'] ?? 0;
		$data            = $this->get_json_data( 'import' );
		$json_files_path = $job_data['file_paths'] ?? null;

		$file = $_FILES['data'] ?? ''; // phpcs:ignore

		if ( $file ) {

			ContentMapHandler::clear_map();
			ErrorHandler::clear_errors();

			if ( 'application/zip' === $file['type'] ) {
				$upload_dir = wp_upload_dir()['basedir'] . '/tutor-pro/' . explode( '.', $file['name'] )[0];
				try {
					$job_data = $this->prepare_import_zip_job( $file, $upload_dir, $collection_id, $job_id );
				} catch ( \Throwable $th ) {
					Helper::remove_files_directory_recursively( $upload_dir );
					$this->response_bad_request( $th->getMessage() );
				}

				$this->update_import_job( $job_data );
				$this->json_response( $job_data['message'], $job_data, HttpHelper::STATUS_OK );
			} else {
				try {
					$result   = $this->get_import_data_from_json( $file, $job_data, $collection_id );
					$data     = $result['data'];
					$job_data = $result['job_data'];
				} catch ( \Throwable $th ) {
					$this->response_bad_request( $th->getMessage() );
				}
			}
		}

		if ( $json_files_path ) {
			foreach ( $json_files_path as $file_path => $file_type ) {
				if ( ! $file_path ) {
					continue;
				}
				$import_data = file_get_contents( $file_path );
				$import_data = json_decode( $import_data, true );

				if ( $import_data ) {
					switch ( $file_type ) {
						case tutor()->course_post_type:
							$completed_count = count( $job_data['completed_contents'][ tutor()->course_post_type ]['success'] ) + count( $job_data['completed_contents'][ tutor()->course_post_type ]['failed'] );
							$course_data     = $import_data['data'][0]['data']['course'];
							$job_count       = count( $job_data['job_requirements'][ tutor()->course_post_type ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id    = $job_data['job_requirements'][ tutor()->course_post_type ]['ids'][ $completed_count ];
								$import_id = $this->importer->import_content( array( $course_data ), $import_data['keep_media_files'], $job_data['collection_id'] );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ tutor()->course_post_type ]['failed'][] = $course_data['ID'];
								} else {
									$job_data['completed_contents'][ tutor()->course_post_type ]['success'][] = $job_id;
								}

								unset( $job_data['file_paths'][ $file_path ] );
								unlink( $file_path );
								unset( $course_data );
								unset( $import_data );

								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case tutor()->bundle_post_type:
							$completed_count = count( $job_data['completed_contents'][ tutor()->bundle_post_type ]['success'] ) + count( $job_data['completed_contents'][ tutor()->bundle_post_type ]['failed'] );
							$bundle_data     = $import_data['data'][0]['data']['bundle'];
							$job_count       = count( $job_data['job_requirements'][ tutor()->bundle_post_type ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id    = $job_data['job_requirements'][ tutor()->bundle_post_type ]['ids'][ $completed_count ];
								$import_id = $this->importer->import_bundle( $bundle_data, $import_data['keep_media_files'], $collection_id );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ tutor()->bundle_post_type ]['failed'][] = $bundle_data['ID'];
								} else {
									$job_data['completed_contents'][ tutor()->bundle_post_type ]['success'][] = $job_id;
								}

								unset( $job_data['file_paths'][ $file_path ] );
								unlink( $file_path );
								unset( $bundle_data );
								unset( $import_data );

								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case $this->exporter::TYPE_MEMBERSHIP_PLANS:
							$completed_count = count( $job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['success'] ) + count( $job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['failed'] );
							$membership_data = $import_data['data'][0]['data']['plans'];
							$job_count       = count( $job_data['job_requirements'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id    = $job_data['job_requirements'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['ids'][ $completed_count ];
								$import_id = $this->importer->import_tutor_plan( $membership_data );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['failed'][] = $membership_data['id'];
								} else {
									$job_data['completed_contents'][ $this->exporter::TYPE_MEMBERSHIP_PLANS ]['success'][] = $job_id;
								}

								unset( $job_data['file_paths'][ $file_path ] );
								unlink( $file_path );
								unset( $bundle_data );
								unset( $import_data );

								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case $this->exporter::TYPE_CONTENT_BANK:
							$completed_count = count( $job_data['completed_contents'][ $this->exporter::TYPE_CONTENT_BANK ]['success'] ) + count( $job_data['completed_contents'][ $this->exporter::TYPE_CONTENT_BANK ]['failed'] );
							$collection_data = $import_data['data'][0]['data'];
							$job_count       = count( $job_data['job_requirements'][ $this->exporter::TYPE_CONTENT_BANK ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id    = $job_data['job_requirements'][ $this->exporter::TYPE_CONTENT_BANK ]['ids'][ $completed_count ];
								$import_id = $this->importer->import_content( $collection_data, $import_data['keep_media_files'], $collection_id );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ $this->exporter::TYPE_CONTENT_BANK ]['failed'][] = $collection_data['ID'];
								} else {
									$job_data['completed_contents'][ $this->exporter::TYPE_CONTENT_BANK ]['success'][] = $job_id;
								}

								unset( $job_data['file_paths'][ $file_path ] );
								unlink( $file_path );
								unset( $course_data );
								unset( $import_data );

								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case Helper::TUTOR_COURSE_PROGRESS_TYPE:
							$completed_count = count( $job_data['completed_contents'][ $file_type ]['success'] );
							$progress_data   = $import_data['data'][0]['data'][ $file_type ];
							$job_count       = count( $job_data['job_requirements'][ $file_type ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id = $job_data['job_requirements'][ $file_type ]['ids'][ $completed_count ];

								$this->importer->import_tutor_course_progress( $progress_data );

								$job_data['completed_contents'][ $file_type ]['success'][] = $job_id;

								unset( $job_data['file_paths'][ $file_path ] );
								unlink( $file_path );
								unset( $course_data );
								unset( $import_data );

								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case Helper::TUTOR_COURSE_ENROLLMENTS_TYPE:
							$completed_count = count( $job_data['completed_contents'][ $file_type ]['success'] ) + count( $job_data['completed_contents'][ $file_type ]['failed'] );
							$import_job_data = $import_data['data'][0]['data'][ $file_type ];
							$job_count       = count( $job_data['job_requirements'][ $file_type ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id = $job_data['job_requirements'][ $file_type ]['ids'][ $completed_count ];

								$import_id = $this->importer->import_tutor_enrollments( $import_job_data[ $job_id ] );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ $file_type ]['failed'][] = $import_job_data[ $job_id ]['enrollment']['ID'];
								} else {
									$job_data['completed_contents'][ $file_type ]['success'][] = $completed_count;
								}

								if ( count( $import_job_data ) - 1 === $job_id ) {
									unset( $job_data['file_paths'][ $file_path ] );
									unset( $import_job_data );
									unlink( $file_path );
								}
								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case Helper::TUTOR_COURSE_ORDERS_TYPE:
							$completed_count = count( $job_data['completed_contents'][ $file_type ]['success'] ) + count( $job_data['completed_contents'][ $file_type ]['failed'] );
							$import_job_data = $import_data['data'][0]['data'][ $file_type ];
							$job_count       = count( $job_data['job_requirements'][ $file_type ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id = $job_data['job_requirements'][ $file_type ]['ids'][ $completed_count ];

								$import_id = $this->importer->import_tutor_orders( $import_job_data[ $job_id ] );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ $file_type ]['failed'][] = $import_job_data[ $job_id ]['orders']['id'];
								} else {
									$job_data['completed_contents'][ $file_type ]['success'][] = $completed_count;
								}

								if ( count( $import_job_data ) - 1 === $job_id ) {
									unset( $job_data['file_paths'][ $file_path ] );
									unset( $import_job_data );
									unlink( $file_path );
								}
								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case Helper::TUTOR_COURSE_SUBSCRIPTION_TYPE:
							$completed_count = count( $job_data['completed_contents'][ $file_type ]['success'] ) + count( $job_data['completed_contents'][ $file_type ]['failed'] );
							$import_job_data = $import_data['data'][0]['data'][ $file_type ];
							$job_count       = count( $job_data['job_requirements'][ $file_type ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id = $job_data['job_requirements'][ $file_type ]['ids'][ $completed_count ];

								$import_id = $this->importer->import_tutor_subscriptions( $import_job_data[ $job_id ] );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ $file_type ]['failed'][] = $import_job_data[ $job_id ]['id'];
								} else {
									$job_data['completed_contents'][ $file_type ]['success'][] = $completed_count;
								}

								if ( count( $import_job_data ) - 1 === $job_id ) {
									unset( $job_data['file_paths'][ $file_path ] );
									unset( $import_job_data );
									unlink( $file_path );
								}
								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case Helper::TUTOR_COURSE_PLANS_TYPE:
							$completed_count = count( $job_data['completed_contents'][ $file_type ]['success'] ) + count( $job_data['completed_contents'][ $file_type ]['failed'] );
							$import_job_data = $import_data['data'][0]['data'][ $file_type ];
							$job_count       = count( $job_data['job_requirements'][ $file_type ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id = $job_data['job_requirements'][ $file_type ]['ids'][ $completed_count ];

								$import_id = $this->importer->import_tutor_plan( $import_job_data[ $job_id ] );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ $file_type ]['failed'][] = $import_job_data[ $job_id ]['id'];
								} else {
									$job_data['completed_contents'][ $file_type ]['success'][] = $completed_count;
								}

								if ( count( $import_job_data ) - 1 === $job_id ) {
									unset( $job_data['file_paths'][ $file_path ] );
									unset( $import_job_data );
									unlink( $file_path );
								}

								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case Helper::TUTOR_COURSE_REVIEW_TYPE:
							$completed_count = count( $job_data['completed_contents'][ $file_type ]['success'] ) + count( $job_data['completed_contents'][ $file_type ]['failed'] );
							$import_job_data = $import_data['data'][0]['data'][ $file_type ];
							$job_count       = count( $job_data['job_requirements'][ $file_type ]['ids'] );

							if ( $job_count !== $completed_count ) {
								$job_id = $job_data['job_requirements'][ $file_type ]['ids'][ $completed_count ];

								$import_id = $this->importer->import_tutor_reviews( $import_job_data[ $job_id ] );

								if ( is_wp_error( $import_id ) ) {
									$job_data['completed_contents'][ $file_type ]['failed'][] = $import_job_data[ $job_id ]['review']['comment_ID'];
								} else {
									$job_data['completed_contents'][ $file_type ]['success'][] = $completed_count;
								}

								if ( count( $import_job_data ) - 1 === $job_id ) {
									unset( $job_data['file_paths'][ $file_path ] );
									unset( $import_job_data );
									unlink( $file_path );
								}
								$this->update_import_job( $job_data );
								$this->send_import_response();
							}
							break;
						case $this->exporter::TYPE_SETTINGS:
							$import_job_data = $import_data['data'][0]['data'];
							$response        = $this->importer->import_settings( $import_job_data );

							if ( is_wp_error( $response ) ) {
								return $response;
							}

							$job_data['completed_contents'][ $file_type ] = $response;

							unset( $job_data['file_paths'][ $file_path ] );
							unset( $import_job_data );
							unlink( $file_path );

							$this->update_import_job( $job_data );
							$this->send_import_response();
							break;
						case $this->exporter::GRADE_BOOKS_SETTINGS:
							$import_job_data = $import_data['data'][0]['data'];
							$response        = $this->importer->import_grade_settings( $import_job_data );

							if ( is_wp_error( $response ) ) {
								return $response;
							}

							$job_data['completed_contents'][ $file_type ] = $response;

							unset( $job_data['file_paths'][ $file_path ] );
							unset( $import_job_data );
							unlink( $file_path );

							$this->update_import_job( $job_data );
							$this->send_import_response();
							break;
						default:
							break;

					}

					$progress = (int) $this->calculate_progress( $job_data );

					if ( 100 === $progress ) {
						$this->send_import_response( __( 'Import completed', 'tutor-pro' ) );
					}
				}
			}
		} elseif ( $data ) {
			foreach ( $data as $content ) {
				$content_type = $content['content_type'];

				$ids = array();

				if ( $this->importer::TYPE_CONTENT_BANK === $content_type ) {
					$content_type = $this->exporter::TYPE_CONTENT_BANK;
				}

				if ( $this->exporter::TYPE_SETTINGS !== $content_type ) {
					$completed_contents = array_merge( $job_data['completed_contents'][ $content_type ]['success'], $job_data['completed_contents'][ $content_type ]['failed'] );
					$ids                = array_diff( $job_data['job_requirements'][ $content_type ]['ids'], $completed_contents );
				}

				switch ( $content['content_type'] ) {
					case get_tutor_post_types( 'course' ):
						if ( $ids ) {
							$id = array_shift( $ids );
							foreach ( $content['data'] as $data ) {
								if ( $data['ID'] !== $id ) {
									continue;
								}
								$this->update_import_job( $job_data, $data, $content_type, $id );
								$this->send_import_response();
							}
						}
						break;
					case $this->importer::TYPE_CONTENT_BANK:
						if ( $ids && tutor_utils()->is_addon_enabled( 'content-bank' ) ) {
							$id = array_shift( $ids );
							foreach ( $content['data'] as $data ) {
								if ( $data['ID'] !== $id ) {
									continue;
								}
								$this->update_import_job( $job_data, $data, $content_type, $id );
								$this->send_import_response();
							}
						}
						break;
					case get_tutor_post_types( 'bundle' ):
						if ( $ids ) {
							$id = array_shift( $ids );
							foreach ( $content['data'] as $data ) {
								if ( $data['ID'] !== $id ) {
									continue;
								}
								$this->update_import_job( $job_data, $data, $content_type, $id );
								$this->send_import_response();
							}
						}
						break;
					case $this->exporter::TYPE_SETTINGS:
						$this->update_import_job( $job_data, $content['data'], $content_type );
						$this->send_import_response( __( 'Importing Settings', 'tutor-pro' ) );
						break;
					default:
						break;
				}
			}

			$progress = (int) $this->calculate_progress( $job_data );

			if ( 100 === $progress ) {
				$this->send_import_response( __( 'Import completed', 'tutor-pro' ) );
			}
		} else {
			$this->response_bad_request( __( 'Invalid or empty data provided', 'tutor-pro' ) );
		}
	}

	/**
	 * Prepare initial contents for import.
	 *
	 * @since 3.7.1
	 *
	 * @throws \Throwable If content cannot be saved.
	 *
	 * @param array $contents the contents obtained from file.
	 * @param int   $collection_id the collection id.
	 *
	 * @return array|\Throwable
	 */
	private function prepare_import_contents( $contents, $collection_id = 0 ) {
		$keep_media_files = false;

		if ( isset( $contents['keep_media_files'] ) ) {
			$keep_media_files = $contents['keep_media_files'];
		}

		$data = $contents['data'];

		$rules = array(
			'schema_version' => 'required',
			'data'           => 'required|is_array',
		);

		$validate_json = ValidationHelper::validate( $rules, $contents );

		if ( ! $validate_json->success ) {
			return new \Exception( 'invalid_json', __( 'Invalid json', 'tutor-pro' ) );
		}

		$result = $this->prepare_import_job_contents( $data, $collection_id );

		if ( is_wp_error( $result ) ) {
			return new \Exception( $result->get_error_message() );
		}

		$job_data = $this->get_default_job_data( $this->job_id, $result['job_requirement'] );

		$this->job_id = $job_data['job_id'];

		$job_data['keep_media_files'] = $keep_media_files;
		$job_data['message']          = __( 'Import in progress...', 'tutor-pro' );

		if ( $collection_id ) {
			$job_data['collection_id'] = $collection_id;
		}

		try {
			$this->save_import_json_content( $result['updated_data'] );
		} catch ( \Throwable $th ) {
			return $th;
		}

		return $job_data;
	}

	/**
	 * Read contents from import file.
	 *
	 * @since 3.7.0
	 *
	 * @param array $file the import file data.
	 * @param array $job_data the job data.
	 *
	 * @return string|\WP_Error
	 */
	private function read_import_file( $file, $job_data ) {
		if ( ! $file && ! $job_data ) {
			return new \WP_Error( 'empty_file', __( 'Invalid or empty file provided', 'tutor-pro' ) );
		}

		if ( $file ) {
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				return new \WP_Error( 'upload_error', __( 'File upload error', 'tutor-pro' ) );
			}

			try {
				$contents = file_get_contents( $file['tmp_name'] );

				if ( ! $contents ) {
					return new \WP_Error( 'error_file_reading', __( 'Error reading from file', 'tutor-pro' ) );
				}
			} catch ( \Throwable $th ) {
				$this->response_bad_request( $th->getMessage() );
			} finally {
				unlink( $file['tmp_name'] );
			}
		}

		return $contents;
	}

	/**
	 * Prepare Import Job Requirements.
	 *
	 * @since 3.7.0
	 *
	 * @param array $data the contents.
	 * @param int   $collection_id the collection id.
	 *
	 * @return array|\WP_Error
	 */
	private function prepare_import_job_contents( $data, $collection_id = 0 ) {

		$job_requirements = array();

		$importable_contents = array();

		foreach ( $this->exporter->get_exportable_content() as $value ) {
			if ( $this->exporter::TYPE_CONTENT_BANK === $value['key'] ) {
				$importable_contents[] = $this->importer::TYPE_CONTENT_BANK;
				continue;
			}

			$importable_contents[] = $value['key'];
		}

		$updated_data = $data;

		foreach ( $data as $key => $content ) {
			$requirement    = array();
			$bundle_courses = array();
			$rules          = array(
				'content_type' => 'required|match_string:' . implode( ',', $importable_contents ),
				'data'         => 'required|is_array',
			);

			$validate_content = ValidationHelper::validate( $rules, $content );

			if ( ! $validate_content->success ) {
				return new \WP_Error( 'invalid_data', __( 'The data provided is invalid', 'tutor-pro' ), $validate_content->errors );
			}

			if ( $this->importer::TYPE_CONTENT_BANK === $content['content_type'] && ! tutor_utils()->is_addon_enabled( 'content-bank' ) ) {
				unset( $updated_data[ $key ] );
				continue;
			}

			if ( get_tutor_post_types( 'bundle' ) === $content['content_type'] ) {
				$course_ids  = $job_requirements[ get_tutor_post_types( 'course' ) ]['ids'] ?? array();
				$course_data = array(
					'content_type' => get_tutor_post_types( 'course' ),
					'data'         => array(),
				);

				if ( $course_ids ) {
					$course_data = array_shift( $updated_data );
				}
				foreach ( $content['data'] as $bundle ) {
					$bundle_courses = $bundle['courses'];

					if ( ! count( $bundle_courses ) ) {
						continue;
					}

					$bundle_course_ids = array_column( $bundle_courses, 'ID' );

					if ( count( $course_ids ) ) {
						$unique_ids = array_diff( $bundle_course_ids, $course_ids );
						array_push( $job_requirements[ get_tutor_post_types( 'course' ) ]['ids'], ...$unique_ids );

						$course_data['data'] = array_merge( $course_data['data'], $bundle_courses );

					} else {
						if ( $job_requirements[ get_tutor_post_types( 'course' ) ] ) {
							$unique_ids = array_diff( $bundle_course_ids, $job_requirements[ get_tutor_post_types( 'course' ) ]['ids'] );
							array_push( $job_requirements[ get_tutor_post_types( 'course' ) ]['ids'], ...$unique_ids );
						} else {
							$requirement['type']                                  = get_tutor_post_types( 'course' );
							$requirement['ids']                                   = $bundle_course_ids;
							$job_requirements[ get_tutor_post_types( 'course' ) ] = $requirement;
						}

						$course_data['data'] = array_merge( $course_data['data'], $bundle_courses );
					}
				}

				array_unshift( $updated_data, $course_data );

				if ( $collection_id ) {
					continue;
				}
			}

			$requirement['type'] = $this->importer::TYPE_CONTENT_BANK === $content['content_type'] ? $this->exporter::TYPE_CONTENT_BANK : $content['content_type'];

			if ( $this->exporter::TYPE_SETTINGS !== $content['content_type'] ) {
				$requirement['ids'] = array_column( $content['data'], 'ID' );
			}

			$job_requirements[ $requirement['type'] ] = $requirement;
		}

		if ( $collection_id ) {
			foreach ( $updated_data as $key => $data ) {
				if ( get_tutor_post_types( 'bundle' ) === $data['content_type'] ) {
					unset( $updated_data[ $key ] );
				}
			}
		}

		return array(
			'job_requirement' => $job_requirements,
			'updated_data'    => $updated_data,
		);
	}

	/**
	 * Save import content in json file.
	 *
	 * @param array $content the content to store.
	 *
	 * @throws \Exception If error writing import content to file.
	 *
	 * @return int|bool
	 */
	private function save_import_json_content( $content ) {
		$json_file = $this->get_json_file( 'import' );

		$data = file_put_contents( $json_file, wp_json_encode( $content, JSON_PRETTY_PRINT ) );

		if ( ! $data ) {
			throw new \Exception( esc_html__( 'Error writing to file', 'tutor-pro' ) );
		}

		return $data;
	}


	/**
	 * Get job data
	 *
	 * @since 3.6.0
	 *
	 * @param mixed $job_id Job id to get job data.
	 *
	 * @return array
	 */
	private function get_export_job( $job_id ) {
		return get_option( $this->exporter::OPT_NAME . $job_id, null );
	}

	/**
	 * Get import job data
	 *
	 * @since 3.6.0
	 *
	 * @param mixed $job_id Job id to get job data.
	 *
	 * @return array
	 */
	private function get_import_job( $job_id ) {
		return get_option( $this->importer::OPT_NAME . $job_id, null );
	}

	/**
	 * Update export job data
	 *
	 * @since 3.6.0
	 *
	 * @throws \Throwable If failed to update the json file.
	 *
	 * @param array  $job_data Job data.
	 * @param string $job_type New done job type.
	 * @param mixed  $new_export_data Exported data to merge with the job data.
	 *
	 * @return void
	 */
	private function update_export_job( array $job_data, string $job_type, $new_export_data ) {

		if ( empty( $new_export_data ) ) {
			update_option( $this->exporter::OPT_NAME . $this->job_id, $job_data, false );
		} else {
			$this->content_type                 = $new_export_data['data'][0]['content_type'] ?? $job_type;
			$this->content_data                 = $new_export_data['data'][0]['data'] ?? array();
			$this->current_processing_parent_id = $job_data['completed_contents'][ $this->content_type ]['current_processing_id'] ?? null;

			try {
				$this->get_json_file_data( $new_export_data );
			} catch ( \Throwable $th ) {
				tutor_log( 'update export ' . $th->getMessage() );
			}

			$job_data['job_progress'] = $this->calculate_progress( $job_data );

			update_option( $this->exporter::OPT_NAME . $this->job_id, $job_data, false );
		}
	}

	/**
	 * Update import job data
	 *
	 * @since 3.6.0
	 * @since 3.7.1 param $data & $content_type & $content_id added.
	 *
	 * @param array  $job_data     the job data to update.
	 * @param array  $data         the array of content data.
	 * @param string $content_type the type of content being imported.
	 * @param int    $content_id   the content id being imported.
	 *
	 * @return void|\WP_Error
	 */
	private function update_import_job( array $job_data, array $data = array(), string $content_type = '', int $content_id = 0 ) {
		if ( $data ) {
			$import_id = null;

			if ( get_tutor_post_types( 'course' ) === $content_type ) {
				$import_id = $this->importer->import_content( array( $data ), $job_data['keep_media_files'], $job_data['collection_id'] );
			}

			if ( $this->exporter::TYPE_CONTENT_BANK === $content_type ) {
				$import_id = $this->importer->import_content( array( $data ), $job_data['keep_media_files'] );
			}

			if ( get_tutor_post_types( 'bundle' ) === $content_type ) {
				$import_id = $this->importer->import_bundle( $data, $job_data['keep_media_files'] );
			}

			if ( $this->exporter::TYPE_SETTINGS === $content_type ) {
				$response = $this->importer->import_settings( $data );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$job_data['completed_contents'][ $content_type ] = $response;
			} else {
				if ( is_wp_error( $import_id ) ) {
					$job_data['completed_contents'][ $content_type ]['failed'][] = $content_id;
				}

				if ( isset( $import_id ) && ! is_wp_error( $import_id ) ) {
					$job_data['completed_contents'][ $content_type ]['success'][] = $content_id;
				}
			}
		}
		update_option( $this->importer::OPT_NAME . $this->job_id, $job_data, false );
	}

	/**
	 * Prepare and send job response
	 *
	 * @since 3.6.0
	 *
	 * @param string $message Response message.
	 * @param int    $status_code Status code.
	 *
	 * @return void
	 */
	private function send_export_response( string $message, $status_code = HttpHelper::STATUS_OK ) {
		$job_data = $this->get_export_job( $this->job_id );
		$progress = (int) $this->calculate_progress( $job_data );

		$response_to_client = null;
		if ( 100 === $progress ) {
			$job_data['job_progress'] = $progress;
			$job_data['job_status']   = 'completed';

			$job_data = $this->generate_progress_message( $job_data, $progress, false );

			$source_dir = $this->upload_dir . "export-{$this->job_id}";
			$zip_path   = trailingslashit( $this->upload_dir ) . 'tutor-lms-data-' . gmdate( 'Y-m-d-H-i-s', tutor_time() ) . '.zip';

			// Create zip and save the zip file name in the job data.
			$job_data['exported_data'] = basename( FileSystemHelper::zip( $source_dir, $zip_path ) );

			// Download url and file size.
			$job_data['export_file'] = array(
				'url'       => Helper::get_download_url( basename( $zip_path ) ),
				'file_size' => size_format( filesize( $zip_path ) ),
			);

			do_action( 'tutor_pro_export_completed', $this->job_id, $job_data['exported_data'] );

			// Send response to the client.
			$response_to_client = $job_data;

			// Unlink the exported folder.
			if ( is_dir( $source_dir ) ) {
				FileSystemHelper::delete_directory( $source_dir );
			}
		} else {
			$job_data['job_progress'] = $progress;
			$job_data                 = $this->generate_progress_message( $job_data, $progress, false );
		}

		update_option( $this->exporter::OPT_NAME . $this->job_id, $job_data, false );

		$this->json_response( $job_data['message'], $response_to_client ? $response_to_client : $job_data, $status_code );
	}

	/**
	 * Prepare and send job response
	 *
	 * @since 3.6.0
	 *
	 * @param string $message Response message.
	 * @param int    $status_code Status code.
	 *
	 * @return void
	 */
	private function send_import_response( string $message = '', $status_code = HttpHelper::STATUS_OK ) {
		$job_data = $this->get_import_job( $this->job_id );
		$progress = (int) $this->calculate_progress( $job_data );

		if ( 100 === $progress ) {
			$job_data['job_progress'] = $progress;
			$job_data['job_status']   = 'completed';

			if ( ErrorHandler::get_errors() ) {
				$job_data['errors'] = ErrorHandler::get_errors();
				ErrorHandler::clear_errors();
			}

			ContentMapHandler::clear_map();

			if ( isset( $job_data['zip_upload_path'] ) ) {
				Helper::remove_files_directory_recursively( $job_data['zip_upload_path'] );
				unset( $job_data['zip_upload_path'] );
			}

			$job_data = $this->generate_progress_message( $job_data, $progress, true );

			// Unlink the json file.
			if ( file_exists( $this->get_json_file( 'import' ) ) ) {
				unlink( $this->get_json_file( 'import' ) );
			}
		} else {
			$job_data['job_progress'] = $progress;
			$job_data                 = $this->generate_progress_message( $job_data, $progress, true );
		}

		$this->update_import_job( $job_data );

		$this->json_response( $message, $job_data, $status_code );
	}

	/**
	 * Get progress message title and label by type.
	 *
	 * @since 3.8.1
	 *
	 * @param string  $type the type of content.
	 * @param integer $progress the progress count.
	 * @param integer $count the count of items.
	 *
	 * @return array|string
	 */
	private function get_progress_label_by_type( string $type, int $progress, int $count ) {
		switch ( $type ) {
			case tutor()->course_post_type:
				return $progress === 100 ? array(
					// translators: %d : number of course imported/exported successfully.
					sprintf( _n( ' Course (%d)', ' Courses (%d)', $count, 'tutor-pro' ), $count ),
					sprintf( _n( ' Course ID (%d)', ' Course IDs (%d)', $count, 'tutor-pro' ), $count ),
				) : sprintf( _n( ' %d Course', ' %d Courses', $count, 'tutor-pro' ), $count );
			case tutor()->bundle_post_type:
				return $progress === 100 ? array(
					// translators: %d : number of bundles imported/exported successfully.
					sprintf( _n( ' Bundle (%d)', ' Bundles (%d)', $count, 'tutor-pro' ), $count ),
					sprintf( _n( ' Bundle ID (%d)', ' Bundle IDs (%d)', $count, 'tutor-pro' ), $count ),
				) : sprintf( _n( ' %d Bundle', ' %d Bundles', $count, 'tutor-pro' ), $count );
			case $this->exporter::TYPE_CONTENT_BANK:
				return $progress === 100 ? array(
					// translators: %d : number of collections imported/exported successfully.
					sprintf( _n( ' Collection (%d)', ' Collections (%d)', $count, 'tutor-pro' ), $count ),
					sprintf( _n( ' Collection ID (%d)', ' Collection IDs (%d)', $count, 'tutor-pro' ), $count ),
				) : sprintf( _n( ' %d Collection', ' %d Collections', $count, 'tutor-pro' ), $count );
			case $this->exporter::TYPE_MEMBERSHIP_PLANS:
				return $progress === 100 ? array(
					// translators: %d : number of plans imported/exported successfully.
					sprintf( _n( ' Membership Plan (%d)', ' Membership Plans (%d)', $count, 'tutor-pro' ), $count ),
					sprintf( _n( ' Membership Plan ID (%d)', ' Membership Plan IDs (%d)', $count, 'tutor-pro' ), $count ),
				) : sprintf( _n( ' %d Membership Plan', ' %d Membership Plans', $count, 'tutor-pro' ), $count );
			case Helper::TUTOR_COURSE_ENROLLMENTS_TYPE:
				// translators: %d : number of enrollments imported/exported successfully.
				return sprintf( _n( 'Enrollment ID (%d)', 'Enrollment IDs (%d)', $count, 'tutor-pro' ), $count );
			case Helper::TUTOR_COURSE_REVIEW_TYPE:
				// translators: %d : number of reviews imported/exported successfully.
				return sprintf( _n( 'Review ID (%d)', 'Review IDs (%d)', $count, 'tutor-pro' ), $count );
			case Helper::TUTOR_COURSE_ORDERS_TYPE:
				// translators: %d : number of orders imported/exported successfully.
				return sprintf( _n( 'Order ID (%d)', 'Order IDs (%d)', $count, 'tutor-pro' ), $count );
			case Helper::TUTOR_COURSE_PLANS_TYPE:
				// translators: %d : number of subscription plans imported/exported successfully.
				return sprintf( _n( 'Subscription Plan ID (%d)', 'Subscription Plan IDs (%d)', $count, 'tutor-pro' ), $count );
		}
	}

	/**
	 * Generate import/export progress message dynamically.
	 *
	 * @since 3.8.1
	 *
	 * @param array   $job_data the job data.
	 * @param integer $progress the import/export progress.
	 * @param boolean $is_import whether it is import or export.
	 *
	 * @return array
	 */
	private function generate_progress_message( array $job_data, int $progress, bool $is_import ) {
		$updated_job_data = $job_data;

		$not_user_data = array(
			tutor()->course_post_type,
			tutor()->bundle_post_type,
			$this->exporter::TYPE_CONTENT_BANK,
			$this->exporter::TYPE_MEMBERSHIP_PLANS,
		);

		$progress_type    = $is_import ? __( 'Importing', 'tutor-pro' ) : __( 'Exporting', 'tutor-pro' );
		$has_user_data    = false;
		$failed_user_data = false;
		$has_settings     = false;
		$message          = '';
		$failed_message   = '';

		foreach ( $updated_job_data['completed_contents'] as $type => $content ) {

			if ( $type === $this->exporter::TYPE_SETTINGS ) {
				if ( ! $content ) {
					unset( $updated_job_data['completed_contents'][ $type ] );
				} else {
					$has_settings = true;
				}
				continue;
			}

			if ( $type === $this->exporter::GRADE_BOOKS_SETTINGS ) {
				if ( ! $content ) {
					unset( $updated_job_data['completed_contents'][ $type ] );
				} else {
					$has_user_data = true;
				}
				continue;
			}

			$success = count( $content['success'] );
			$failed  = count( $content['failed'] );

			if ( ! in_array( $type, $not_user_data ) ) {
				if ( $success ) {
					$has_user_data = true;
				}
				if ( $failed && 100 === $progress ) {
					$failed_user_data = true;
					$updated_job_data['completed_contents'][ $type ]['label'] = $this->get_progress_label_by_type( $type, $progress, $failed ) . ',' ?? '';
				}
			} else {

				if ( $success ) {
					$message .= 100 === $progress ? $this->get_progress_label_by_type( $type, $progress, $success )[0] . ',' : $this->get_progress_label_by_type( $type, $progress, $success ) . ',';
				}

				if ( $failed ) {
					$failed_message .= 100 === $progress ? $this->get_progress_label_by_type( $type, $progress, $failed )[0] . ',' : $this->get_progress_label_by_type( $type, $progress, $failed ) . ',';
					$updated_job_data['completed_contents'][ $type ]['label'] = $this->get_progress_label_by_type( $type, $progress, $failed )[1] . ',' ?? '';
				}
			}
		}

		if ( $failed_user_data ) {
			$failed_message .= __( ' User Data,', 'tutor-pro' );
		}

		if ( $has_user_data ) {
			$message .= __( ' User Data,', 'tutor-pro' );
		}

		if ( $has_settings ) {
			$message .= __( ' Settings,', 'tutor-pro' );
		}

		if ( 100 === $progress ) {
			$updated_job_data['failed_message'] = rtrim( trim( $failed_message ), ',' );
			$updated_job_data['message']        = rtrim( trim( $message ), ',' );
		} else {
			$message = rtrim( trim( $message ), ',' ) . ' ' . $progress_type;

			if ( $failed_message ) {
				$message .= ' and ' . rtrim( trim( $failed_message ), ',' ) . __( ' Failed', 'tutor-pro' );
			}
			$updated_job_data['message'] = $message;
		}

		return $updated_job_data;
	}

	/**
	 * Calculate the job progress based on the data
	 *
	 * @since 3.6.0
	 *
	 * @param array $job_data Job data.
	 *
	 * @return number Job progress
	 */
	private function calculate_progress( array $job_data ) {
		$total     = 0;
		$completed = 0;

		foreach ( $job_data['job_requirements'] as $content ) {

			if ( in_array( $content['type'], Exporter::SETTINGS_CONTENT_TYPES ) ) {

				++$total;

				if ( ! empty( $job_data['completed_contents'][ $content['type'] ] ) ) {
					++$completed;
				}
				continue;
			}

			$total += count( $content['ids'] );

			$completed_content_type = $job_data['completed_contents'][ $content['type'] ];

			if ( is_array( $completed_content_type ) ) {

				$completed += count( $completed_content_type['success'] );
				$completed += count( $completed_content_type['failed'] );
			}
		}

		return $total > 0 ? round( ( $completed / $total ) * 100, 2 ) : 100;
	}

	/**
	 * Get the JSON file
	 *
	 * @since 3.6.0
	 *
	 * @since 3.8.1 added \WP_Filesystem_Base.
	 *
	 * @global \WP_Filesystem_Base $wp_filesystem WordPress filesystem instance.
	 *
	 * @param string $type whether import or export.
	 *
	 * @return string JSON file path
	 */
	private function get_json_file( string $type = 'export' ) {

		global $wp_filesystem;

		if ( 'import' === $type ) {
			$import_file = $this->upload_dir . 'import-' . $this->job_id . '.json';

			// Ensure directory exists with proper permissions.
			if ( ! file_exists( $this->upload_dir ) ) {
				wp_mkdir_p( $this->upload_dir );

				// Set directory permissions (755 for security).
				if ( file_exists( $this->upload_dir ) ) {
					chmod( $this->upload_dir, 0755 );
				}
			}

			return $import_file;
		}

		list( $folder, $file_name ) = $this->determine_export_file_name();

		if ( empty( $file_name ) ) {
			return '';
		}

		$export_folder = $this->upload_dir . "{$type}-{$this->job_id}/{$this->content_type}/{$folder}";

		// Ensure directory exists with proper permissions.
		if ( ! $wp_filesystem->is_dir( $export_folder ) ) {
			wp_mkdir_p( $export_folder );

			if ( $wp_filesystem->is_dir( $export_folder ) ) {
				chmod( $export_folder, 0755 ); //phpcs:ignore
			}
		}

		return trailingslashit( $export_folder ) . $file_name . '.json';
	}

	/**
	 * Get the JSON file
	 *
	 * @since 3.6.0
	 *
	 * @since 3.8.1 added \WP_Filesystem_Base.
	 *
	 * @global \WP_Filesystem_Base $wp_filesystem WordPress filesystem instance.
	 *
	 * @param string $type whether import or export.
	 *
	 * @return array JSON file path
	 */
	private function get_json_data( string $type = 'export' ) {

		global $wp_filesystem;

		$export_file = $this->get_json_file( $type );
		$data        = $wp_filesystem->get_contents( $export_file );

		return json_decode( $data, true );
	}

	/**
	 * Update json file update
	 *
	 * @since 3.6.0
	 *
	 * @since 3.8.1 added \WP_Filesystem_Base.
	 *
	 * @global \WP_Filesystem_Base $wp_filesystem WordPress filesystem instance.
	 *
	 * @throws \Exception If failed to write the JSON file.
	 *
	 * @param array $data Exported data.
	 */
	private function get_json_file_data( $data ) {

		WP_Filesystem();
		global $wp_filesystem;

		$data = Helper::deep_maybe_unserialize( $data );

		// Unwrap the 'courses' array to ensure the data format is transparent and consistent across both bundled and course exports.
		if ( isset( $data['data'][0]['data'] ) && Exporter::COURSES === array_key_first( $data['data'][0]['data'] ) ) {
			$data['data'][0]['data'] = $data['data'][0]['data'][ Exporter::COURSES ];
		}

		$store = $wp_filesystem->put_contents(
			$this->get_json_file(),
			wp_json_encode( $data, JSON_PRETTY_PRINT )
		);

		if ( false === $store ) {
			throw new \Exception( esc_html__( 'Failed to write the JSON file', 'tutor-pro' ) );
		}

		return $store;
	}

	/**
	 * Get default job data
	 *
	 * @since 3.6.0
	 *
	 * @param mixed  $job_id Job id.
	 * @param string $job_requirements Job requirements.
	 *
	 * @return array
	 */
	private function get_default_job_data( $job_id, $job_requirements ) {
		$user_id = get_current_user_id();

		$data = array(
			'created_at'         => gmdate( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), tutor_time() ),
			'user_name'          => tutor_utils()->display_name( $user_id ),
			'job_id'             => $job_id ? $job_id : wp_rand(),
			'job_progress'       => '0',
			'job_status'         => 'in-progress',
			'job_requirements'   => $job_requirements,
			'message'            => __( 'Export in progress...', 'tutor-pro' ),
			'failed_message'     => '',
			'completed_contents' => array(
				tutor()->course_post_type              => array(
					'label'                 => '',
					'current_processing_id' => array(),
					'success'               => array(),
					'failed'                => array(),
				),
				tutor()->bundle_post_type              => array(
					'label'                   => '',
					'success'                 => array(),
					'failed'                  => array(),
					tutor()->course_post_type => array(
						'success' => array(),
						'failed'  => array(),
					),
				),
				$this->exporter::TYPE_CONTENT_BANK     => array(
					'label'   => '',
					'success' => array(),
					'failed'  => array(),
				),
				$this->exporter::TYPE_MEMBERSHIP_PLANS => array(
					'label'   => '',
					'success' => array(),
					'failed'  => array(),
				),
				Helper::TUTOR_COURSE_ENROLLMENTS_TYPE  => array(
					'label'   => '',
					'success' => array(),
					'failed'  => array(),
				),
				Helper::TUTOR_COURSE_REVIEW_TYPE       => array(
					'label'   => '',
					'success' => array(),
					'failed'  => array(),
				),
				Helper::TUTOR_COURSE_PROGRESS_TYPE     => array(
					'label'   => '',
					'success' => array(),
					'failed'  => array(),
				),
				Helper::TUTOR_COURSE_ORDERS_TYPE       => array(
					'label'   => '',
					'success' => array(),
					'failed'  => array(),
				),
				Helper::TUTOR_COURSE_PLANS_TYPE        => array(
					'label'   => '',
					'success' => array(),
					'failed'  => array(),
				),
				Helper::TUTOR_COURSE_SUBSCRIPTION_TYPE => array(
					'label'   => '',
					'success' => array(),
					'failed'  => array(),
				),
				'settings'                             => false,
				$this->exporter::GRADE_BOOKS_SETTINGS  => false,
			),
		);

		return apply_filters( 'tutor_pro_export_job_data', $data );
	}

	/**
	 * Fetch export/import history
	 *
	 * Retrieves the last 10 export/import operations with their status,
	 * creation date, and user information.
	 *
	 * @since 3.6.0
	 *
	 * @return void send wp_json_response
	 */
	public function ajax_fetch_history() {
		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability();

		$data = $this->get_export_import_history();

		$this->json_response( __( 'History fetched successfully!', 'tutor-pro' ), $data );
	}

	/**
	 * Get export/import history
	 *
	 * Unserialize the data before sending response
	 *
	 * @since 3.6.0
	 *
	 * @return array
	 */
	public function get_export_import_history(): array {
		global $wpdb;
		$data = array();

		$fetch = QueryHelper::get_all(
			$wpdb->options,
			array(
				'(option_name LIKE %s OR option_name LIKE %s) AND option_value NOT LIKE %s' => array(
					'RAW',
					array(
						'tutor_pro_export_%',
						'tutor_pro_import_%',
						'%"job_progress";i:0%',
					),
				),
			),
			'option_id',
			10
		);

		if ( ! $fetch ) {
			return $data;
		}

		foreach ( $fetch as $item ) {
			if ( ! isset( $item->option_name ) || ! isset( $item->option_value ) ) {
				continue;
			}

			$type          = strpos( $item->option_name, 'export' ) !== false ? 'export' : 'import';
			$options_value = maybe_unserialize( $item->option_value );

			if ( ! is_array( $options_value ) ) {
				continue;
			}

			$converted_item = array(
				'type'       => $type,
				'id'         => (int) ( $item->option_id ?? 0 ),
				'created_at' => ! empty( $options_value['created_at'] ) ? tutor_i18n_get_formated_date( $options_value['created_at'] ) : '',
				'user_name'  => Input::sanitize( $options_value['user_name'] ?? '' ),
				'title'      => $this->generate_progress_message( $options_value, 100, 'import' === $type )['message'],
			);

			$data[] = $converted_item;
		}

		return $data;
	}

	/**
	 * Delete export/import history
	 *
	 * @since 3.6.0
	 *
	 * @return void send wp_json_response
	 */
	public function ajax_delete_export_import_history() {
		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability();

		$option_id = Input::post( 'option_id', 0, Input::TYPE_INT );

		if ( ! $option_id ) {
			$this->response_bad_request( __( 'Option ID is required to delete history', 'tutor-pro' ) );
		}

		try {
			$this->delete_export_import_history( $option_id );
		} catch ( \InvalidArgumentException $e ) {
			$this->response_bad_request( $e->getMessage() );
		} catch ( \Exception $e ) {
			$this->response_bad_request( $e->getMessage() );
		}

		$this->json_response( __( 'History deleted successfully!', 'tutor-pro' ) );
	}

	/**
	 * Delete export/import history
	 *
	 * @since 3.6.0
	 *
	 * @param int $option_id Option ID to delete.
	 *
	 * @return bool|\WP_Error
	 */
	public function delete_export_import_history( $option_id = 0 ) {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $wpdb->options
				WHERE option_id = %d
				AND (option_name LIKE %s OR option_name LIKE %s)",
				$option_id,
				'tutor_pro_export_%',
				'tutor_pro_import_%'
			)
		);

		if ( false === $deleted ) {
			return new \WP_Error( 'db_error', __( 'Database error occurred while deleting history', 'tutor-pro' ) );
		}

		return true;
	}

	/**
	 * Delete export/import history
	 *
	 * @since 3.6.0
	 *
	 * @param mixed $job_id Job ID.
	 * @param array $job_data Exported data.
	 *
	 * @return void
	 */
	public function update_settings_log( $job_id, $job_data ) {
		if ( is_array( $job_data ) && ! empty( $job_data ) ) {
			$exported_data = $job_data['data'];
			if ( ! empty( $exported_data ) ) {
				foreach ( $exported_data as $data ) {
					if ( $this->exporter::TYPE_SETTINGS === $data['content_type'] ) {
						( new Options_V2( false ) )->update_settings_log( $data['data'], 'Exported' );
					}
				}
			} else {
				global $wpdb;
				$option_name = 'tutor_pro_export_' . $job_id;

				$delete_clause = array(
					'option_name' => $option_name,
				);

				QueryHelper::delete( $wpdb->options, $delete_clause );
			}
		}
	}

	/**
	 * Get contextual sub contents
	 *
	 * If the context is content-bank then remove quiz from the list
	 * If the context is empty then remove questions from the list.
	 *
	 * @since 3.7.1
	 *
	 * @param string $context Export context.
	 * @param array  $sub_contents Sub contents.
	 *
	 * @return array
	 */
	public function get_contextual_sub_content( string $context, array $sub_contents ) {
		if ( count( $sub_contents ) ) {
			if ( $this->exporter::TYPE_CONTENT_BANK === $context ) {
				$sub_contents = array_filter(
					$sub_contents,
					fn ( $content ) => tutor()->quiz_post_type !== $content['key']
				);
			} else {
				$sub_contents = array_filter(
					$sub_contents,
					fn ( $content ) => $this->exporter::TYPE_QUESTIONS !== $content['key']
				);
			}
		}

		return array_values( $sub_contents );
	}

	/**
	 * Check if the given type is a course or a bundle.
	 *
	 * @since 3.8.1
	 *
	 * @param string $type The post type to check.
	 * @return bool True if the type is a course or bundle, false otherwise.
	 */
	private function is_course_or_bundle( string $type ): bool {

		return in_array( $type, array( tutor()->course_post_type, tutor()->bundle_post_type ) );
	}


	/**
	 * Calculate the number of sub-files that have been processed.
	 *
	 * @since 3.8.1
	 *
	 * @param int   $total_sub_files    Total number of sub-files for the content.
	 * @param array $completed_contents Data containing completed sub-files.
	 * @return int Number of sub-files that have been completed.
	 */
	private function calculate_sub_files( int $total_sub_files, array $completed_contents ): int {

		$remaining       = $completed_contents['remaining_sub_files'] ?? $total_sub_files;
		$remaining_count = is_array( $remaining ) ? count( $remaining ) : $remaining;

		return abs( $total_sub_files - $remaining_count );
	}


	/**
	 * Determine the file name for exporting the current content.
	 *
	 * @since 3.8.1
	 *
	 * @return array Array containing [parent_id, file_key].
	 */
	private function determine_export_file_name(): array {

		if ( empty( $this->content_data ) ) {
			return array( null, null );
		}

		if ( self::SETTINGS === $this->content_type ) {
			$this->content_type = '';
			return array( '', self::SETTINGS );
		}

		if ( $this->exporter::GRADE_BOOKS_SETTINGS === $this->content_type ) {
			$this->content_type = '';
			return array( '', $this->exporter::GRADE_BOOKS_SETTINGS );
		}

		if ( CollectionModel::POST_TYPE === $this->content_type && is_a( $this->content_data[0], 'WP_Post' ) ) {
			return array( '', $this->content_data[0]->ID );
		}

		$content_key = array_keys( $this->content_data );

		if ( CourseExporter::is_course( $content_key[0] ) ) {
			return array( $this->content_data['course']->ID, $this->content_data['course']->ID );
		}

		if ( CourseExporter::is_sub_file( $this->content_type, $content_key[0] ) ) {
			return array( $this->current_processing_parent_id, $content_key[0] );
		}

		if ( BundleExporter::is_bundle( $content_key[0] ) ) {
			return array( $this->content_data['bundle']->ID, $this->content_data['bundle']->ID );
		}

		if ( BundleExporter::is_sub_file( $this->content_type, $content_key[0] ) ) {
			if ( $this->exporter::COURSES === $content_key[0] ) {

				$sub_content_keys = array_keys( $this->content_data[ $content_key[0] ] );
				$file_name        = $this->exporter::COURSE === $sub_content_keys[0] ? $this->current_bundle_course_id : $sub_content_keys[0];
				$folder           = "{$this->current_processing_parent_id}/{$content_key[0]}/{$this->current_bundle_course_id}";
				return array( $folder, $file_name );
			}
			return array( $this->current_processing_parent_id, $content_key[0] );
		}

		if ( PlanExporter::is_membership_plan( $content_key[0] ) ) {
			return array( $this->current_processing_parent_id, $this->current_processing_parent_id );
		}

		if ( SubscriptionExporter::is_sub_file( $this->content_type, $content_key[0] ) ) {
			return array( $this->current_processing_parent_id, $content_key[0] );
		}

		return array( null, null );
	}

	/**
	 * Check if the current bundle course has already been exported.
	 *
	 * @since 3.8.1
	 *
	 * @param array $job_data The export job data containing job requirements.
	 *
	 * @return bool True if the course is already exported, false otherwise.
	 */
	private function is_course_already_exported( $job_data ) {

		$course_type_index = array_search( tutor()->course_post_type, array_column( $job_data['job_requirements'], 'type' ) );

		if ( false === $course_type_index ) {
			return false;
		}

		return in_array( $this->current_bundle_course_id, $job_data['job_requirements'][ $course_type_index ]['ids'] );
	}

	/**
	 * Prepare job data for export by setting flags and adding optional media/user data.
	 *
	 * @since 3.8.1
	 *
	 * @param array    $job_data Reference to the job data array.
	 * @param int|bool $keep_media_files Whether to include media files in the export.
	 * @param int|bool $keep_user_data Whether to include user data in the export.
	 * @return void
	 */
	private function prepare_job_data( &$job_data, $keep_media_files, $keep_user_data ) {

		$job_data['keep_media_files'] = $job_data['keep_media_files'] ?? $keep_media_files ?? 0;
		$job_data['keep_user_data']   = $job_data['keep_user_data'] ?? $keep_user_data ?? 0;

		if ( ! empty( $job_data['keep_media_files'] ) ) {
			$this->exporter->add_media_files();
		}

		if ( ! empty( $job_data['keep_user_data'] ) ) {
			$this->exporter->add_user_data();
			$this->exporter->set_job_data( $job_data );
		}
	}

	/**
	 * Get the remaining IDs to process by removing already completed ones.
	 *
	 * @since 3.8.1
	 *
	 * @param array $ids List of all IDs to process.
	 * @param array $completed_contents Completed contents with 'success' and 'failed' keys.
	 * @return array Remaining IDs that have not yet been processed.
	 */
	private function get_remaining_ids( array $ids, array $completed_contents ) {

		$completed_ids = array_merge( $completed_contents['success'], $completed_contents['failed'] );
		return array_diff( $ids, $completed_ids );
	}

	/**
	 * Get the current sub file being processed and the remaining sub files.
	 *
	 * @since 3.8.1
	 * @param array $completed_contents Completed contents data.
	 * @param array $sub_files List of all sub files for the export.
	 * @return array Tuple containing:
	 *               - array $remaining_course_sub_files The remaining sub files after the current one.
	 *               - mixed $current_sub_file The current sub file being processed, or null if none.
	 */
	private function get_current_and_remaining_sub_files( $completed_contents, $sub_files ) {

		$remaining_course_sub_files = ! empty( $completed_contents['remaining_sub_files'] ) ? $completed_contents['remaining_sub_files'] : $sub_files;
		$current_sub_file           = array_shift( $remaining_course_sub_files );

		return array( $remaining_course_sub_files, $current_sub_file );
	}



	/**
	 * Prepare the next course in a bundle for export, updating job data as needed.
	 *
	 * @since 3.8.1
	 *
	 * @param array $job_data Reference to the job data array.
	 * @param int   $bundle_id The ID of the bundle being exported.
	 * @param array $completed_contents Completed contents for the bundle.
	 * @param array $remaining_bundle_sub_files List of remaining sub files for the bundle.
	 * @return array Tuple containing:
	 *               - string $current_course_sub_file The current course sub file being processed.
	 *               - array $remaining_course_sub_files The remaining course sub files
	 *               - array $remaining_bundle_course_ids The remaining bundle course ids
	 */
	private function prepare_next_course_in_bundle( &$job_data, $bundle_id, $completed_contents, $remaining_bundle_sub_files ) {

		$course_post_type = tutor()->course_post_type;
		$bundle_post_type = tutor()->bundle_post_type;
		$course           = $completed_contents[ $course_post_type ];

		$bundle_course_ids           = ! empty( $course['bundle_course_ids'] ) ? $course['bundle_course_ids'] : BundleModel::get_bundle_course_ids( $bundle_id );
		$remaining_bundle_course_ids = ! empty( $course['remaining_bundle_course_ids'] ) ? $course['remaining_bundle_course_ids'] : $bundle_course_ids;

		$course_sub_files           = $this->course_exporter->get_sub_files( $job_data['keep_user_data'] );
		$remaining_course_sub_files = ! empty( $course['remaining_course_sub_files'] ) ? $course['remaining_course_sub_files'] : $course_sub_files;

		$current_course_sub_file        = current( $remaining_course_sub_files );
		$this->current_bundle_course_id = $this->exporter::COURSE === $current_course_sub_file ? current( $remaining_bundle_course_ids ) : $course['current_bundle_course_id'];

		if ( $this->is_course_already_exported( $job_data ) ) {

			array_shift( $remaining_bundle_course_ids );
			$remaining_bundle_sub_files = array( $this->exporter::COURSES );

			$job_data['completed_contents'][ $bundle_post_type ][ $course_post_type ]['remaining_bundle_course_ids'] = $remaining_bundle_course_ids;
			$job_data['completed_contents'][ $bundle_post_type ][ $course_post_type ]['remaining_course_sub_files']  = array();

			if ( empty( $remaining_bundle_course_ids ) ) {
				$job_data['completed_contents'][ $bundle_post_type ]['success'][] = $bundle_id;
				array_shift( $remaining_bundle_sub_files );
			}

			$job_data['completed_contents'][ $bundle_post_type ]['remaining_sub_files'] = $remaining_bundle_sub_files;
			$this->update_export_job( $job_data, $bundle_post_type, array() );
			$this->send_export_response( __( 'Export in progress', 'tutor-pro' ) );
		}

		return array(
			'current_course_sub_file'     => $current_course_sub_file,
			'remaining_course_sub_files'  => $remaining_course_sub_files,
			'remaining_bundle_course_ids' => $remaining_bundle_course_ids,
		);
	}

	/**
	 * Handles the download of an exported ZIP file.
	 *
	 * @since 3.8.1
	 *
	 * @return void
	 */
	public function handle_export_zip_download() {

		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability();

		$action    = Input::get( 'action', '', Input::TYPE_STRING );
		$file_name = Input::get( 'file', '', Input::TYPE_STRING );
		$download  = Input::get( 'download', '', Input::TYPE_BOOL );

		if ( empty( $action ) || Helper::EXPORT_ZIP_DOWNLOAD_ACTION !== $action || empty( $file_name ) ) {
			return;
		}

		$file_path = $this->upload_dir . $file_name;

		if ( false === $download ) {
			wp_delete_file( $file_path );
			return;
		}

		Helper::download_zip( $file_path );
	}
}

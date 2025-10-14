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

use TUTOR\Reviews;
use TutorPro\ContentBank\Models\CollectionModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handling export functionality.
 *
 * @since 3.6.0
 */
class Exporter {

	/**
	 * Course ids to export
	 *
	 * @since 3.6.0
	 *
	 * @var array
	 */
	private $course_ids = array();

	/**
	 * Bundle ids to export
	 *
	 * @since 3.6.0
	 *
	 * @var array
	 */
	private $bundle_ids = array();

	/**
	 * Collection ids to export
	 *
	 * @since 3.7.0
	 *
	 * @var array
	 */
	private $collection_ids = array();

	/**
	 * Course content types to export
	 *
	 * @since 3.6.0
	 *
	 * @var array
	 */
	private $content_types = array();

	/**
	 * Export settings
	 *
	 * @since 3.6.0
	 *
	 * @var array
	 */
	private $export_settings = false;

	/**
	 * Membership ids to export.
	 *
	 * @since 3.8.1
	 *
	 * @var array
	 */
	private $membership_plan_ids = array();

	/**
	 * Keep media files
	 *
	 * @since 3.6.0
	 *
	 * @var array
	 */
	private $keep_media_files = false;

	/**
	 * Unique identifier for the current job or export/import process.
	 *
	 * @since 3.8.1
	 *
	 * @var int
	 */
	private $job_id;

	/**
	 * Version for the JSON file
	 *
	 * @since 3.6.0
	 */
	const SCHEMA_VERSION = '2.0.0';

	/**
	 * Exportable content types other than posts.
	 *
	 * @since 3.6.0
	 */
	const TYPE_SETTINGS    = 'settings';
	const TYPE_ATTACHMENTS = 'attachments';

	/**
	 * Content bank
	 *
	 * @since 3.7.0
	 */
	const TYPE_CONTENT_BANK = 'content_bank';

	/**
	 * Questions
	 *
	 * @since 3.7.1
	 */
	const TYPE_QUESTIONS = 'questions';


	/**
	 * Keep user data
	 *
	 * @since 3.8.1
	 *
	 * @var bool
	 */
	private $keep_user_data = false;

	/**
	 * Export option name
	 *
	 * Each job id will be concat with this option name
	 *
	 * @since 3.6.0
	 */
	const OPT_NAME = 'tutor_pro_export_';

	/**
	 * Add-ons List
	 *
	 * @since 3.8.1
	 */
	const ADD_ONS = array(
		self::TYPE_CONTENT_BANK => 'content-bank',
		'course-bundle'         => 'course-bundle',
	);

	/**
	 * Membership Plans
	 *
	 * @since 3.8.1
	 */
	const TYPE_MEMBERSHIP_PLANS = 'membership_plans';

	/**
	 * Current sub file being processed.
	 *
	 * @since 3.8.1
	 *
	 * @var string
	 */
	private $current_sub_file;

	/**
	 * Current bundle course ID being processed.
	 *
	 * @since 3.8.1
	 *
	 * @var int
	 */
	private $current_bundle_course_id;

	/**
	 * Current course sub file being processed.
	 *
	 * @since 3.8.1
	 *
	 * @var string
	 */
	private $current_course_sub_file;

	/**
	 * Holds the job data associated with the current export process.
	 *
	 * @since 3.8.1
	 *
	 * @var array
	 */
	private $job_data;

	const BUNDLE               = 'bundle';
	const ENROLLMENTS          = 'enrollments';
	const PLANS                = 'plans';
	const ORDERS               = 'orders';
	const SUBSCRIPTIONS        = 'subscriptions';
	const COURSES              = 'courses';
	const COURSE               = 'course';
	const PROGRESS             = 'progress';
	const REVIEWS              = 'reviews';
	const OPTION_USER_IDS      = 'tutor_pro_user_ids_for_';
	const GRADE_BOOKS_SETTINGS = 'grade_books_settings';

	/**
	 * Indicates whether grade book settings have been retrieved or set.
	 *
	 * @since 3.8.1
	 *
	 * @var bool|array
	 */
	private $grade_book_settings = false;

	/**
	 * List of content types that represent single export settings.
	 *
	 * @since 3.8.1
	 *
	 * @var string[]
	 */
	const SETTINGS_CONTENT_TYPES = array(
		self::TYPE_SETTINGS,
		self::GRADE_BOOKS_SETTINGS,
	);


	/**
	 * Get exportable main types.
	 *
	 * @since 3.6.0
	 *
	 * @since 3.7.1 $context param added.
	 *
	 * @param string $context Context content-bank or empty.
	 *
	 * @return array
	 */
	public function get_exportable_content( string $context = '' ): array {
		$contents = array(
			array(
				'key'      => tutor()->course_post_type,
				'label'    => __( 'Courses', 'tutor-pro' ),
				'contents' => $this->get_exportable_sub_contents(),
			),
			array(
				'key'      => self::TYPE_SETTINGS,
				'label'    => __( 'Settings', 'tutor-pro' ),
				'contents' => array(),
			),
			array(
				'key'      => 'keep_media_files',
				'label'    => __( 'Keep media files', 'tutor-pro' ),
				'contents' => array(),
			),
			array(
				'key'      => 'keep_user_data',
				'label'    => __( 'Keep user data', 'tutor-pro' ),
				'contents' => array(),
			),
		);

		// Add enabled add-ons.
		foreach ( self::ADD_ONS as $key => $value ) {
			if ( tutor_utils()->is_addon_enabled( $value ) ) {
				$contents[] = array(
					'key'      => $key,
					'label'    => __( ucwords( str_replace( array( '_', '-' ), ' ', $key ) ), 'tutor-pro' ),
					'contents' => array(),
				);
			}
		}

		if ( self::TYPE_CONTENT_BANK === $context ) {
			$contents = array_filter(
				$contents,
				fn( $content ) => tutor()->course_post_type === $content['key']
			);
			$contents = array_values( $contents ); // Reset keys.
		}
		return apply_filters( 'tutor_pro_exportable_contents', $contents );
	}

	/**
	 * Get exportable sub types.
	 *
	 * @since 3.6.0
	 *
	 * @return array
	 */
	public function get_exportable_sub_contents(): array {
		$types = array(
			tutor()->quiz_post_type       => __( 'Quizzes', 'tutor-pro' ),
			tutor()->assignment_post_type => __( 'Assignments', 'tutor-pro' ),
		);

		$types = array(
			array(
				'label' => __( 'Questions', 'tutor-pro' ),
				'key'   => self::TYPE_QUESTIONS,
			),
			array(
				'label' => __( 'Lessons', 'tutor-pro' ),
				'key'   => tutor()->lesson_post_type,
			),
			array(
				'label' => __( 'Quizzes', 'tutor-pro' ),
				'key'   => tutor()->quiz_post_type,
			),
			array(
				'label' => __( 'Assignments', 'tutor-pro' ),
				'key'   => tutor()->assignment_post_type,
			),
		);

		if ( ! tutor_utils()->is_addon_enabled( 'tutor-assignments' ) ) {
			unset( $types[2] );
		}

		return apply_filters( 'tutor_pro_exportable_sub_types', $types );
	}

	/**
	 * Add courses to export
	 *
	 * @since 3.6.0
	 *
	 * @since 3.8.1 added $current_sub_file parameter
	 *
	 * @param string|array $course_ids Course IDs, comma separate or array.
	 * @param string|array $content_types A single type or array of types to export.
	 * @param string       $current_sub_file    The sub-file type currently being processed (e.g., course, enrollments, plans).
	 *
	 * @return $this object
	 */
	public function add_courses( $course_ids, $content_types, $current_sub_file ) {
		if ( is_string( $content_types ) ) {
			$content_types = array( $content_types );
		}

		$this->course_ids       = $this->prepare_content_ids( $course_ids );
		$this->content_types    = $content_types;
		$this->current_sub_file = $current_sub_file;
		return $this;
	}

	/**
	 * Add bundles to export
	 *
	 * @since 3.6.0
	 *
	 * @since 3.8.1 added $current_sub_file parameter
	 *
	 * @param string|array $bundle_ids Bundle IDs comma separate or array.
	 * @param string       $current_sub_file    The sub-file type currently being processed (e.g., course, enrollments, plans).
	 *
	 * @return $this object
	 */
	public function add_bundles( $bundle_ids, $current_sub_file ) {
		$this->bundle_ids       = $this->prepare_content_ids( $bundle_ids );
		$this->current_sub_file = $current_sub_file;
		return $this;
	}

	/**
	 * Add collection to export
	 *
	 * @since 3.7.0
	 *
	 * @param string|array $collection_ids Collection IDs comma separate or array.
	 *
	 * @return $this object
	 */
	public function add_collections( $collection_ids ) {
		$this->collection_ids = $this->prepare_content_ids( $collection_ids );
		return $this;
	}

	/**
	 * Add bundles to export
	 *
	 * @since 3.6.0
	 *
	 * @return $this object
	 */
	public function add_settings() {
		$this->export_settings = true;
		return $this;
	}

	/**
	 * Mark grade book settings as added.
	 *
	 * @since 3.8.1
	 *
	 * @return $this Returns the current instance for method chaining.
	 */
	public function add_grade_book_settings() {
		$this->grade_book_settings = true;
		return $this;
	}

	/**
	 * Add media files to export
	 *
	 * @since 3.6.0
	 *
	 * @return $this object
	 */
	public function add_media_files() {
		$this->keep_media_files = true;
		return $this;
	}

	/**
	 * Add user data to export
	 *
	 * @since 3.8.1
	 *
	 * @return $this object
	 */
	public function add_user_data() {
		$this->keep_user_data = true;
		return $this;
	}

	/**
	 * Export new export
	 *
	 * @throws \Throwable If invalid course id bundle id detect.
	 *
	 * @since 3.6.0
	 */
	public function export() {
		$export = array(
			'schema_version'   => self::SCHEMA_VERSION,
			'exported_at'      => current_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'keep_media_files' => $this->keep_media_files,
			'keep_user_data'   => $this->keep_user_data,
			'data'             => array(),
		);

		if ( count( $this->course_ids ) ) {
			$course_data = array(
				'content_type' => tutor()->course_post_type,
				'data'         => array(),
			);

			foreach ( $this->course_ids as $id ) {
				try {
					$course_data['data'] = $this->export_courses( $id );
				} catch ( \Throwable $th ) {
					throw $th;
				}
			}

			$export['data'][] = $course_data;
		}

		if ( count( $this->bundle_ids ) ) {
			$bundle_data = array(
				'content_type' => tutor()->bundle_post_type,
				'data'         => array(),
			);

			if ( tutor_utils()->is_addon_enabled( 'course-bundle' ) ) {
				foreach ( $this->bundle_ids as $id ) {
					try {
						$bundle_data['data'] = $this->export_bundles( $id );
					} catch ( \Throwable $th ) {
						throw $th;
					}
				}
			}

			$export['data'][] = $bundle_data;
		}

		if ( count( $this->collection_ids ) ) {
			$collection_data = array(
				'content_type' => CollectionModel::POST_TYPE,
				'data'         => array(),
			);

			foreach ( $this->collection_ids as $id ) {
				try {
					$export_data               = CollectionExporter::export( $id, $this->keep_media_files );
					$collection_data['data'][] = $export_data;
				} catch ( \Throwable $th ) {
					throw $th;
				}
			}

			$export['data'][] = $collection_data;
		}

		if ( $this->export_settings ) {
			$settings = array(
				'content_type' => self::TYPE_SETTINGS,
				'data'         => get_option( 'tutor_option' ),
			);

			$export['data'][] = $settings;
		}

		if ( count( $this->membership_plan_ids ) ) {

			$membership_plan_data = array(
				'content_type' => self::TYPE_MEMBERSHIP_PLANS,
				'data'         => array(),
			);

			foreach ( $this->membership_plan_ids as $id ) {
				try {
					$export_data                  = self::export_membership_plans( $id );
					$membership_plan_data['data'] = $export_data;
				} catch ( \Throwable $th ) {
					throw $th;
				}
			}

			$export['data'][] = $membership_plan_data;
		}

		if ( $this->grade_book_settings ) {
			$grade_book_settings = array(
				'content_type' => self::GRADE_BOOKS_SETTINGS,
				'data'         => get_gradebook_settings(),
			);

			$export['data'][] = $grade_book_settings;
		}

		return $export;
	}

	/**
	 * Convert comma separated id to array id
	 *
	 * @since 3.6.0
	 *
	 * @param mixed $ids Content ids String|array.
	 *
	 * @return array
	 */
	private function prepare_content_ids( $ids ): array {
		return array_map( 'intval', is_array( $ids ) ? $ids : explode( ',', $ids ) );
	}

	/**
	 * Get default exporter schema
	 *
	 * @since 3.6.0
	 *
	 * @return array
	 */
	public function get_schema() {
		$export = array(
			'schema_version'   => self::SCHEMA_VERSION,
			'exported_at'      => current_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'keep_media_files' => $this->keep_media_files,
			'keep_user_data'   => $this->keep_user_data,
			'data'             => array(),
		);

		return $export;
	}

	/**
	 * Set membership plan IDs for the current object.
	 *
	 * @since 3.8.1
	 *
	 * @param array|int $membership_plan_ids Single ID or an array of membership plan IDs to add.
	 * @param string    $current_sub_file   Set Current Sub File to export.
	 *
	 * @return $this Returns the current instance for method chaining.
	 */
	public function add_membership_plans( $membership_plan_ids, $current_sub_file ) {
		$this->membership_plan_ids = $this->prepare_content_ids( $membership_plan_ids );
		$this->current_sub_file    = $current_sub_file;
		return $this;
	}

	/**
	 * Set the job ID for the current instance.
	 *
	 * @param int $job_id The ID of the job to associate with this instance.
	 * @return void
	 */
	public function add_job_id( $job_id ) {
		$this->job_id = $job_id;
	}

	/**
	 * Export course-related data for a given course ID based on the current sub-file type.
	 *
	 * @since 3.8.0
	 *
	 * @param int $id The ID of the course to export.
	 *
	 * @return array An associative array with the sub-file type as the key
	 *                              and the exported data as the value.
	 */
	private function export_courses( $id ): array {

		switch ( $this->current_sub_file ) {

			case self::COURSE:
				return array(
					self::COURSE => empty( $id ) ? array() : CourseExporter::export( $id, $this->content_types, $this->keep_media_files ),
				);

			case self::ENROLLMENTS:
				$enrollment_data = EnrollmentExporter::export( $id );

				// Update Membership Plan IDs.
				if ( ! empty( $enrollment_data['plan_ids'] ) ) {
					$this->update_job_data( $enrollment_data['plan_ids'] );
				}

				return array(
					self::ENROLLMENTS => $enrollment_data['enrollment_data'],
				);

			case self::PLANS:
				$export_data         = PlanExporter::export( $id, self::COURSE );
				$plan_ids            = PlanExporter::extract_plan_ids( $export_data );
				$course_and_plan_ids = array(
					'plan_ids'  => $plan_ids,
					'course_id' => $id,
				);

				update_option( self::OPT_NAME . $this->job_id . '_course_and_plan_ids', $course_and_plan_ids );

				return array( self::PLANS => $export_data );

			case self::ORDERS:
				$course_and_plan_ids = get_option( self::OPT_NAME . $this->job_id . '_course_and_plan_ids' );
				$course_and_plan_ids = array_merge( $course_and_plan_ids['plan_ids'] ?? array(), array( $course_and_plan_ids['course_id'] ?? $id ) );

				$export_data = OrderExporter::export( $course_and_plan_ids, $id );

				delete_option( self::OPT_NAME . $this->job_id . '_course_and_plan_ids' );

				return array( self::ORDERS => $export_data );

			case self::SUBSCRIPTIONS:
				$course_and_plan_ids = get_option( self::OPT_NAME . $this->job_id . '_course_and_plan_ids' );
				$plan_ids            = $course_and_plan_ids['plan_ids'];

				return array( self::SUBSCRIPTIONS => SubscriptionExporter::export( $plan_ids ) );

			case self::PROGRESS:
				return array( self::PROGRESS => ProgressExporter::export( $id ) );

			case self::REVIEWS:
				return array( self::REVIEWS => ( new Reviews() )->get_reviews_by_course_id( $id ) );

			default:
				return array();
		}
	}

	/**
	 * Export bundle data based on the current sub file type.
	 *
	 * @since 3.8.1
	 *
	 * @param int $id Bundle ID to export.
	 *
	 * @return array Exported data keyed by sub file type.
	 */
	private function export_bundles( $id ) {

		switch ( $this->current_sub_file ) {

			case self::BUNDLE:
				return array(
					self::BUNDLE => BundleExporter::export( $id ),
				);

			case self::ENROLLMENTS:
				return array(
					self::ENROLLMENTS => EnrollmentExporter::export( $id, true )['enrollment_data'],
				);

			case self::PLANS:
				$export_data         = PlanExporter::export( $id, self::BUNDLE );
				$plan_ids            = PlanExporter::extract_plan_ids( $export_data );
				$course_and_plan_ids = array(
					'plan_ids'  => $plan_ids,
					'course_id' => $id,
				);

				update_option( self::OPT_NAME . $this->job_id . '_course_and_plan_ids', $course_and_plan_ids );

				return array( self::PLANS => $export_data );

			case self::ORDERS:
				$course_and_plan_ids = get_option( self::OPT_NAME . $this->job_id . '_course_and_plan_ids' );

				$course_and_plan_ids = array_merge( $course_and_plan_ids['plan_ids'] ?? array(), array( $course_and_plan_ids['course_id'] ?? $id ) );

				$export_data = OrderExporter::export( $course_and_plan_ids, $id );

				delete_option( self::OPT_NAME . $this->job_id . '_course_and_plan_ids' );

				return array( self::ORDERS => $export_data );

			case self::SUBSCRIPTIONS:
				$course_and_plan_ids = get_option( self::OPT_NAME . $this->job_id . '_course_and_plan_ids' );
				$plan_ids            = $course_and_plan_ids['plan_ids'];

				return array( self::SUBSCRIPTIONS => SubscriptionExporter::export( $plan_ids ) );

			case self::COURSES:
				$this->content_types    = BundleExporter::get_content_types();
				$this->current_sub_file = $this->current_course_sub_file;
				$export_data            = $this->export_courses( $this->current_bundle_course_id );

				return array( self::COURSES => $export_data );

			default:
				return array();
		}
	}

	/**
	 * Set the current bundle course ID.
	 *
	 * @since 3.8.1
	 *
	 * @param int $id Bundle course ID.
	 *
	 * @return void
	 */
	public function set_current_bundle_course_id( $id ) {

		$this->current_bundle_course_id = $id;
	}

	/**
	 * Set the current course sub file.
	 *
	 * @since 3.8.1
	 *
	 * @param string $id Current course sub file key.
	 *
	 * @return void
	 */
	public function set_current_course_sub_file( $id ) {
		$this->current_course_sub_file = $id;
	}

	/**
	 * Export membership plan data based on the current sub file type.
	 *
	 * @since 3.8.1
	 *
	 * @param int $id Membership plan ID to export.
	 *
	 * @return array Exported data keyed by sub file type.
	 */
	private function export_membership_plans( $id ) {

		switch ( $this->current_sub_file ) {

			case self::PLANS:
				return array( self::PLANS => PlanExporter::export( $id ) );

			case self::SUBSCRIPTIONS:
				return array( self::SUBSCRIPTIONS => SubscriptionExporter::export( array( $id ) ) );

			case self::ORDERS:
				return array( self::ORDERS => OrderExporter::export( array( $id ) ) );

			default:
				return array();
		}
	}

	/**
	 * Set the job data reference for the exporter.
	 *
	 * @since 3.8.1
	 *
	 * @param array $job_data Reference to the job data array.
	 */
	public function set_job_data( &$job_data ) {

		if ( $this->keep_user_data ) {
			$this->job_data = &$job_data;
			return $this;
		}
	}


	/**
	 * Update job requirements with additional membership plan IDs.
	 *
	 * @since 3.8.1
	 *
	 * @param int[] $plan_ids Array of membership plan IDs to add to job requirements.
	 * @return void
	 */
	private function update_job_data( $plan_ids ) {

		if ( ! empty( $plan_ids ) ) {

			foreach ( $this->job_data['job_requirements'] as &$job ) {

				if ( self::TYPE_MEMBERSHIP_PLANS === $job['type'] ) {
					$job['ids'] = array_unique( array_merge( $job['ids'], $plan_ids ) );
				}
			}
		}
	}

	/**
	 * Append additional export items to the provided contents array.
	 *
	 * @since 3.8.1
	 *
	 * @param array $contents Existing export contents.
	 * @return array Modified export contents including the additional items.
	 */
	public function append_export_contents( array $contents ) {
		if ( empty( $contents ) ) {
			return $contents;
		}

		if ( tutor_utils()->is_addon_enabled( 'subscription' ) ) {
			$contents[] = array(
				'type' => self::TYPE_MEMBERSHIP_PLANS,
				'ids'  => array(),
			);
		}

		if ( tutor_utils()->is_addon_enabled( TUTOR_GB()->basename ) ) {
			$contents[] = array(
				'type' => self::GRADE_BOOKS_SETTINGS,
			);
		}

		return $contents;
	}
}

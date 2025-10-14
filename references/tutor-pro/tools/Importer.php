<?php
/**
 * TutorPro Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

use Tutor\Helpers\QueryHelper;
use Tutor\Helpers\ValidationHelper;
use Tutor\Models\CourseModel;
use Tutor\Options_V2;
use TutorPro\ContentBank\Models\ContentModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Importer class
 */
class Importer {
	/**
	 * Course Importer Class Instance.
	 *
	 * @since 3.6.0
	 *
	 * @var CourseImporter
	 */
	private $course_importer;


	/**
	 * Quiz Importer Class Instance.
	 *
	 * @since 3.6.0
	 *
	 * @var QuizImporter
	 */
	private $quiz_importer;

	/**
	 * Assignment Importer Class Instance.
	 *
	 * @since 3.6.0
	 *
	 * @var AssignmentImporter
	 */
	private $assignment_importer;

	/**
	 * Bundle Importer Class Instance.
	 *
	 * @since 3.6.0
	 *
	 * @var BundleImporter
	 */
	private $bundle_importer;


	/**
	 * Tutor Lesson Class Instance.
	 *
	 * @since 3.8.0
	 *
	 * @var CollectionImporter
	 */
	private $collection_importer;

	/**
	 * Tutor Lesson Importer Class Instance.
	 *
	 * @since 3.8.0
	 *
	 * @var LessonImporter
	 */
	private $lesson_importer;

	/**
	 * User Data Importer Class Instance.
	 *
	 * @since 3.8.0
	 *
	 * @var UserDataImporter
	 */
	private $user_data_importer;


	/**
	 * Review Importer Class Instance.
	 *
	 * @since 3.8.0
	 *
	 * @var ReviewImporter
	 */
	private $review_importer;

	/**
	 * Order Importer Class Instance.
	 *
	 * @since 3.8.0
	 *
	 * @var OrderImporter
	 */
	private $order_importer;

	/**
	 * Gradebook table
	 *
	 * @var string
	 */
	private $grade_book_table = 'tutor_gradebooks';



	/**
	 * Import option name
	 *
	 * Each job id will be concat with this option name
	 *
	 * @since 3.6.0
	 */
	const OPT_NAME = 'tutor_pro_import_';


	/**
	 * Content Bank Type
	 *
	 * @since 3.7.0
	 */
	const TYPE_CONTENT_BANK = 'cb-collection';

	/**
	 * Constants for content type.
	 *
	 * @since 3.7.0
	 *
	 * @var string
	 */
	const TYPE_LESSON     = 'lesson';
	const TYPE_ASSIGNMENT = 'assignment';
	const TYPE_QUESTION   = 'question';


	/**
	 * Importer class constructor.
	 */
	public function __construct() {
		$this->course_importer     = new CourseImporter();
		$this->lesson_importer     = new LessonImporter();
		$this->quiz_importer       = new QuizImporter();
		$this->assignment_importer = new AssignmentImporter();
		$this->bundle_importer     = new BundleImporter();
		$this->collection_importer = new CollectionImporter();
		$this->user_data_importer  = new UserDataImporter();
		$this->review_importer     = new ReviewImporter();
		$this->order_importer      = new OrderImporter();
	}

	/**
	 * Import tutor settings.
	 *
	 * @since 3.6.0
	 *
	 * @param array $data array of settings data.
	 *
	 * @return bool|\WP_Error
	 */
	public function import_settings( $data ) {
		if ( is_array( $data ) && count( $data ) ) {
			$update_option = tutor_utils()->sanitize_recursively( $data );

			$tutor_option = get_option( 'tutor_option' );

			if ( $update_option === $tutor_option || maybe_serialize( $tutor_option ) === maybe_serialize( $update_option ) ) {
				return true;
			}

			$response = update_option( 'tutor_option', $update_option );

			( new Options_V2( false ) )->update_settings_log( $update_option, 'Imported' );
			return $response;
		}
	}

	/**
	 * Import gradebook settings.
	 *
	 * @since 3.8.1
	 *
	 * @param array $data the gradebook settings data.
	 *
	 * @return bool|WP_Error
	 */
	public function import_grade_settings( array $data ) {
		$previous_gradebook_ids = array();
		$new_gradebook_ids      = array();

		// Prepare grade settings.
		foreach ( $data as $key => $settings ) {
			$previous_gradebook_ids[] = $settings['gradebook_id'];

			unset( $settings['gradebook_id'] );

			$settings['grade_config'] = maybe_serialize( $settings['grade_config'] );

			$data[ $key ] = $settings;
		}

		try {
			$result = QueryHelper::insert_multiple_rows( $this->grade_book_table, $data, false, false );
			if ( $result ) {
				$new_gradebook_ids = QueryHelper::query(
					$this->grade_book_table,
					array(
						'select' => 'gradebook_id',
						'where'  => array( 'gradebook_id >= LAST_INSERT_ID()' => array( 'RAW', array() ) ),
						'output' => 'ARRAY_N',
					)
				);
				// Flatten ids.
				$new_gradebook_ids = array_merge( ...$new_gradebook_ids );
			}
		} catch ( \Throwable $th ) {
			return new \WP_Error( $th->getMessage() );
		}

		if ( count( $previous_gradebook_ids ) === count( $new_gradebook_ids ) ) {
			$gradebook_map = array_combine( $previous_gradebook_ids, $new_gradebook_ids );

			ContentMapHandler::set_content_map( 'gradebooks', $gradebook_map );
		}

		return $result;
	}

	/**
	 * Prepare tutor settings.
	 *
	 * @since 3.6.0
	 *
	 * @param array $settings array of settings data.
	 *
	 * @return array
	 */
	public function prepare_tutor_settings( $settings ) {
		$data = $settings;

		$skip_options = array(
			'tutor_dashboard_page_id',
			'tutor_toc_page_id',
			'tutor_cart_page_id',
			'tutor_checkout_page_id',
			'course_permalink_base',
			'lesson_permalink_base',
			'membership_pricing_page_id',
			'quiz_permalink_base',
			'assignment_permalink_base',
			'student_register_page',
			'instructor_register_page',
			'course_archive_page',
			'tutor_certificate_page',
		);

		foreach ( $skip_options as $option_key ) {
			if ( isset( $data[ $option_key ] ) ) {
				unset( $data[ $option_key ] );
			}
		}

		return $data;
	}

	/**
	 * Import bundle using importer.
	 *
	 * @since 3.6.0
	 *
	 * @param array $post the bundle data.
	 * @param bool  $keep_media_files whether to download media files or not.
	 *
	 * @return bool|\WP_Error
	 */
	public function import_bundle( array $post, bool $keep_media_files = false ) {
		if ( is_array( $post ) && count( $post ) ) {
			$bundle_id         = $post['ID'] ?? 0;
			$meta              = $post['meta'] ?? null;
			$course_ids        = array();
			$failed_course_ids = array();
			$thumbnail_url     = $post['thumbnail_url'] ?? null;
			$attachment_links  = $post['attachment_links'] ?? null;
			$attachment_ids    = array();
			$map               = ContentMapHandler::get_content_map();
			$course_map        = $map['courses'] ?? null;

			unset( $map );

			if ( $meta ) {
				$meta = array_map( fn( $val ) => $val[0], $meta );
				$this->bundle_importer->prepare_bundle_meta( $meta );
			}

			$post = $this->prepare_post_data( $post );

			if ( is_wp_error( $post ) ) {
				return $post;
			}

			$post = Helper::unset_post_data( $post );

			$post['post_status'] = 'draft';

			$id = wp_insert_post( $post, true );

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			if ( isset( $course_map ) ) {
				$course_map[ $bundle_id ] = array( 'course_id' => $id );
				ContentMapHandler::update_content_map( tutor()->course_post_type, $course_map );
			} else {
				ContentMapHandler::set_content_map( tutor()->course_post_type, array( $bundle_id => array( 'course_id' => $id ) ) );
			}

			if ( $thumbnail_url && $keep_media_files ) {
				Helper::save_post_thumbnail( $thumbnail_url, $id );
			}

			if ( $attachment_links && $keep_media_files ) {
				$attachment_ids = Helper::get_attachment_ids_from_urls( $attachment_links );
			}

			if ( $attachment_ids ) {
				update_post_meta( $id, '_tutor_attachments', maybe_serialize( $attachment_ids ) );
			}

			if ( $meta ) {
				$bundle_course_ids = $meta['bundle-course-ids'] ?? '';

				if ( $bundle_course_ids ) {
					$bundle_course_ids = explode( ',', $bundle_course_ids );

					foreach ( $bundle_course_ids as $course_id ) {
						$course_ids[] = $course_map[ $course_id ]['course_id'] ?? $course_id;
					}
				}
			}

			if ( $course_ids ) {
				$this->bundle_importer->update_course_bundle_ids( $id, $course_ids );
			}

			if ( $id ) {
				if ( $meta ) {
					$this->bundle_importer->prepare_bundle_meta( $meta );
				}
				$id = wp_update_post(
					array(
						'ID'          => $id,
						'post_status' => 'publish',
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					return $id;
				}
			}

			return array(
				'bundle_id'         => $id,
				'failed_course_ids' => $failed_course_ids,
			);
		}
	}

	/**
	 * Import tutor posts recursively.
	 *
	 * @since 3.6.0
	 * @since 3.7.1 param $collection_id added.
	 *
	 * @param array $posts the array of data to import.
	 * @param bool  $keep_media_files whether to download media files or not.
	 * @param int   $collection_id the collection id.
	 * @param bool  $from_course if import from course.
	 *
	 * @return array|bool|\WP_Error
	 */
	public function import_content( array $posts, bool $keep_media_files = false, $collection_id = 0, bool $from_course = false ) {
		global $wpdb;
		if ( $collection_id ) {
			return $this->collection_importer->import_content_in_collection( $posts[0], $keep_media_files, $collection_id, $from_course );
		}

		$course_id      = 0;
		$mapped_courses = ContentMapHandler::get_content_map()['courses'] ?? null;

		if ( is_array( $posts ) && count( $posts ) ) {
			foreach ( $posts as $key => $post ) {
				$import_id        = $post['ID'] ?? null;
				$contents         = $post['contents'] ?? null;
				$taxonomies       = $post['taxonomies'] ?? null;
				$meta             = $post['meta'] ?? null;
				$thumbnail_url    = $post['thumbnail_url'] ?? null;
				$attachment_links = $post['attachment_links'] ?? null;
				$attachment_ids   = array();

				if ( $taxonomies ) {
					$this->set_tutor_course_taxonomies( $taxonomies );
				}

				if ( $attachment_links ) {
					$attachment_ids = Helper::get_attachment_ids_from_urls( $attachment_links );
				}

				// Prepare post data before insert.
				$post = $this->prepare_post_data( $post );

				if ( is_wp_error( $post ) ) {
					return $post;
				}

				$post = Helper::unset_post_data( $post );

				$course_id = wp_insert_post( $post, true, true );

				if ( is_wp_error( $course_id ) ) {
					return $course_id;
				}

				if ( tutor()->course_post_type === get_post_type( $course_id ) ) {
					if ( ! $mapped_courses ) {
						ContentMapHandler::set_content_map( 'courses', array( $import_id => array( 'course_id' => $course_id ) ) );
					} else {
						$mapped_courses[ $import_id ] = array( 'course_id' => $course_id );
						ContentMapHandler::update_content_map( 'courses', $mapped_courses );
					}
				}

				if ( $meta ) {
					$meta = Helper::prepare_meta( $course_id, $meta, $keep_media_files );
				}

				if ( $attachment_ids ) {
					$meta[] = array(
						'post_id'    => $course_id,
						'meta_key'   => '_tutor_attachments',
						'meta_value' => maybe_serialize( $attachment_ids ),
					);
				}

				try {
					QueryHelper::insert_multiple_rows( $wpdb->postmeta, $meta, false, false );
				} catch ( \Throwable $th ) {
					ErrorHandler::set_error( $post['post_type'], 'Error saving meta value for course : ' . $post['post_title'] );
				}

				if ( $thumbnail_url && $keep_media_files ) {
					Helper::save_post_thumbnail( $thumbnail_url, $course_id );
				}

				if ( $taxonomies ) {
					$result = $this->course_importer->course_importer_set_categories_tags( array( $course_id => $taxonomies ) );

				}

				unset( $posts[ $key ] );
				unset( $meta );

				if ( $contents ) {
					$contents = $this->add_post_parent( $contents, $course_id );
					if ( tutor()->course_post_type === get_post_type( $course_id ) ) {
						$this->import_topics( $contents, $keep_media_files, $import_id );
					} else {
						$this->import_child_contents( $contents, $keep_media_files );
					}
				}

				unset( $contents );

			}

			return $course_id;
		}
	}

	/**
	 * Import topic contents form courses.
	 *
	 * @since 3.8.0
	 *
	 * @param array   $contents the topic contents list.
	 * @param boolean $keep_media_files whether to keep media files.
	 * @param int     $parent_id the parent course id.
	 *
	 * @return void
	 */
	private function import_topics( $contents, $keep_media_files = false, $parent_id = 0 ) {
		$children        = array_column( $contents, 'children', 'ID' ) ?? null;
		$course_contents = array();

		unset( $contents['children'] );

		$courses_map = ContentMapHandler::get_content_map()['courses'] ?? null;

		foreach ( $contents as $topic ) {
			$post_type         = $topic['post_type'] ?? 'post';
			$post_title        = $topic['post_title'] ?? $topic['ID'];
			$previous_topic_id = $topic['ID'];

			$topic = Helper::unset_post_data( $topic );
			$topic = $this->prepare_post_data( $topic );

			if ( is_wp_error( $topic ) ) {
				ErrorHandler::set_error( $post_type, $post_title );
				continue;
			}

			$topic_id = wp_insert_post( $topic, true, true );

			if ( is_wp_error( $topic_id ) ) {
				ErrorHandler::set_error( $post_type, $post_title );
				continue;
			}

			if ( $courses_map && $parent_id ) {
				if ( isset( $courses_map[ $parent_id ] ) ) {
					$map = $courses_map[ $parent_id ];
					$map[ tutor()->topics_post_type ][ $previous_topic_id ] = $topic_id;
					$courses_map[ $parent_id ]                              = $map;
				}
			}

			if ( $children && isset( $children[ $previous_topic_id ] ) ) {
				$child_contents = $children[ $previous_topic_id ];

				unset( $children[ $previous_topic_id ] );

				$child_contents = $this->add_post_parent( $child_contents, $topic_id );

				array_push( $course_contents, ...$child_contents );
			}
		}

		ContentMapHandler::update_content_map( 'courses', $courses_map );

		if ( $course_contents ) {
			$this->import_child_contents( $course_contents, $keep_media_files, $parent_id );
		}
	}

	/**
	 * Import child contents.
	 *
	 * @since 3.8.0
	 *
	 * @param array   $contents list of post contents.
	 * @param boolean $keep_media_files whether to keep media files.
	 * @param int     $parent_id the parent course id.
	 *
	 * @return void
	 */
	private function import_child_contents( $contents, $keep_media_files = false, $parent_id = 0 ) {
		foreach ( $contents as $key => $content ) {
			$post_type  = $content['post_type'] ?? 'post';
			$post_title = $content['post_title'] ?? $content['ID'];
			$content    = $this->prepare_post_data( $content );

			if ( is_wp_error( $content ) ) {
				ErrorHandler::set_error( $post_type, $post_title );
				continue;
			}

			switch ( $post_type ) {
				case tutor()->lesson_post_type:
				case ContentModel::LESSON_POST_TYPE:
					$content_id = $this->lesson_importer->insert_lesson( $content, $keep_media_files, $parent_id );
					if ( is_wp_error( $content_id ) ) {
						ErrorHandler::set_error( $post_type, $post_title );
					}
					break;
				case tutor()->assignment_post_type:
				case ContentModel::ASSIGNMENT_POST_TYPE:
					$content_id = $this->assignment_importer->insert_assignment( $content, $keep_media_files, $parent_id );
					if ( is_wp_error( $content_id ) ) {
						ErrorHandler::set_error( $post_type, $post_title );
					}
					break;

				case tutor()->quiz_post_type:
				case ContentModel::QUESTION_POST_TYPE:
					$content_id = $this->quiz_importer->insert_quiz( $content, false, $parent_id );
					if ( is_wp_error( $content_id ) ) {
						ErrorHandler::set_error( $post_type, $post_title );
					}
					break;
				default:
					break;
			}

			unset( $contents[ $key ] );
		}
	}

	/**
	 * Flatten an array with child content as value and key as parent id,
	 * replace old parent id with parent id from key.
	 *
	 * @since 3.6.0
	 *
	 * @param array $contents the array of contents to flatten.
	 * @param array $parent_id the array of parent ids to replace.
	 *
	 * @return array
	 */
	public function add_post_parent( $contents, $parent_id ) {
		$posts = array();
		foreach ( $contents as $content ) {
			$content['post_parent'] = $parent_id;
			$posts[]                = $content;
		}
		return $posts;
	}

	/**
	 * Inserts categories and tags if not exist in new site.
	 *
	 * @since 3.6.0
	 *
	 * @param array $taxonomies the array of taxonomies.
	 *
	 * @return bool|\WP_Error
	 */
	public function set_tutor_course_taxonomies( $taxonomies ) {
		$category_map = ContentMapHandler::get_content_map()['categories'] ?? array();
		$categories   = $taxonomies['categories'] ?? array();
		$tags         = $taxonomies['tags'] ?? array();

		if ( $categories ) {
			foreach ( $categories as $category ) {
				$previous_term_id = $category['term_id'];
				$new_term_id      = $category['term_id'];
				if ( ! term_exists( $category['name'] ) ) {

					if ( $category['parent'] ) {
						$category_list      = array_column( $categories, 'name', 'term_id' );
						$parent_term_name   = $category_list[ $category['parent'] ];
						$term               = get_term_by( 'name', $parent_term_name, CourseModel::COURSE_CATEGORY );
						$category['parent'] = $term ? $term->term_id : 0;
					}

					$response = wp_insert_term(
						$category['name'],
						CourseModel::COURSE_CATEGORY,
						array(
							'parent'      => $category['parent'],
							'description' => $category['description'],
							'slug'        => $category['slug'],
						)
					);

					if ( is_wp_error( $response ) ) {
						return $response;
					}

					$new_term_id = $response['term_id'];
				}

				if ( $category_map ) {
					$category_map[ $previous_term_id ] = $new_term_id;
					ContentMapHandler::update_content_map( 'categories', $category_map );
				} else {
					$category_map[ $previous_term_id ] = $new_term_id;
					ContentMapHandler::set_content_map( 'categories', $category_map );
				}
			}
		}

		if ( $tags ) {
			foreach ( $tags as $tag ) {
				if ( ! term_exists( $tag['name'] ) ) {
					$response = wp_insert_term(
						$tag['name'],
						CourseModel::COURSE_TAG,
						array(
							'parent'      => $tag['parent'],
							'description' => $tag['description'],
							'slug'        => $tag['slug'],
						)
					);

					if ( is_wp_error( $response ) ) {
						return $response;
					}
				}
			}
		}

		return true;
	}

	/**
	 * Prepare post data before insertion.
	 *
	 * @since 3.6.0
	 *
	 * @param array $post the post data to prepare.
	 *
	 * @return array|\WP_Error
	 */
	public function prepare_post_data( $post ) {

		$post = sanitize_post( $post, 'db' );

		$content_bank_post_type = array();

		if ( tutor_utils()->is_addon_enabled( 'content-bank' ) ) {
			$content_bank_post_type = array( self::TYPE_CONTENT_BANK, ...ContentModel::get_content_post_types() );

			if ( ! isset( $post['post_status'] ) ) {
				$post['post_status'] = 'publish';
			}
		}

		$rules = array(
			'post_title' => 'required',
			'post_type'  => 'required|match_string:' . implode( ',', array( ...array_values( get_tutor_post_types() ), ...$content_bank_post_type ) ),
		);

		$validate_content = ValidationHelper::validate( $rules, $post );

		if ( ! $validate_content->success ) {
			return new \WP_Error( 'invalid_post_data', __( 'Post data is invalid', 'tutor-pro' ), $validate_content->errors );
		}

		$post['post_author'] = get_current_user_id();
		return $post;
	}


	/**
	 * Import tutor course progress data.
	 *
	 * @since 3.8.0
	 *
	 * @param array $progress the course progress data.
	 *
	 * @return void
	 */
	public function import_tutor_course_progress( $progress ) {
		if ( ! $progress ) {
			return;
		}
		$quiz_attempts_and_answers = $progress['quiz_attempts_and_answers'] ?? array();
		$submitted_assignments     = $progress['submitted_assignments'] ?? array();
		$course_completion         = $progress['course_completion'] ?? array();
		$gradebook_results         = $progress['grade_book_results'] ?? array();

		if ( count( $quiz_attempts_and_answers ) ) {
			foreach ( $quiz_attempts_and_answers as $attempts ) {
				try {
					$this->user_data_importer->insert_quiz_attempts( $attempts );
				} catch ( \Throwable $th ) {
					ErrorHandler::set_error( __( 'Quiz Attempts', 'tutor-pro' ), $th->getMessage() );
				}
			}
		}

		if ( count( $submitted_assignments ) ) {
			foreach ( $submitted_assignments as $submitted_assignment ) {
				try {
					$this->user_data_importer->insert_assignment_submission( $submitted_assignment );
				} catch ( \Throwable $th ) {
					ErrorHandler::set_error( __( 'Assignment Submission', 'tutor-pro' ), $th->getMessage() );
				}
			}
		}

		if ( count( $course_completion ) ) {
			foreach ( $course_completion as $completion ) {
				try {
					$this->user_data_importer->insert_course_completion( $completion );
				} catch ( \Throwable $th ) {
					ErrorHandler::set_error( __( 'Course Completion', 'tutor-pro' ), $th->getMessage() );
				}
			}
		}

		if ( count( $gradebook_results ) && QueryHelper::table_exists( 'tutor_gradebooks_results' ) ) {
			try {
				$this->user_data_importer->insert_gradebook_result( $gradebook_results );
			} catch ( \Throwable $th ) {
				ErrorHandler::set_error( __( 'Gradebook Results', 'tutor-pro' ), $th->getMessage() );
			}
		}
	}


	/**
	 * Import tutor course review data.
	 *
	 * @since 3.8.0
	 *
	 * @param array $reviews the course review data.
	 *
	 * @return int|\WP_Error
	 */
	public function import_tutor_reviews( array $reviews ) {
		$review_id = $this->review_importer->insert_reviews( $reviews );

		return $review_id;
	}

	/**
	 * Import tutor orders.
	 *
	 * @since 3.8.0
	 *
	 * @param array $orders the array of order data.
	 *
	 * @return void|\WP_Error
	 */
	public function import_tutor_orders( array $orders ) {
		try {
			$this->order_importer->insert_order( $orders );
		} catch ( \Throwable $th ) {
			return new \WP_Error( 'tutor_order', $th->getMessage() );
		}
	}

	/**
	 * Import tutor subscription plans.
	 *
	 * @since 3.8.0
	 *
	 * @param array $plans the array of plan data.
	 *
	 * @return int|\WP_Error
	 */
	public function import_tutor_plan( array $plans ) {
		try {
			$plan_id = $this->order_importer->insert_plan( $plans );
		} catch ( \Throwable $th ) {
			return new \WP_Error( 'tutor_plan', $th->getMessage() );
		}
		return $plan_id;
	}

	/**
	 * Import tutor subscriptions.
	 *
	 * @since 3.8.0
	 *
	 * @param array $subscription the array of subscription data.
	 *
	 * @return void|\WP_Error
	 */
	public function import_tutor_subscriptions( array $subscription ) {
		try {
			$this->order_importer->insert_subscription( $subscription );
		} catch ( \Throwable $th ) {
			return new \WP_Error( 'tutor_subscription', $th->getMessage() );
		}
	}

	/**
	 * Import tutor enrollment data.
	 *
	 * @since 3.8.0
	 *
	 * @param array $enrollment_data the array of enrollment data.
	 *
	 * @return int|\WP_Error
	 */
	public function import_tutor_enrollments( array $enrollment_data ) {
		if ( is_array( $enrollment_data ) && count( $enrollment_data ) ) {
			$content_map = ContentMapHandler::get_content_map();

			$course_map = $content_map['courses'] ?? null;

			$enrolled_student_data = $enrollment_data['student'] ?? array();
			$enrolled_student_meta = $enrollment_data['student_meta'] ?? array();
			$enrollment            = $enrollment_data['enrollment'] ?? array();
			$meta                  = $enrollment_data['enrollment_meta'] ?? array();
			$course_id             = $enrollment['post_parent'] ?? 0;

			unset( $enrollment_data['student'] );
			unset( $enrollment_data['student_meta'] );
			unset( $enrollment_data['enrollment'] );
			unset( $enrollment_data['enrollment_meta'] );

			$user_id = $enrollment['post_author'];

			if ( $enrolled_student_data ) {
				$user_id = $this->user_data_importer->create_user( $enrolled_student_data, $enrolled_student_meta, $course_id );
			}

			if ( ! $enrollment && $enrolled_student_data ) {
				return 1;
			}

			if ( $course_id ) {
				$course_id                 = $course_map[ $course_id ]['course_id'] ?? $course_id;
				$enrollment['post_parent'] = $course_id;
				unset( $course_map );
			}

			$enrollment['post_author'] = $user_id;

			$enrollment = Helper::unset_post_data( $enrollment );

			$enrollment_id = wp_insert_post( $enrollment, true );

			if ( is_wp_error( $enrollment_id ) ) {
				return $enrollment_id;
			}

			if ( $meta ) {
				// Orders and Plans are processed after enrollments.
				$post_meta     = array_map( fn( $val ) => $val[0], $meta );
				$orders        = $content_map['orders'] ?? array();
				$subscriptions = $content_map['subscriptions'] ?? array();

				foreach ( $post_meta as $key => $value ) {

					if ( '_tutor_enrolled_by_order_id' === $key ) {
						if ( ! $orders ) {
							$orders['enrollments'] = array( $value => $enrollment_id );
							ContentMapHandler::set_content_map( 'orders', $orders );
						} else {
							$enrollments           = $orders['enrollments'] ?? array();
							$enrollments[ $value ] = $enrollment_id;
							$orders['enrollments'] = $enrollments;

							ContentMapHandler::update_content_map(
								'orders',
								$orders
							);

						}
					}

					if ( '_tutor_subscription_id' === $key ) {
						if ( ! $subscriptions ) {
							$subscriptions['enrollments'] = array( $value => $enrollment_id );
							ContentMapHandler::set_content_map( 'subscriptions', $subscriptions );
						} else {
							$enrollments                  = $subscriptions['enrollments'] ?? array();
							$enrollments[ $value ]        = $enrollment_id;
							$subscriptions['enrollments'] = $enrollments;

							ContentMapHandler::update_content_map(
								'subscriptions',
								$subscriptions
							);

						}
					}
				}
			}

			unset( $content_map );

			return $enrollment_id;
		}
	}
}

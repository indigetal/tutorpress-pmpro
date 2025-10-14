<?php
/**
 * Backend Course Bundle Listing
 *
 * @package TutorPro\CourseBundle
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 2.2.0
 */

namespace TutorPro\CourseBundle\Backend;

use TUTOR\Backend_Page_Trait;
use TUTOR\Input;
use Tutor\Models\CourseModel;
use TutorPro\CourseBundle\CustomPosts\CourseBundle;
use TutorPro\CourseBundle\Models\BundleModel;
use TutorPro\CourseBundle\Utils;

/**
 * BundleList Class.
 *
 * @since 2.2.0
 */
class BundleList {

	use Backend_Page_Trait;

	/**
	 * Register hooks.
	 *
	 * @since 2.2.0
	 *
	 * @param bool $register_hooks register hooks.
	 *
	 * @return void|null
	 */
	public function __construct( $register_hooks = true ) {
		if ( ! $register_hooks ) {
			return;
		}

		add_filter( 'tutor_admin_course_list', array( $this, 'add_bundle_list' ), 10, 4 );
		add_filter( 'tutor_course_list_meta', array( $this, 'add_bundle_meta' ), 10, 2 );
		add_filter( 'tutor_course_list_course_edit_link', array( $this, 'add_bundle_edit_link' ), 10, 2 );
		add_filter( 'tutor_course_list_before_filter_items', array( $this, 'add_bundle_filter' ) );
		add_action( 'tutor_data_list_navbar_button', array( $this, 'create_bundle_button' ) );

		add_action( 'trashed_post', array( $this, 'redirect_to_bundle_list_page' ) );

		add_action( 'save_post_' . CourseModel::POST_TYPE, array( $this, 'assign_category_to_bundle' ), 100 );
		add_action( 'save_post_' . CourseBundle::POST_TYPE, array( $this, 'assign_bundle_category' ), 100 );

		add_filter( 'tutor_course_mini_info', array( $this, 'add_total_courses_number' ), 10, 2 );
		add_filter( 'tutor_course_card_data', array( $this, 'add_bundle_course_duration' ), 10, 2 );
	}

	/**
	 * Add post type filter for course and bundles in course list.
	 *
	 * @since 3.5.0
	 *
	 * @param array $filters existing filters.
	 *
	 * @return array
	 */
	public function add_bundle_filter( $filters ) {
		if ( is_admin() && 'tutor' === Input::get( 'page', '' ) ) {
			$bundle_filter = array(
				'label'      => __( 'Type', 'tutor-pro' ),
				'field_type' => 'select',
				'field_name' => 'post-type',
				'options'    => array(
					array(
						'key'   => '',
						'title' => __( 'Courses & Bundles', 'tutor-pro' ),
					),
					array(
						'key'   => 'courses',
						'title' => __( 'Courses', 'tutor-pro' ),
					),
					array(
						'key'   => 'course-bundle',
						'title' => __( 'Bundles', 'tutor-pro' ),
					),
				),
				'value'      => Input::get( 'post-type', '' ),
			);

			return array_merge( array( $bundle_filter ), $filters );
		}
	}

	/**
	 * Add Create Bundle button on course list.
	 *
	 * @since 3.5.0
	 *
	 * @return void
	 */
	public function create_bundle_button() {
		if ( is_admin() && 'tutor' === Input::get( 'page', '' ) ) {
			?>
			<a href="#" class="tutor-btn tutor-btn-outline-primary tutor-d-flex tutor-align-center tutor-gap-1 tutor-add-new-course-bundle">
				<i class="tutor-icon-bundle"></i>
				<span><?php esc_html_e( 'New Bundle', 'tutor-pro' ); ?></span>
			</a>
			<?php
		}
	}

	/**
	 * Set course bundle edit link.
	 *
	 * @since 3.5.0
	 *
	 * @param string   $edit_link the course edit link to update.
	 * @param \WP_POST $post the post object.
	 *
	 * @return string
	 */
	public function add_bundle_edit_link( $edit_link, $post ) {
		if ( ! isset( $post ) || CourseBundle::POST_TYPE !== $post->post_type ) {
			return $edit_link;
		}

		$edit_link = Utils::construct_page_url( 'edit', $post->ID );
		return $edit_link;
	}

	/**
	 * Filter meta value to show bundle info.
	 *
	 * @since 3.5.0
	 *
	 * @param string   $content the meta content to display.
	 * @param \WP_POST $post the post object.
	 *
	 * @return string
	 */
	public function add_bundle_meta( $content, $post ) {
		if ( CourseBundle::POST_TYPE === $post->post_type ) {
			$total_courses = count( BundleModel::get_bundle_course_ids( $post->ID ) );
			$content       = '<div class="tutor-bundle-list-meta">' . esc_html( $total_courses . ' ' . _n( 'Course', 'Courses', $total_courses, 'tutor-pro' ) ) . '</div>';
		}
		return $content;
	}

	/**
	 * Filter backend course list to add bundles.
	 *
	 * @since 3.5.0
	 *
	 * @param array  $args arguments for querying courses.
	 * @param int    $user_id the user id.
	 * @param string $status the post status.
	 * @param bool   $all_post_types should keep all post types.
	 *
	 * @return array
	 */
	public function add_bundle_list( $args, $user_id, $status, $all_post_types ) {
		$post_type = Input::get( 'post-type', '' );

		if ( tutor()->course_post_type === $post_type ) {
			return $args;
		}

		if ( isset( $args['post_type'] ) ) {
			if ( ! $all_post_types && CourseBundle::POST_TYPE === $post_type ) {
				$args['post_type'] = CourseBundle::POST_TYPE;
			} else {
				$args['post_type'] = array( $args['post_type'], CourseBundle::POST_TYPE );
			}
		}

		return $args;
	}

	/**
	 * On course update, assign course category to bundle category
	 *
	 * @since 2.6.0
	 *
	 * @param int $post_id post id.
	 *
	 * @return void
	 */
	public function assign_category_to_bundle( $post_id ) {
		if ( CourseModel::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$bundle_ids = BundleModel::get_bundle_ids_by_course( $post_id );
		if ( empty( $bundle_ids ) ) {
			return;
		}

		foreach ( $bundle_ids as $bundle_id ) {
			$this->assign_bundle_category( $bundle_id );
		}
	}

	/**
	 * Assign bundle category.
	 *
	 * @param int $post_id post id.
	 *
	 * @return void
	 */
	public static function assign_bundle_category( $post_id ) {
		if ( CourseBundle::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		$categories = BundleModel::get_bundle_course_categories( $post_id );
		$cat_ids    = array_column( $categories, 'term_id' );

		wp_set_post_terms( $post_id, $cat_ids, CourseModel::COURSE_CATEGORY );
	}

	/**
	 * After trash a bundle direct to the bundle list page
	 *
	 * @since 2.2.4
	 *
	 * @param integer $post_id int bundle id.
	 *
	 * @return void
	 */
	public static function redirect_to_bundle_list_page( int $post_id ): void {
		$post = get_post( $post_id );
		if ( CourseBundle::POST_TYPE === $post->post_type ) {
			$is_gutenberg_enabled = tutor_utils()->get_option( 'enable_gutenberg_course_edit' );
			if ( ! $is_gutenberg_enabled ) {
				wp_safe_redirect( admin_url( 'admin.php?page=tutor' ) );
				exit;
			}
		}
	}

	/**
	 * Get bundle delete restriction message.
	 *
	 * @since 2.2.0
	 *
	 * @return string
	 */
	public static function get_delete_restriction_message() {
		return __( 'This bundle has enrolled student. It can not be deleted', 'tutor-pro' );
	}


	/**
	 * Update bundle status
	 *
	 * @param string $status for updating bundle status.
	 * @param string $bulk_ids comma separated ids.
	 *
	 * @return bool
	 *
	 * @since 2.0.0
	 */
	public static function update_bundle_status( string $status, $bulk_ids ): bool {
		global $wpdb;
		$post_table = $wpdb->posts;
		$status     = sanitize_text_field( $status );
		$bulk_ids   = sanitize_text_field( $bulk_ids );

		$update = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$post_table} SET post_status = %s WHERE ID IN ($bulk_ids)", //phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$status
			)
		);

		return true;
	}

	/**
	 * Add total course to mini info
	 *
	 * @since 3.8.0
	 *
	 * @param array  $info info.
	 * @param object $post post data.
	 *
	 * @return array
	 */
	public static function add_total_courses_number( $info, $post ) {
		if ( tutor()->bundle_post_type === $post->post_type ) {
			$info['total_course'] = count( BundleModel::get_bundle_course_ids( $post->ID ) );
		}

		return $info;
	}

	/**
	 * Add course duration to card data
	 *
	 * @since 3.8.0
	 *
	 * @param array  $info info.
	 * @param object $post post data.
	 *
	 * @return array
	 */
	public static function add_bundle_course_duration( $info, $post ) {
		if ( tutor()->bundle_post_type === $post->post_type ) {
			$course_ids              = BundleModel::get_bundle_course_ids( $post->ID );
			$bundle_duration         = BundleModel::convert_seconds_into_human_readable_time( BundleModel::get_bundle_duration( $course_ids ), false );
			$info['course_duration'] = $bundle_duration;
		}

		return $info;
	}
}

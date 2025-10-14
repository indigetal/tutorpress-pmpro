<?php
/**
 * Bundle Details Page Logic Handler.
 *
 * @package TutorPro\CourseBundle
 * @subpackage Frontend
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 2.2.0
 */

namespace TutorPro\CourseBundle\Frontend;

use TutorPro\CourseBundle\CustomPosts\CourseBundle;
use TutorPro\CourseBundle\CustomPosts\ManagePostMeta;
use TutorPro\CourseBundle\Models\BundleModel;
use TutorPro\CourseBundle\Utils;

/**
 * Bundle Details Class
 *
 * @since 2.2.0
 */
class BundleDetails {

	/**
	 * Register hooks.
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'template_include', array( $this, 'load_single_bundle_template' ), 99 );
		add_filter( 'tutor_course_about_title', array( $this, 'add_bundle_title' ) );
		add_filter( 'tutor/course/single/sidebar/metadata', array( $this, 'add_bundle_metadata' ), 11, 2 );
		add_filter( 'tutor_course_single_tags', array( $this, 'bundle_tags' ), 10, 2 );
		add_action( 'tutor_after_course_details_wc_cart_price', array( $this, 'add_discount_info' ), 10, 2 );
		add_action( 'tutor_after_course_details_tutor_cart_price', array( $this, 'add_bundle_discount_info' ), 10, 1 );
		add_filter( 'tutor/course/single/entry-box/is_enrolled', array( $this, 'content_for_enrolled_user' ), 10, 2 );

		/**
		 * Bypass pre-requisites for bundle courses.
		 *
		 * @since 2.2.0
		 */
		add_filter( 'tutor_pro_show_prerequisites_courses', array( $this, 'bypass_course_prerequisites' ), 10, 3 );
		add_filter( 'tutor_pro_prerequisites_redirect', array( $this, 'bypass_course_prerequisites' ), 10, 3 );

		/**
		 * Bypass course expiry
		 *
		 * @since 2.2.0
		 */
		add_filter( 'tutor_pro_check_course_expiry', array( $this, 'bypass_course_expiry' ), 10, 2 );
		add_filter( 'tutor_pro_show_course_expire_info', array( $this, 'bypass_course_expiry' ), 10, 2 );

		add_action( 'tutor_cart_item_badge', array( $this, 'add_cart_item_bundle_badge' ) );
	}

	/**
	 * Bypass course expire for bundle course.
	 *
	 * @since 2.2.0
	 *
	 * @param bool $bool true or false.
	 * @param int  $course_id course id.
	 *
	 * @return bool
	 */
	public function bypass_course_expiry( $bool, $course_id ) {
		$user_id = get_current_user_id();
		// If user not logged in.
		if ( ! $user_id ) {
			return $bool;
		}

		// Find which bundle has this course.
		$bundle_id = BundleModel::get_bundle_id_by_course( $course_id );
		if ( ! $bundle_id ) {
			return $bool;
		}

		$course_enrolled = tutor_utils()->is_enrolled( $course_id, $user_id );
		$bundle_enrolled = tutor_utils()->is_enrolled( $bundle_id, $user_id );

		if ( $course_enrolled && $bundle_enrolled ) {
			/**
			 * Course expire will not work for who are enrolled with bundle.
			 *
			 * @since 2.2.0
			 */
			return false;
		}

		return $bool;
	}

	/**
	 * Bypass course pre-requisites for bundle courses.
	 *
	 * @since 2.2.0
	 *
	 * @param bool  $bool bool value.
	 * @param int   $course_id post id.
	 * @param array $prerequisite_course_ids course ids.
	 *
	 * @return bool
	 */
	/**
	 * Bypass course pre-requisites for bundle courses.
	 *
	 * @since 2.2.0
	 *
	 * @param bool  $bool bool value.
	 * @param int   $course_id post id.
	 * @param array $prerequisite_course_ids course ids.
	 *
	 * @return bool
	 */
	public function bypass_course_prerequisites( $bool, $course_id, $prerequisite_course_ids ) {
		$user_id = get_current_user_id();
		// If user not logged in.
		if ( ! $user_id ) {
			return $bool;
		}

		// Get bundles that has this course.
		$bundles = BundleModel::get_bundle_id_by_course( $course_id, false );
		if ( ! $bundles ) {
			return $bool;
		}

		if ( tutor_utils()->count( $bundles ) ) {
			foreach ( $bundles as $bundle ) {
				$bundle_id       = (int) $bundle->post_id;
				$course_enrolled = tutor_utils()->is_enrolled( $course_id, $user_id );
				$bundle_enrolled = tutor_utils()->is_enrolled( $bundle_id, $user_id );

				if ( $course_enrolled && $bundle_enrolled ) {
					$bundle_course_ids = BundleModel::get_bundle_course_ids( $bundle_id );

					$bool = false;
					// If the prerequisite course ids belong to bundle then ask user to complete
					// prerequire course first.
					$prerequisite_ids_in_bundle = array_intersect( $prerequisite_course_ids, $bundle_course_ids );
					if ( ! empty( $prerequisite_ids_in_bundle ) ) {
						foreach ( $prerequisite_ids_in_bundle as $course_id ) {
							if ( tutor_utils()->is_completed_course( $course_id, $user_id ) ) {
								$bool = false;
							} else {
								$bool = true;
							}
						}
					}
				}
			}
		}

		return $bool;
	}

	/**
	 * Show entry box content for enrolled user.
	 *
	 * @since 2.2.0
	 *
	 * @param string $content HTML content.
	 * @param int    $post_id post id.
	 *
	 * @return string
	 */
	public function content_for_enrolled_user( $content, $post_id ) {
		$post_type = get_post_type( $post_id );
		if ( CourseBundle::POST_TYPE !== $post_type ) {
			return $content;
		}

		ob_start();
		$link       = '#';
		$has_access = tutor_utils()->has_user_course_content_access( get_current_user_id(), $post_id );
		if ( tutor_utils()->is_enrolled( $post_id ) || $has_access ) {
			$link = get_the_permalink( $post_id ) . '#tutor-bundle-course-list';
		}
		?>
		<div>
			<a href="<?php echo esc_url( $link ); ?>" class="tutor-btn tutor-btn-outline-primary tutor-btn-block">
				<?php esc_html_e( 'Explore Courses', 'tutor-pro' ); ?>
			</a>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Add discount ribbon info.
	 *
	 * @since 2.2.0
	 *
	 * @param object $product wc product.
	 * @param int    $bundle_id bundle id.
	 *
	 * @return void
	 */
	public function add_discount_info( $product, $bundle_id ) {
		$post_type = get_post_type( $bundle_id );
		if ( CourseBundle::POST_TYPE !== $post_type ) {
			return;
		}

		$ribbon_type = ManagePostMeta::get_ribbon_type( $bundle_id );
		if ( BundleModel::RIBBON_NONE === $ribbon_type ) {
			return;
		}

		$discount          = BundleModel::get_bundle_discount_by_ribbon( $bundle_id, $ribbon_type );
		$bundle_sale_price = BundleModel::get_bundle_sale_price( $bundle_id );

		if ( $bundle_sale_price <= 0 ) {
			return;
		}
		?>
			<div class="tutor-d-flex tutor-align-center tutor-gap-1">
				<?php
					/* translators: %s: discount value */
					echo esc_html( sprintf( __( '%s off', 'tutor-pro' ), $discount ) );
				?>
			</div>
		<?php
	}


	/**
	 * Add discount ribbon info for tutor course bundle.
	 *
	 * @since 3.2.0
	 *
	 * @param int $bundle_id bundle id.
	 *
	 * @return void
	 */
	public function add_bundle_discount_info( $bundle_id ) {
		$this->add_discount_info( null, $bundle_id );
	}

	/**
	 * Get all tags  for the bundle courses.
	 *
	 * @since 2.2.0
	 *
	 * @param array $tags tags.
	 * @param int   $bundle_id bundle id.
	 *
	 * @return array
	 */
	public function bundle_tags( $tags, $bundle_id ) {
		$post_type = get_post_type( $bundle_id );
		if ( CourseBundle::POST_TYPE !== $post_type ) {
			return $tags;
		}

		$courses = BundleModel::get_bundle_course_ids( $bundle_id );
		if ( 0 === count( $courses ) ) {
			return $tags;
		}

		$tags = wp_get_object_terms( $courses, 'course-tag', array( 'ids' ) );

		return $tags;
	}

	/**
	 * Add bundle meta data.
	 *
	 * @since 2.2.0
	 *
	 * @param array $metadata meta data.
	 * @param int   $bundle_id bundle id.
	 *
	 * @return array
	 */
	public function add_bundle_metadata( $metadata, $bundle_id ) {
		$post_type = get_post_type( $bundle_id );
		if ( CourseBundle::POST_TYPE !== $post_type ) {
			return $metadata;
		}

		$overview       = BundleModel::get_bundle_meta( $bundle_id );
		$total_enrolled = BundleModel::get_total_bundle_sold( $bundle_id );
		$total_course   = BundleModel::get_total_courses_in_bundle( $bundle_id );

		//phpcs:disable WordPress.WP.I18n.MissingTranslatorsComment
		return array(
			array(
				'icon_class' => 'tutor-icon-book-open-o',
				'label'      => __( 'Total Courses', 'tutor-pro' ),
				'value'      => sprintf( __( '%s Total Courses', 'tutor-pro' ), $total_course ),
			),
			array(
				'icon_class' => 'tutor-icon-mortarboard',
				'label'      => __( 'Total Enrolled', 'tutor-pro' ),
				'value'      => get_tutor_option( 'enable_course_total_enrolled' ) ? sprintf( __( '%s Total Enrolled', 'tutor-pro' ), $total_enrolled ) : null,
			),
			array(
				'icon_class' => 'tutor-icon-clock-line',
				'label'      => __( 'Duration', 'tutor-pro' ),
				'value'      => get_tutor_option( 'enable_course_duration' ) ? sprintf( __( '%s Duration', 'tutor-pro' ), $overview['total_duration'] ?? 0 ) : null,
			),
			array(
				'icon_class' => 'tutor-icon-video-camera-o',
				'label'      => __( 'Lesson Content', 'tutor-pro' ),
				'value'      => sprintf( __( '%s Lesson Content', 'tutor-pro' ), $overview['total_video_contents'] ?? 0 ),
			),
			array(
				'icon_class' => 'tutor-icon-download',
				'label'      => __( 'Downloadable Resources', 'tutor-pro' ),
				'value'      => sprintf( __( '%s Downloadable Resources', 'tutor-pro' ), $overview['total_resources'] ?? 0 ),
			),
			array(
				'icon_class' => 'tutor-icon-circle-question-mark',
				'label'      => __( 'Quiz Papers', 'tutor-pro' ),
				'value'      => sprintf( __( '%s Quiz Papers', 'tutor-pro' ), $overview['total_quizzes'] ?? 0 ),
			),
		);
		//phpcs:enable WordPress.WP.I18n.MissingTranslatorsComment
	}

	/**
	 * Add bundle title.
	 *
	 * @since 2.2.0
	 *
	 * @param string $title title.
	 *
	 * @return string
	 */
	public function add_bundle_title( $title ) {
		if ( Utils::is_bundle_single_page() ) {
			return __( 'About Bundle', 'tutor-pro' );
		}

		return $title;
	}

	/**
	 * Load single bundle details template.
	 *
	 * @since 2.2.0
	 *
	 * @param string $template template.
	 *
	 * @return string
	 */
	public function load_single_bundle_template( $template ) {
		if ( Utils::is_bundle_single_page() ) {
			do_action( 'single_bundle_template_before_load', get_the_ID() );
			wp_reset_postdata();
			return Utils::template_path( 'single-course-bundle.php' );
		}

		return $template;
	}

	/**
	 * Add bundle badge to checkout course item
	 *
	 * @since 3.8.0
	 *
	 * @param object $course The course.
	 *
	 * @return void
	 */
	public function add_cart_item_bundle_badge( $course ) {
		if ( 'course-bundle' !== $course->post_type ) {
			return;
		}
		?>
		<span class="tutor-checkout-course-bundle-badge">
			<?php
				$bundle_course_ids = BundleModel::get_bundle_course_ids( $course->ID );
				// translators: %d: Number of courses in the cart.
				echo esc_html( sprintf( __( '%d Course bundle', 'tutor-pro' ), count( $bundle_course_ids ) ) );
			?>
		</span>
		<?php
	}
}

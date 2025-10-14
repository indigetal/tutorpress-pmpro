<?php
/**
 * Handle Course Coming Soon
 *
 * @package TutorPro\Classes
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

namespace TUTOR_PRO;

use TUTOR\Input;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Course Coming Soon
 */
class CourseComingSoon {

	const COURSE_COMING_SOON_META              = '_tutor_course_enable_coming_soon';
	const COURSE_COMING_SOON_THUMBNAIL_ID_META = '_tutor_course_coming_soon_thumbnail_id';
	const COURSE_CURRICULUM_PREVIEW_META       = '_tutor_course_enable_curriculum_preview';
	/**
	 * Register hooks.
	 */
	public function __construct() {
		/**
		 * Coming soon hooks
		 *
		 * @since 3.3.0
		 */
		add_filter( 'tutor_course_thumb_url', array( $this, 'set_coming_soon_thumbnail' ), 10, 4 );
		add_filter( 'tutor_get_course_topics', array( $this, 'show_course_curriculum' ) );
		add_filter( 'tutor_course_filter_args', array( $this, 'show_filtered_coming_soon_courses' ) );
		add_filter( 'tutor/course/single/entry-box/free', array( $this, 'remove_coming_soon_add_to_cart' ), 10, 2 );
		add_filter( 'tutor_add_to_cart_btn', array( $this, 'remove_coming_soon_add_to_cart' ), 10, 2 );
		add_filter( 'tutor_pro_subscription_enrollment', array( $this, 'remove_coming_soon_add_to_cart' ), 10, 2 );
		add_filter( 'tutor_course_loop_add_to_cart_button', array( $this, 'remove_coming_soon_add_to_cart' ), 10, 2 );
		add_filter( 'tutor_course_restrict_new_entry', array( $this, 'set_course_coming_soon_entry_button' ), 10, 2 );
		add_filter( 'tutor_course_details_response', array( $this, 'set_course_coming_soon_details_response' ) );
		add_filter( 'tutor_course/loop/start/button', array( $this, 'remove_coming_soon_add_to_cart' ), 10, 2 );
		add_filter( 'tutor_limit_course_archive_list_filter', array( $this, 'set_course_coming_soon_archive_query' ) );
		add_filter( 'tutor_course_lead_info_args', array( $this, 'set_coming_soon_lead_info_args' ) );
		add_filter( 'tutor/course/single/entry-box/is_public', array( $this, 'set_course_coming_soon_entry_button'), 10, 2 );
		add_filter( 'tutor/course/single/entry-box/is_enrolled', array( $this, 'set_course_coming_soon_entry_button'), 10, 2 );
		add_filter( 'tutor_course_loop_price', array( $this, 'restrict_public_course' ), 10, 2);
		add_filter( 'tutor_course_has_video', array( $this, 'replace_intro_video' ), 10, 2 );

		add_action( 'future_to_publish', array( $this, 'handle_schedule_post_published' ) );
		add_action( 'pre_get_posts', array( $this, 'get_coming_soon_details' ) );
		add_action( 'tutor_course/single/entry/after', array( $this, 'set_entry_box_coming_soon_button' ), 8 );
		add_action( 'tutor_course_loop_footer_bottom', array( $this, 'show_course_coming_soon' ) );
		add_action( 'tutor_save_course_after', array( $this, 'set_course_coming_soon_meta' ), 10, 2 );
		add_action( 'tutor_after_prepare_update_post_meta', array( $this, 'prepare_course_coming_soon_update_post_meta' ), 10, 2 );
	}

	/**
	 * Restrict public course entry in course list when coming soon.
	 *
	 * @since 3.3.0
	 *
	 * @param string $content   the course card footer content.
	 * @param int    $course_id the course id.
	 *
	 * @return string
	 */
	public function restrict_public_course( $content, $course_id ) {
		$course_coming_soon = (bool) get_post_meta( $course_id, self::COURSE_COMING_SOON_META, true );
		$is_public_course   = 'yes' === get_post_meta( $course_id, '_tutor_is_public_course', true );
		if ( $course_coming_soon && $is_public_course ) {
			$content = '';
		}
		return $content;
	}

	/**
	 * Replace course details intro video for coming soon
	 *
	 * @since 3.3.0
	 *
	 * @param array|bool $has_video check if video exists for course.
	 *
	 * @return array|bool;
	 */
	public function replace_intro_video( $has_video, $course_id ) {
		$course_coming_soon       = (bool) get_post_meta( $course_id, self::COURSE_COMING_SOON_META, true );
		$coming_soon_thumbnail_id = (int) get_post_meta( $course_id, self::COURSE_COMING_SOON_THUMBNAIL_ID_META, true );
		if ( $course_coming_soon && $coming_soon_thumbnail_id ) {
			$has_video = false;
		}
		return $has_video;
	}

	/**
	 * Filter course lead info args for coming soon.
	 *
	 * @since 3.3.0
	 *
	 * @param array $args course lead info query args.
	 *
	 * @return array
	 */
	public function set_coming_soon_lead_info_args( $args ) {
		$args['post_status'] = array( 'publish', 'future' );
		return $args;
	}

	/**
	 * Meta query for getting coming soon courses only.
	 *
	 * @since 3.3.0
	 *
	 * @return array
	 */
	private function coming_soon_meta_query() {
		$meta_query = array(
			'relation' => 'OR',
			array(
				'key'     => self::COURSE_COMING_SOON_META,
				'value'   => '1',
				'compare' => '=',
			),
			array(
				'key'     => self::COURSE_COMING_SOON_META,
				'compare' => 'NOT EXISTS',
			),
		);
		return $meta_query;
	}

	/**
	 * Set course archive list query object for coming soon.
	 *
	 * @since 3.3.0
	 *
	 * @param \WP_Query $query the query object.
	 *
	 * @return \WP_Query
	 */
	public function set_course_coming_soon_archive_query( $query ) {
		$query->set( 'post_status', array( 'publish', 'future' ) );
		// Only show coming soon and published courses.
		$query->set(
			'meta_query',
			$this->coming_soon_meta_query()
		);

		return $query;
	}

	/**
	 * Extend course details for coming soon.
	 *
	 * @since 3.3.0
	 *
	 * @param array $data the course details.
	 *
	 * @return array
	 */
	public function set_course_coming_soon_details_response( $data ) {
		$course_id = Input::sanitize( $data['ID'], 0 );

		$data['enable_coming_soon'] = get_post_meta( $course_id, self::COURSE_COMING_SOON_META, true );
		$is_coming_soon             = (bool) $data['enable_coming_soon'];
		if ( $is_coming_soon ) {
			$data['coming_soon_thumbnail']     = wp_get_attachment_image_url( get_post_meta( $course_id, self::COURSE_COMING_SOON_THUMBNAIL_ID_META, true ), 'post-thumbnail' );
			$data['coming_soon_thumbnail_id']  = get_post_meta( $course_id, self::COURSE_COMING_SOON_THUMBNAIL_ID_META, true );
			$data['enable_curriculum_preview'] = get_post_meta( $course_id, self::COURSE_CURRICULUM_PREVIEW_META, true );
		}

		return $data;
	}

	/**
	 * Prevent course entry when coming soon.
	 *
	 * @since 3.3.0
	 *
	 * @param string $content the content to replace.
	 * @param int    $course_id the course id.
	 *
	 * @return string
	 */
	public function set_course_coming_soon_entry_button( $content, $course_id ) {
		$course_coming_soon = (bool) get_post_meta( $course_id, self::COURSE_COMING_SOON_META, true );

		if ( $course_coming_soon ) {
			$content = '';
		}

		return $content;
	}

	/**
	 * Save course coming soon meta when save post.
	 *
	 * @since 3.3.0
	 *
	 * @param int      $post_ID the post id.
	 * @param \WP_Post $course the tutor course.
	 *
	 * @return void
	 */
	public function set_course_coming_soon_meta( $post_ID, $course ) {
		$enable_coming_soon = Input::post( 'enable_coming_soon' );
		if ( $enable_coming_soon ) {
			update_post_meta( $post_ID, self::COURSE_COMING_SOON_META, $enable_coming_soon );
		} elseif ( ! tutor_is_rest() ) {
			delete_post_meta( $post_ID, self::COURSE_COMING_SOON_META );
		}

		$coming_soon_thumbnail_id = Input::post( 'coming_soon_thumbnail_id' );
		if ( $coming_soon_thumbnail_id ) {
			update_post_meta( $post_ID, self::COURSE_COMING_SOON_THUMBNAIL_ID_META, $coming_soon_thumbnail_id );
		} elseif ( ! tutor_is_rest() ) {
			delete_post_meta( $post_ID, self::COURSE_COMING_SOON_THUMBNAIL_ID_META );
		}

		$enable_curriculum_preview = Input::post( 'enable_curriculum_preview' );
		if ( $enable_curriculum_preview ) {
			update_post_meta( $post_ID, self::COURSE_CURRICULUM_PREVIEW_META, $enable_curriculum_preview );
		} elseif ( ! tutor_is_rest() ) {
			delete_post_meta( $post_ID, self::COURSE_CURRICULUM_PREVIEW_META );
		}
	}

	/**
	 * Prepare coming soon course meta on course update.
	 *
	 * @since 3.3.0
	 *
	 * @param int   $post_id the post id.
	 * @param array $params  the post parameters passed.
	 *
	 * @return void
	 */
	public function prepare_course_coming_soon_update_post_meta( $post_id, $params ) {
		if ( isset( $params['enable_coming_soon'] ) ) {
			update_post_meta( $post_id, self::COURSE_COMING_SOON_META, $params['enable_coming_soon'] );
		}

		if ( isset( $params['coming_soon_thumbnail_id'] ) ) {
			update_post_meta( $post_id, self::COURSE_COMING_SOON_THUMBNAIL_ID_META, $params['coming_soon_thumbnail_id'] );
		}

		if ( isset( $params['enable_curriculum_preview'] ) ) {
			update_post_meta( $post_id, self::COURSE_CURRICULUM_PREVIEW_META, $params['enable_curriculum_preview'] );
		}
	}

	/**
	 * Remove add to cart button when course coming soon.
	 *
	 * @since 3.3.0
	 *
	 * @param string $button the button content.
	 * @param int    $course_id the course id.
	 *
	 * @return string
	 */
	public function remove_coming_soon_add_to_cart( $button, $course_id ) {
		$course_coming_soon = (bool) get_post_meta( $course_id, self::COURSE_COMING_SOON_META, true );

		if ( $course_coming_soon ) {
			$button = '';
		}

		return $button;
	}

	/**
	 * Show coming soon on course list.
	 *
	 * @since 3.3.0
	 *
	 * @param int $course_id the course id.
	 *
	 * @return void
	 */
	public function show_course_coming_soon( $course_id ) {
		$course_coming_soon = (bool) get_post_meta( $course_id, self::COURSE_COMING_SOON_META, true );
		$content            = '';

		if ( $course_coming_soon ) {
			ob_start();
			tutor_load_template( 'coming-soon', array( 'course' => get_post( $course_id ) ), true );
			$content = ob_get_clean();
		}
		echo wp_kses_post( $content );
	}

	/**
	 * Set coming soon for single course entry box.
	 *
	 * @since 3.3.0
	 *
	 * @param int $course_id the course id.
	 *
	 * @return void
	 */
	public function set_entry_box_coming_soon_button( $course_id ) {
		$course_coming_soon = (bool) get_post_meta( $course_id, self::COURSE_COMING_SOON_META, true );
		$content            = '';

		if ( $course_coming_soon ) {
			ob_start();
			tutor_load_template(
				'coming-soon',
				array(
					'course'       => get_post( $course_id ),
					'is_entry_box' => true,
				),
				true
			);
			$content = ob_get_clean();
		}
		echo wp_kses_post( $content );
	}

	/**
	 * Show coming soon course for course list filter.
	 *
	 * @since 3.3.0
	 *
	 * @param array $args array of filter arguments.
	 *
	 * @return array
	 */
	public function show_filtered_coming_soon_courses( $args ) {
		if ( isset( $args['post_status'] ) ) {
			$args['post_status'] = array( 'publish', 'future' );
		}

		if ( isset( $args['meta_query'] ) ) {
			array_push(
				$args['meta_query'],
				$this->coming_soon_meta_query()
			);
		} else {
			$args['meta_query'] = $this->coming_soon_meta_query();
		}

		return $args;
	}


	/**
	 * Show course curriculum on coming soon courses.
	 *
	 * @since 3.3.0
	 *
	 * @param \WP_Query $topics the topics of the course.
	 *
	 * @return \WP_Query
	 */
	public function show_course_curriculum( $topics ) {
		$course_id          = get_the_ID();
		$course_coming_soon = (bool) get_post_meta( $course_id, self::COURSE_COMING_SOON_META, true );
		$course_curriculum  = (bool) get_post_meta( $course_id, self::COURSE_CURRICULUM_PREVIEW_META, true );

		if ( $course_coming_soon && ! $course_curriculum ) {
			return new \WP_Query();
		}

		return $topics;
	}


	/**
	 * Show coming soon course details for students.
	 *
	 * @since 3.3.0
	 *
	 * @param \WP_Query $query the query object.
	 *
	 * @return void
	 */
	public function get_coming_soon_details( $query ) {

		$post_type   = Input::get( 'post_type' );
		$post_id     = Input::get( 'p' );
		$course_type = isset( $post_type ) && in_array( $post_type, array( tutor()->course_post_type ), true );
		$coming_soon = isset( $post_id ) && (bool) get_post_meta( $post_id, self::COURSE_COMING_SOON_META, true );

		if ( $coming_soon && $course_type ) {
			$query->set( 'post_status', array( 'publish', 'future' ) );
		}
	}

	/**
	 * Set coming soon thumbnail if provided and replace course thumbnail.
	 *
	 * @since 3.3.0
	 *
	 * @param int    $thumb_url         the thumbnail url.
	 * @param int    $post_id           the post id.
	 * @param string $size              the thumbnail size.
	 * @param int    $post_thumbnail_id the feature image of course.
	 *
	 * @return int
	 */
	public function set_coming_soon_thumbnail( $thumb_url, $post_id, $size, $post_thumbnail_id ) {

		$enable_coming_soon       = (bool) get_post_meta( $post_id, self::COURSE_COMING_SOON_META, true );
		$coming_soon_thumbnail_id = (int) get_post_meta( $post_id, self::COURSE_COMING_SOON_THUMBNAIL_ID_META, true );
		$placeholder_url          = tutor_pro()->url . 'assets/images/coming-soon.svg';

		if ( $enable_coming_soon && $coming_soon_thumbnail_id ) {
			$thumb_url = wp_get_attachment_image_url( $coming_soon_thumbnail_id, $size );
		} elseif ( $enable_coming_soon  && ! $post_thumbnail_id ) {
			$thumb_url = $placeholder_url;
		}

		return $thumb_url;
	}

	/**
	 * Handle scheduled course publish.
	 *
	 * @since 3.3.0
	 *
	 * @param object $course the course object.
	 *
	 * @return void
	 */
	public function handle_schedule_post_published( $course ) {
		if ( tutor()->course_post_type !== $course->post_type ) {
			return;
		}

		$enable_coming_soon = (bool) get_post_meta( $course->ID, self::COURSE_COMING_SOON_META, true );

		if ( $enable_coming_soon ) {
			delete_post_meta( $course->ID, self::COURSE_COMING_SOON_META );
			delete_post_meta( $course->ID, self::COURSE_COMING_SOON_THUMBNAIL_ID_META );
			delete_post_meta( $course->ID, self::COURSE_CURRICULUM_PREVIEW_META );
		}
	}
}

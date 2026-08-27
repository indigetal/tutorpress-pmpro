<?php
/**
 * Enrolled Content Access
 *
 * Shared policy for denying stale membership-created enrollments on mapped
 * direct callers of Utils::has_enrolled_content_access.
 *
 * @package TutorPress_PMPro
 * @subpackage Frontend
 * @since 1.0.7
 */

namespace TUTORPRESS_PMPRO\Frontend;

/**
 * Class Enrolled_Content_Access
 *
 * Shared access service for mapped enrolled-content callers.
 */
class Enrolled_Content_Access {

	/**
	 * Access checker service instance.
	 *
	 * @since 1.0.7
	 * @var \TUTORPRESS_PMPRO\Access\Access_Checker
	 */
	private $access_checker;

	/**
	 * Constructor.
	 *
	 * @since 1.0.7
	 * @param \TUTORPRESS_PMPRO\Access\Access_Checker $access_checker Access checker instance.
	 */
	public function __construct( $access_checker ) {
		$this->access_checker = $access_checker;
	}

	/**
	 * Register enrolled-content access hooks.
	 *
	 * @since 1.0.7
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'tutor_single_content_template', array( $this, 'filter_single_content_template' ), 100, 2 );
		add_action( 'wp_ajax_tutor_render_quiz_content', array( $this, 'guard_render_quiz_content' ), 9 );
		add_action( 'wp_ajax_sync_video_playback', array( $this, 'guard_sync_video_playback' ), 9 );
		add_action( 'wp_ajax_tutor_place_rating', array( $this, 'guard_place_rating' ), 9 );
		add_action( 'template_redirect', array( $this, 'guard_start_quiz' ), 9 );
		add_action( 'template_redirect', array( $this, 'guard_answering_quiz' ), 9 );
		add_action( 'wp_ajax_tutor_quiz_abandon', array( $this, 'guard_answering_quiz' ), 9 );
	}

	/**
	 * Replace the legacy lesson/quiz template when membership enrollment should be denied.
	 *
	 * Tutor's priority-99 callbacks have already selected a path. Other post types
	 * and non-denied requests keep the incoming value unchanged.
	 *
	 * @since 1.0.7
	 * @param string $template  Incoming template path.
	 * @param string $post_type Current content post type.
	 * @return string
	 */
	public function filter_single_content_template( $template, $post_type ) {
		$is_lesson = tutor()->lesson_post_type === $post_type;
		$is_quiz   = tutor()->quiz_post_type === $post_type;

		if ( ! $is_lesson && ! $is_quiz ) {
			return $template;
		}

		$content_id = absint( get_the_ID() );
		if ( ! $content_id ) {
			return $template;
		}

		$content_type = $is_lesson ? 'lesson' : 'quiz';
		$course_id    = absint( tutor_utils()->get_course_id_by( $content_type, $content_id ) );
		$lesson_id    = $is_lesson ? $content_id : 0;

		if ( ! $this->should_deny_enrolled_content_access( $course_id, $lesson_id ) ) {
			return $template;
		}

		return tutor_get_template( 'single.lesson.required-enroll' );
	}

	/**
	 * Deny stale membership quiz-render AJAX before Tutor's callback.
	 *
	 * Silently returns unless nonce, authenticated user, integer quiz_id,
	 * and a valid resolved course are established. Tutor's priority-10
	 * callback retains nonce errors and other native handling.
	 *
	 * @since 1.0.7
	 * @return void
	 */
	public function guard_render_quiz_content() {
		if ( ! tutor_utils()->is_nonce_verified() ) {
			return;
		}

		if ( ! get_current_user_id() ) {
			return;
		}

		$quiz_id = \TUTOR\Input::post( 'quiz_id', 0, \TUTOR\Input::TYPE_INT );
		if ( ! $quiz_id ) {
			return;
		}

		$course_id = absint( tutor_utils()->get_course_id_by( 'quiz', $quiz_id ) );
		if ( ! $course_id ) {
			return;
		}

		if ( ! $this->should_deny_enrolled_content_access( $course_id ) ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Access Denied.', 'tutor' ),
			)
		);
	}

	/**
	 * Deny stale membership playback AJAX before Tutor's callback.
	 *
	 * Silently returns unless nonce, authenticated user, integer post_id,
	 * Tutor lesson post type, and a valid resolved course are established.
	 * Tutor's priority-10 callback retains nonce errors and other native handling.
	 *
	 * @since 1.0.7
	 * @return void
	 */
	public function guard_sync_video_playback() {
		if ( ! tutor_utils()->is_nonce_verified() ) {
			return;
		}

		if ( ! get_current_user_id() ) {
			return;
		}

		$post_id = \TUTOR\Input::post( 'post_id', 0, \TUTOR\Input::TYPE_INT );
		if ( ! $post_id ) {
			return;
		}

		if ( tutor()->lesson_post_type !== get_post_type( $post_id ) ) {
			return;
		}

		$course_id = absint( tutor_utils()->get_course_id_by( 'lesson', $post_id ) );
		if ( ! $course_id ) {
			return;
		}

		if ( ! $this->should_deny_enrolled_content_access( $course_id, $post_id ) ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Access Denied', 'tutor' ),
			)
		);
		exit;
	}

	/**
	 * Deny stale membership non-REST review AJAX before Tutor's callback.
	 *
	 * Silently returns unless the request is non-REST, nonce, authenticated
	 * user, and Tutor's course_id input resolve to a valid course. Tutor's
	 * priority-10 callback retains nonce errors and rating/review handling.
	 *
	 * @since 1.0.7
	 * @return void
	 */
	public function guard_place_rating() {
		if ( tutor_is_rest() ) {
			return;
		}

		if ( ! tutor_utils()->is_nonce_verified() ) {
			return;
		}

		if ( ! get_current_user_id() ) {
			return;
		}

		$course_id = absint( \TUTOR\Input::post( 'course_id' ) );
		if ( ! $course_id ) {
			return;
		}

		if ( ! $this->should_deny_enrolled_content_access( $course_id ) ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'Access Denied', 'tutor' ),
			)
		);
		exit;
	}

	/**
	 * Deny stale membership quiz-start POSTs before Tutor's start_the_quiz.
	 *
	 * Silently returns unless tutor_action is tutor_start_quiz, nonce, logged-in
	 * user, integer quiz_id, and CourseModel::get_course_by_quiz() establish a
	 * valid course. Tutor's priority-10 callback retains nonce, sign-in, and
	 * Invalid course handling.
	 *
	 * @since 1.0.7
	 * @return void
	 */
	public function guard_start_quiz() {
		if ( 'tutor_start_quiz' !== \TUTOR\Input::post( 'tutor_action' ) ) {
			return;
		}

		if ( ! tutor_utils()->is_nonce_verified() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$quiz_id = \TUTOR\Input::post( 'quiz_id', 0, \TUTOR\Input::TYPE_INT );
		if ( ! $quiz_id ) {
			return;
		}

		$course = \Tutor\Models\CourseModel::get_course_by_quiz( $quiz_id );
		if ( ! $course ) {
			return;
		}

		if ( ! $this->should_deny_enrolled_content_access( absint( $course->ID ) ) ) {
			return;
		}

		wp_die( esc_html( __( 'You don\'t have access to this course', 'tutor' ) ) );
	}

	/**
	 * Deny stale membership quiz-answer POSTs before Tutor's answering_quiz.
	 *
	 * Silently returns unless tutor_action is tutor_answering_quiz_question,
	 * logged-in user, nonce, integer attempt_id owned by the current user,
	 * integer quiz_id, and get_course_id_by() establish a valid course.
	 * Tutor's priority-10 callback retains sign-in, nonce, and invalid-attempt
	 * handling.
	 *
	 * @since 1.0.7
	 * @return void
	 */
	public function guard_answering_quiz() {
		if ( 'tutor_answering_quiz_question' !== \TUTOR\Input::post( 'tutor_action' ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! tutor_utils()->is_nonce_verified() ) {
			return;
		}

		$attempt_id = \TUTOR\Input::post( 'attempt_id', 0, \TUTOR\Input::TYPE_INT );
		if ( ! $attempt_id ) {
			return;
		}

		$user_id = get_current_user_id();
		$attempt = tutor_utils()->get_attempt( $attempt_id );
		if ( ! $attempt || ! is_object( $attempt ) || (int) $attempt->user_id !== (int) $user_id ) {
			return;
		}

		$quiz_id = \TUTOR\Input::post( 'quiz_id', 0, \TUTOR\Input::TYPE_INT );
		if ( ! $quiz_id ) {
			return;
		}

		$course_id = absint( tutor_utils()->get_course_id_by( 'quiz', $quiz_id ) );
		if ( ! $course_id ) {
			return;
		}

		if ( ! $this->should_deny_enrolled_content_access( $course_id ) ) {
			return;
		}

		wp_die( esc_html( __( 'You don\'t have access to this course', 'tutor' ) ) );
	}

	/**
	 * Whether mapped enrolled-content surfaces should deny this course/lesson.
	 *
	 * Invalid course IDs, public courses, and truthy lesson previews do not deny.
	 * Otherwise delegates exclusively to should_deny_membership_enrollment().
	 *
	 * @since 1.0.7
	 * @param int $course_id Course post ID (already validated by the caller).
	 * @param int $lesson_id Optional lesson post ID for preview keep. Default 0.
	 * @return bool True to deny, false otherwise.
	 */
	private function should_deny_enrolled_content_access( $course_id, $lesson_id = 0 ) {
		$course_id = absint( $course_id );
		$lesson_id = absint( $lesson_id );

		if ( ! $course_id ) {
			return false;
		}

		if ( \TUTOR\Course_List::is_public( $course_id ) ) {
			return false;
		}

		if ( $lesson_id && tutor()->lesson_post_type === get_post_type( $lesson_id ) && (bool) get_post_meta( $lesson_id, '_is_preview', true ) ) {
			return false;
		}

		return $this->access_checker->should_deny_membership_enrollment( $course_id, get_current_user_id() );
	}
}

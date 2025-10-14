<?php
/**
 * Manage Course Enrollment Expire
 *
 * @package TutorPro\EnrollmentExpiry
 * @subpackage Frontend
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 2.0.0
 */

namespace TUTOR_ENROLLMENTS;

use TUTOR\Input;
use Tutor\Traits\JsonResponse;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enrollment Expiry Class
 *
 * @since 2.2.0
 */
class Enrollment_Expiry {
	use JsonResponse;

	/**
	 * User meta key to hold custom expiry date, after the
	 * underscore course id will be concat like the following:
	 * tutor_course_enrollment_expiry_date_12
	 *
	 * @var string
	 *
	 * @since 3.3.0
	 */
	const ENROLLMENT_EXPIRY_DATE_UMK = 'tutor_course_enrollment_expiry_date_';

	/**
	 * Register hooks
	 *
	 * @since 2.0.0
	 *
	 * @since 3.3.0 wp_ajax_tutor_pro_enrollment_extend added
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'single_course_template_before_load', array( $this, 'cancel_expired_enrolment' ), 10, 1 );
		add_action( 'tutor_before_enrolment_check', array( $this, 'cancel_expired_enrolment' ), 10, 2 );
		add_action( 'tutor_after_enrolled', array( $this, 'remove_custom_expiry_date' ) );

		add_action( 'tutor_course/single/entry/after', array( $this, 'show_expires_info' ) );
		add_filter( 'tutor/options/extend/attr', array( $this, 'setting_field' ), 12 );

		add_filter( 'tutor/course/single/sidebar/metadata', array( $this, 'add_entry_box_meta' ), 10, 2 );

		add_action( 'wp_ajax_tutor_pro_enrollment_extend', array( $this, 'ajax_store_enrollment_extend_date' ) );
		add_filter( 'tutor_enrollment_list_status', array( $this, 'filter_the_status' ), 10, 2 );
	}

	/**
	 * Check is enabled.
	 *
	 * @since 2.0.0
	 *
	 * @return boolean
	 */
	private function is_enabled() {
		return (bool) get_tutor_option( 'enrollment_expiry_enabled' );
	}

	/**
	 * Settings field.
	 *
	 * @since 2.0.0
	 *
	 * @param array $attr attr.
	 *
	 * @return array
	 */
	public function setting_field( $attr ) {
		if ( apply_filters( 'tutor_membership_only_mode', false ) ) {
			return $attr;
		}

		$attr['course']['blocks']['block_course']['fields'][] = array(
			'key'         => 'enrollment_expiry_enabled',
			'type'        => 'toggle_switch',
			'label'       => __( 'Enrollment Expiration', 'tutor-pro' ),
			'label_title' => '',
			'default'     => 'off',
			'desc'        => __( 'Enable to allow enrollment expiration feature in all courses', 'tutor-pro' ),
		);

		return $attr;
	}

	/**
	 * Cancel course enrolment if course expire
	 *
	 * @since 2.0.0
	 *
	 * @since 3.3.0 Filter added to exclude ids that has custom expiry date
	 *
	 * @param int $course_id course id.
	 * @param int $user_id user id.
	 *
	 * @return void|null
	 */
	public function cancel_expired_enrolment( $course_id, $user_id = null ) {

		if ( ! $this->is_enabled() ) {
			return;
		}

		if ( ! $user_id && ! is_user_logged_in() ) {
			return;
		}

		$check_expiry = apply_filters( 'tutor_pro_check_course_expiry', true, $course_id );
		if ( ! $check_expiry ) {
			return;
		}

		global $wpdb;

		$expiry = get_tutor_course_settings( $course_id, 'enrollment_expiry' );
		if ( ! is_numeric( $expiry ) || $expiry < 1 ) {
			return;
		}

		$user_id = tutor_utils()->get_user_id( $user_id );

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					ID,
					post_parent,
					post_author,
					post_date
				FROM {$wpdb->posts}
            	WHERE post_author=%d
                AND post_parent=%d
                AND post_type='tutor_enrolled'
                AND post_status='completed'
				",
				$user_id,
				$course_id,
			)
		);

		if ( is_array( $results ) && count( $results ) ) {
			$expired_list = array_filter(
				$results,
				function( $item ) use ( $expiry ) {
					$custom_expiry_date = $this::get_custom_expiry_date( $item->post_parent, $item->post_author );
					if ( $custom_expiry_date ) {
						return tutor_time() > $custom_expiry_date;
					}

					$post_date    = strtotime( $item->post_date );
					$course_limit = $post_date + ( DAY_IN_SECONDS * (int) $expiry );
					return tutor_time() > $course_limit;
				}
			);

			$ids = array_column( $expired_list, 'ID' );
			if ( count( $ids ) ) {
				$wpdb->query( "UPDATE {$wpdb->posts} SET post_status='cancel' WHERE ID IN ( " . implode( ',', $ids ) . ' )' );//phpcs:ignore

				foreach ( $ids as $id ) {
					do_action( 'tutor_enrollment/after/expired', $id );
				}
			}
		}
	}

	/**
	 * Show expire info for non-enrolled student.
	 * TODO: Will be removed.
	 *
	 * @since 2.0.0
	 *
	 * @param int $course_id course id.
	 *
	 * @return void|null
	 */
	public function show_expires_info_not_enrolled( $course_id ) {

		if ( ! $this->is_enabled() || tutor_utils()->is_enrolled( $course_id, get_current_user_id() ) ) {
			return;
		}

		$show_info = apply_filters( 'tutor_pro_show_course_expire_info', true, $course_id );
		if ( ! $show_info ) {
			return;
		}

		$expiry     = get_tutor_course_settings( $course_id, 'enrollment_expiry' );
		$is_limited = is_numeric( $expiry ) && $expiry >= 1;

		$validity = $is_limited ? $expiry . ' ' . ( $expiry > 1 ? __( 'days', 'tutor-pro' ) : __( 'day', 'tutor-pro' ) ) : __( 'Lifetime', 'tutor-pro' );

		echo '<div class="enrolment-expire-info tutor-fs-7 tutor-color-muted tutor-d-flex tutor-align-center tutor-mt-12">
				<i class="tutor-icon-calender-line tutor-mr-8"></i> ' .
				esc_html__( 'Enrollment validity', 'tutor-pro' ), ': 
				<span class="tutor-ml-4">' . esc_html( apply_filters( 'tutor_course_expire_validity', $validity, $course_id ) ) . '</span>
			</div>';
	}

	/**
	 * Add course entry box meta.
	 *
	 * @param array $meta meta.
	 * @param int   $course_id course id.
	 *
	 * @return array.
	 */
	public function add_entry_box_meta( $meta, $course_id ) {
		if ( ! $this->is_enabled() || tutor_utils()->is_enrolled( $course_id, get_current_user_id() ) ) {
			return $meta;
		}

		$show_info = apply_filters( 'tutor_pro_show_course_expire_info', true, $course_id );
		if ( ! $show_info ) {
			return $meta;
		}

		$expiry     = get_tutor_course_settings( $course_id, 'enrollment_expiry' );
		$is_limited = is_numeric( $expiry ) && $expiry >= 1;

		$validity = $is_limited ? $expiry . ' ' . ( $expiry > 1 ? __( 'days', 'tutor-pro' ) : __( 'day', 'tutor-pro' ) ) : __( 'Lifetime', 'tutor-pro' );

		$meta[] = array(
			'list_class' => 'tutor-course-enrollment-meta',
			'icon_class' => 'tutor-icon-calender-line',
			'label'      => __( 'Enrollment validity', 'tutor-pro' ),
			/* translators: %s: validity */
			'value'      => sprintf( __( 'Enrollment validity: %s', 'tutor-pro' ), $validity ),
		);

		return $meta;
	}

	/**
	 * Show expire info.
	 *
	 * @since 2.0.0
	 *
	 * @param int $course_id course id.
	 *
	 * @return void|null
	 */
	public function show_expires_info( $course_id ) {

		if ( ! $this->is_enabled() ) {
			return;
		}

		$enrolment = tutor_utils()->is_enrolled( $course_id, get_current_user_id() );

		$show_info = apply_filters( 'tutor_pro_show_course_expire_info', true, $course_id );
		if ( ! $show_info ) {
			return;
		}

		if ( $enrolment ) {
			$expiry   = get_tutor_course_settings( $course_id, 'enrollment_expiry' );
			$validity = apply_filters( 'tutor_course_expire_validity', __( 'Lifetime', 'tutor-pro' ), $course_id );
			if ( ! is_numeric( $expiry ) || $expiry < 1 ) {
				?>
				<p class="enrolment-expire-info tutor-fs-7 tutor-color-muted tutor-d-flex tutor-align-center tutor-mt-4">
					<i class="tutor-icon-calender-line tutor-mr-8"></i>
					<?php esc_html_e( 'Enrollment validity:', 'tutor-pro' ); ?>
					<span class="tutor-ml-4">
						<?php echo esc_html( $validity ); ?>
					</span>
				</p>
				<?php
				return;
			}

			$expiry_date        = strtotime( "+{$expiry} days", strtotime( $enrolment->post_date ) );
			$custom_expiry_date = self::get_custom_expiry_date( $course_id, $enrolment->post_author );
			if ( $custom_expiry_date ) {
				$expiry_date = $custom_expiry_date;
			}

			$validity = tutor_i18n_get_formated_date( gmdate( 'Y-m-d', $expiry_date ), get_option( 'date_format' ) );
			/* translators: %s: validity */
			$text = sprintf( __( 'Enrollment valid until %s', 'tutor-pro' ), $validity );

			echo '<p class="enrolment-expire-info tutor-fs-7 tutor-color-muted tutor-d-flex tutor-align-center tutor-mt-4">
					<i class="tutor-icon-calender-line tutor-mr-8"></i> ' .
					esc_html( $text ) . ' 
				</p>';
		}
	}

	/**
	 * Store custom enrollment expiry date for a specific student
	 * for a course
	 *
	 * @since 3.3.0
	 *
	 * @return void
	 */
	public function ajax_store_enrollment_extend_date() {
		tutor_utils()->check_nonce();
		tutor_utils()->check_current_user_capability();

		$enrollment_id = Input::post( 'enrollment_id', 0, Input::TYPE_INT );
		$extend_date   = Input::post( 'extend_date' );

		if ( ! $extend_date ) {
			$this->response_bad_request( __( 'Invalid extend date', 'tutor-pro' ) );
		}

		if ( ! $enrollment_id ) {
			$this->response_bad_request( __( 'Invalid enrollment ID', 'tutor-pro' ) );
		}

		$enrollment = get_post( $enrollment_id );
		if ( ! $enrollment ) {
			$this->response_bad_request( __( 'Enrollment not found', 'tutor-pro' ) );
		}

		$student_id = $enrollment->post_author;
		$course_id  = $enrollment->post_parent;
		if ( ! $student_id || ! $course_id ) {
			$this->response_bad_request( __( 'Invalid student or course ID', 'tutor-pro' ) );
		}

		if ( $this::store_custom_expiry_date( $course_id, $student_id, $extend_date ) ) {
			// Mark the enrollment as active.
			if ( strtotime( $extend_date ) > strtotime( 'today' ) ) {
				$enrollment->post_status = 'completed';
				wp_update_post( $enrollment );
			}

			$this->json_response( __( 'Enrollment date extended successfully!', 'tutor-pro' ) );
		} else {
			$this->response_bad_request( __( 'There was an error or the date is same as previous date.', 'tutor-pro' ) );
		}
	}

	/**
	 * Get custom enrollment expiry date for a specific student
	 * for a course
	 *
	 * @since 3.3.0
	 *
	 * @param int $course_id  Course ID.
	 * @param int $student_id Student ID.
	 *
	 * @return int|false Expiry date in unix timestamp format or false if not found
	 */
	public static function get_custom_expiry_date( int $course_id = 0, int $student_id = 0 ) {
		$course_id  = tutor_utils()->get_post_id( $course_id );
		$student_id = tutor_utils()->get_user_id( $student_id );

		if ( ! $course_id || ! $student_id ) {
			return false;
		}

		$expiry_date_timestamp = get_user_meta( $student_id, self::ENROLLMENT_EXPIRY_DATE_UMK . $course_id, true );

		return $expiry_date_timestamp ? (int) $expiry_date_timestamp : false;
	}

	/**
	 * Get custom enrollment expiry date for a specific student
	 * for a course
	 *
	 * @since 3.3.0
	 *
	 * @param int $course_id  Course ID.
	 * @param int $student_id Student ID.
	 * @param int $expiry_date Expiry date.
	 *
	 * @return bool
	 */
	public static function store_custom_expiry_date( int $course_id, int $student_id, $expiry_date ) {
		return (bool) update_user_meta( $student_id, self::ENROLLMENT_EXPIRY_DATE_UMK . $course_id, strtotime( $expiry_date ) );
	}

	/**
	 * Remove custom enrollment expiry date for a specific student
	 * for a course
	 *
	 * @since 3.4.0
	 *
	 * @param int $course_id  Course ID.
	 *
	 * @return void
	 */
	public static function remove_custom_expiry_date( int $course_id ) {
		$student_id = tutor_utils()->get_user_id();
		delete_user_meta( $student_id, self::ENROLLMENT_EXPIRY_DATE_UMK . $course_id );
	}

	/**
	 * Filter the status of the enrollment
	 *
	 * @since 3.3.0
	 *
	 * @param string $status Enrollment status.
	 * @param object $enrollment Enrollment object.
	 *
	 * @return string
	 */
	public function filter_the_status( $status, $enrollment ) {
		try {
			if ( $this::is_expired( $status, $enrollment->enrol_id ) ) {
				$status = __( 'Expired', 'tutor-pro' );
			}
		} catch ( \Throwable $th ) {
			tutor_log( $th );
			return $status;
		}

		return $status;
	}

	/**
	 * Check if enrollment is expired
	 *
	 * @since 3.3.0
	 *
	 * @param string $status Enrollment status.
	 * @param mixed  $enrollment Enrollment ID or WP_Post object.
	 *
	 * @throws \InvalidArgumentException If invalid enrollment argument passed.
	 *
	 * @return bool
	 */
	public static function is_expired( $status, $enrollment ) {
		$enrollment = is_a( $enrollment, 'WP_Post' ) ? $enrollment : get_post( $enrollment );
		$is_expired = false;

		$is_cancelled = in_array( strtolower( $status ), array( 'cancel', 'canceled', 'cancelled' ), true );
		if ( $is_cancelled ) {
			return $is_expired;
		}

		if ( ! is_a( $enrollment, 'WP_Post' ) ) {
			throw new \InvalidArgumentException( __( 'Invalid enrollment argument passed', 'tutor-pro' ) );
		}

		$course_id  = $enrollment->post_parent;
		$student_id = $enrollment->post_author;

		$expire_in_days      = (int) get_tutor_course_settings( $course_id, 'enrollment_expiry' );
		$default_expiry_date = $expire_in_days ? strtotime( '+' . $expire_in_days . ' days', strtotime( $enrollment->post_date_gmt ) ) : false;

		$custom_expiry_date = self::get_custom_expiry_date( $course_id, $student_id );
		if ( $custom_expiry_date ) {
			$is_expired = strtotime( 'today' ) > $custom_expiry_date;
		} else {
			$is_expired = $default_expiry_date && strtotime( 'today' ) > $default_expiry_date;
		}

		return $is_expired;
	}
}

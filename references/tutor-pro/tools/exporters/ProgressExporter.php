<?php
/**
 * User Progress Exporter.
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use TUTOR\Quiz;
use Tutor\Models\CourseModel;
use TUTOR_ASSIGNMENTS\Assignments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle user progress exporting
 *
 * @since 3.8.1
 */
class ProgressExporter {

	/**
	 * Export course-related data including quiz attempts, assignments, and completion data.
	 *
	 * @since 3.8.1
	 *
	 * @param int $course_id The ID of the course to export data.
	 *
	 * @return array An associative array containing:
	 *   - 'quiz_attempts_and_answers': Quiz attempts and answers for the course.
	 *   - 'submitted_assignments': Submitted assignments for the course.
	 *   - 'course_completion': Completion data for the course.
	 */
	public static function export( $course_id ) {

		$result = array(
			'quiz_attempts_and_answers' => ( new Quiz() )->get_quiz_attempts_and_answers_by_course_id( $course_id ),
			'course_completion'         => ( new CourseModel() )->get_course_completion_data_by_course_id( $course_id ),
		);

		if ( tutor_utils()->is_addon_enabled( TUTOR_ASSIGNMENTS()->basename ) ) {
			$result['submitted_assignments'] = ( new Assignments() )->get_assignments_by_course_id( $course_id );
		}

		if ( tutor_utils()->is_addon_enabled( TUTOR_GB()->basename ) ) {
			$result['grade_book_results'] = get_gradebook_results_by_course_id( $course_id );
		}

		return $result;
	}
}

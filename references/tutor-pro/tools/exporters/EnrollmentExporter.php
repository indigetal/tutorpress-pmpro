<?php
/**
 * Student Enrollment Exporter.
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use TUTOR_ENROLLMENTS\Enrollments;
use TutorPro\Subscription\Models\PlanModel;
use TutorPro\Subscription\Models\SubscriptionModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle enrollment exporting
 *
 * @since 3.8.1
 */
class EnrollmentExporter {

	/**
	 * Export enrollment data and associated plan IDs for a given course or bundle.
	 *
	 * @since 3.8.1
	 *
	 * @param int  $id          The course or bundle ID to export enrollments from.
	 * @param bool $from_bundle Whether the export is for a bundle. Defaults to false.
	 *
	 * @return array{
	 *     enrollment_data: array<array<string, mixed>>, // List of enrollment and student data.
	 *     plan_ids: int[]                                // Unique membership plan IDs associated with enrollments.
	 * }
	 */
	public static function export( int $id, $from_bundle = false ): array {

		// Get student data.
		$enrollments = ( new Enrollments() )->get_all_enrollments( $id );

		if ( ! $enrollments ) {
			return array(
				'enrollment_data' => array(),
				'plan_ids'        => array(),
			);
		}

		$enrolled_user_ids               = array();
		$deleted_enrollment_user_records = array();
		$plan_ids                        = array();

		$result = array_map(
			function ( $enrollment ) use ( &$enrolled_user_ids, &$plan_ids, $from_bundle ) {

				array_push( $enrolled_user_ids, $enrollment->post_author );

				$enrollment_meta = get_post_meta( $enrollment->ID );

				// Get membership plan IDs based on their associated course.
				if ( ! $from_bundle && ! empty( $enrollment_meta ) && array_key_exists( SubscriptionModel::SUBSCRIPTION_ENROLLMENT_META, $enrollment_meta ) ) {

					$subscription = ( new SubscriptionModel() )->get_subscription( $enrollment_meta[ SubscriptionModel::SUBSCRIPTION_ENROLLMENT_META ] );

					if ( ! empty( $subscription ) ) {
						$is_membership_plan = ( new PlanModel() )->is_membership_plan( $subscription->plan_id );

						if ( $is_membership_plan && ! in_array( $subscription->plan_id, $plan_ids ) ) {
							$plan_ids[] = $subscription->plan_id;
						}
					}
				}

				return array(
					'enrollment'      => $enrollment,
					'enrollment_meta' => $enrollment_meta,
					'student'         => get_user_by( 'ID', $enrollment->post_author ),
					'student_meta'    => get_user_meta( $enrollment->post_author ),
				);
			},
			$enrollments
		);

		$user_ids = get_option( Exporter::OPTION_USER_IDS . $id );

		if ( ! empty( $user_ids ) ) {
			$deleted_enrollment_user_records = self::get_deleted_enrollment_user_records( $user_ids, $enrolled_user_ids );
		}

		delete_option( Exporter::OPTION_USER_IDS . $id );

		if ( ! empty( $deleted_enrollment_user_records ) ) {
			$result = array_merge( $result, $deleted_enrollment_user_records );
		}

		return array(
			'enrollment_data' => $result,
			'plan_ids'        => $plan_ids,
		);
	}

	/**
	 * Build records for users whose enrollments are deleted.
	 *
	 * @since 3.8.1
	 *
	 * @param int[] $user_ids           user IDs from order table for this particular course.
	 * @param int[] $enrolled_user_ids  User IDs that currently have enrollments.
	 *
	 * @return array
	 */
	private static function get_deleted_enrollment_user_records( $user_ids, $enrolled_user_ids ): array {

		return array_filter(
			array_map(
				function ( $id ) use ( $enrolled_user_ids ) {

					if ( ! in_array( $id, $enrolled_user_ids ) ) {
						return array(
							'enrollment'      => array(),
							'enrollment_meta' => array(),
							'student'         => get_user_by( 'ID', $id ),
							'student_meta'    => get_user_meta( $id ),
						);
					}
				},
				$user_ids
			)
		);
	}
}

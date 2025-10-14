<?php
/**
 * Subscription Plan Exporter.
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.8.1
 */

namespace TutorPro\Tools;

use TutorPro\Tools\Exporter;
use Tutor\Helpers\QueryHelper;
use TutorPro\Subscription\Models\PlanModel;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle subscription plan exporting
 *
 * @since 3.8.1
 */
class PlanExporter {

	/**
	 * Export membership plans and their related plan items.
	 *
	 * @since 3.8.1
	 *
	 * @param int    $id   The ID of the plan or object to export.
	 * @param string $type The type of export. Default is Exporter::TYPE_MEMBERSHIP_PLANS.
	 *
	 * @return array The exported plan data. Returns a single plan array if exporting a membership plan,
	 *               or an array of plans with their associated items for other types.
	 */
	public static function export( int $id, $type = Exporter::TYPE_MEMBERSHIP_PLANS ): array {

		global $wpdb;

		$condition          = Exporter::TYPE_MEMBERSHIP_PLANS === $type ? array( 'plans.id' => $id ) : array( 'plan_items.object_id' => $id );
		$plans              = PlanModel::get_filtered_subscription_plans( $condition );
		$total_plan_columns = count( QueryHelper::get_table_schema( $wpdb->tutor_subscription_plans ) );
		$processed_plan_ids = array();
		$plan_info          = array();

		foreach ( $plans as $key => $plan ) {

			if ( empty( $plan_info ) || ! isset( $processed_plan_ids[ $plan->id ] ) ) {
				$plan_info[]                     = array_slice( (array) $plan, 0, $total_plan_columns );
				$processed_plan_ids[ $plan->id ] = $key;
			}

			if ( isset( $processed_plan_ids[ $plan->id ] ) && ! empty( $plan->object_name && ! empty( $plan->object_id ) ) ) {
				$plan_info[ $processed_plan_ids[ $plan->id ] ]['items'][] = array_slice( (array) $plan, $total_plan_columns );
			}
		}

		return Exporter::TYPE_MEMBERSHIP_PLANS === $type ? reset( $plan_info ) : $plan_info;
	}

	/**
	 * Extract plan IDs from a list of subscription plans.
	 *
	 * @since 3.8.1
	 *
	 * @param array $plans An array of subscription plans.
	 *
	 * @return array Returns an array of plan IDs. Returns an empty array if the input is empty.
	 */
	public static function extract_plan_ids( array $plans ) {

		if ( empty( $plans ) ) {
			return array();
		}

		return array_map( fn( $plan ) => (int) $plan['id'], $plans );
	}


	/**
	 * Check if the given key corresponds to a membership plan.
	 *
	 * @since 3.8.1
	 *
	 * @param string $key The key to check.
	 *
	 * @return bool True if it is a membership plan, false otherwise.
	 */
	public static function is_membership_plan( string $key ): bool {
		return Exporter::PLANS === $key;
	}
}

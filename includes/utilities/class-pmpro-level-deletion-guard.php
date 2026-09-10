<?php
namespace TUTORPRESS_PMPRO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PMPro_Level_Deletion_Guard {
	public static function membership_status_blocks_deletion( $status ) {
		if ( ! is_string( $status ) || '' === $status ) {
			return true;
		}
		return ! in_array( $status, array( 'admin_cancelled', 'cancelled', 'changed', 'admin_changed', 'expired', 'inactive' ), true );
	}

	public static function order_status_blocks_deletion( $status ) {
		if ( ! is_string( $status ) || '' === $status ) {
			return true;
		}
		return ! in_array( $status, array( 'success', 'refunded', 'error', 'voided', 'cancelled' ), true );
	}

	public static function subscription_row_blocks_deletion( $status = null ) {
		return true;
	}

	public static function membership_inventory( $level_id ) {
		return self::level_inventory( $level_id, 'pmpro_memberships_users', 'membership_id', 'membership_status_blocks_deletion' );
	}

	public static function subscription_inventory( $level_id ) {
		return self::level_inventory( $level_id, 'pmpro_subscriptions', 'membership_level_id', 'subscription_row_blocks_deletion' );
	}

	private static function level_inventory( $level_id, $table_prop, $column, $classifier ) {
		global $wpdb;
		$level_id = (int) $level_id;
		if ( $level_id <= 0 || empty( $wpdb->$table_prop ) || ! in_array( $column, array( 'membership_id', 'membership_level_id' ), true ) ) {
			return array( 'complete' => false, 'protected' => true, 'total' => 0 );
		}
		$table = $wpdb->$table_prop;
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %d", $level_id ) );
		if ( false === $total || null === $total || '' !== $wpdb->last_error ) {
			return array( 'complete' => false, 'protected' => true, 'total' => 0 );
		}
		$statuses = $wpdb->get_col( $wpdb->prepare( "SELECT `status` FROM `{$table}` WHERE `{$column}` = %d", $level_id ) );
		if ( false === $statuses || ! is_array( $statuses ) || '' !== $wpdb->last_error || (int) $total !== count( $statuses ) ) {
			return array( 'complete' => false, 'protected' => true, 'total' => 0 );
		}
		$protected = false;
		foreach ( $statuses as $status ) {
			if ( self::{$classifier}( is_string( $status ) ? $status : '' ) ) {
				$protected = true;
			}
		}
		return array( 'complete' => true, 'protected' => $protected, 'total' => (int) $total );
	}

	public static function order_inventory( $level_id ) {
		global $wpdb;
		$level_id = (int) $level_id;
		$residual = 'unsaved_checkout_race';
		if ( $level_id <= 0 || empty( $wpdb->pmpro_membership_orders ) ) {
			return array( 'complete' => false, 'protected' => true, 'total' => 0, 'residual' => $residual );
		}
		$table = $wpdb->pmpro_membership_orders;
		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `membership_id` = %d", $level_id ) );
		if ( false === $total || null === $total || '' !== $wpdb->last_error ) {
			return array( 'complete' => false, 'protected' => true, 'total' => 0, 'residual' => $residual );
		}
		$statuses = $wpdb->get_col( $wpdb->prepare( "SELECT `status` FROM `{$table}` WHERE `membership_id` = %d", $level_id ) );
		if ( false === $statuses || ! is_array( $statuses ) || '' !== $wpdb->last_error || (int) $total !== count( $statuses ) ) {
			return array( 'complete' => false, 'protected' => true, 'total' => 0, 'residual' => $residual );
		}
		$protected = 0;
		$terminal  = 0;
		foreach ( $statuses as $status ) {
			if ( self::order_status_blocks_deletion( is_string( $status ) ? $status : '' ) ) {
				$protected++;
			} else {
				$terminal++;
			}
		}
		if ( (int) $total !== $protected + $terminal ) {
			return array( 'complete' => false, 'protected' => true, 'total' => 0, 'residual' => $residual );
		}
		return array( 'complete' => true, 'protected' => $protected > 0, 'total' => (int) $total, 'residual' => $residual );
	}

	public static function evaluate( $level_id, $object_id, $object_type ) {
		global $wpdb;
		$level_id = (int) $level_id; $object_id = (int) $object_id;
		if ( $level_id <= 0 || $object_id <= 0 || ! in_array( $object_type, array( 'courses', 'course-bundle' ), true ) || empty( $wpdb->pmpro_membership_levelmeta ) || empty( $wpdb->pmpro_memberships_pages ) || empty( $wpdb->pmpro_membership_levels_groups ) || empty( $wpdb->pmpro_groups ) ) {
			return 'ineligible';
		}
		foreach ( array( self::membership_inventory( $level_id ), self::subscription_inventory( $level_id ), self::order_inventory( $level_id ) ) as $inv ) {
			if ( empty( $inv['complete'] ) || ! empty( $inv['protected'] ) ) {
				return 'protected';
			}
		}
		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM `{$wpdb->pmpro_membership_levelmeta}` WHERE pmpro_membership_level_id = %d AND meta_key IN ('tutorpress_managed','tutorpress_course_id','tutorpress_bundle_id','TUTORPRESS_PMPRO_membership_model')", $level_id ), OBJECT_K );
		$pages = $wpdb->get_col( $wpdb->prepare( "SELECT page_id FROM `{$wpdb->pmpro_memberships_pages}` WHERE membership_id = %d", $level_id ) );
		$pm = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM `{$wpdb->postmeta}` WHERE meta_key = %s", '_tutorpress_pmpro_levels' ), ARRAY_A );
		$gids = $wpdb->get_col( $wpdb->prepare( "SELECT `group` FROM `{$wpdb->pmpro_membership_levels_groups}` WHERE `level` = %d", $level_id ) );
		$grows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM `{$wpdb->postmeta}` WHERE meta_key = %s", '_tutorpress_pmpro_group_id' ), ARRAY_A );
		if ( false === $meta || ! is_array( $meta ) || false === $pages || ! is_array( $pages ) || false === $pm || ! is_array( $pm ) || false === $gids || ! is_array( $gids ) || false === $grows || ! is_array( $grows ) || '' !== $wpdb->last_error ) {
			return 'ineligible';
		}
		$managed = isset( $meta['tutorpress_managed'] ) ? (string) $meta['tutorpress_managed']->meta_value : '';
		$crev = isset( $meta['tutorpress_course_id'] ) ? (int) $meta['tutorpress_course_id']->meta_value : 0;
		$brev = isset( $meta['tutorpress_bundle_id'] ) ? (int) $meta['tutorpress_bundle_id']->meta_value : 0;
		$model = isset( $meta['TUTORPRESS_PMPRO_membership_model'] ) ? (string) $meta['TUTORPRESS_PMPRO_membership_model']->meta_value : '';
		$pages = array_values( array_unique( array_map( 'intval', $pages ) ) );
		$listed = array(); foreach ( $pm as $row ) { $ids = maybe_unserialize( $row['meta_value'] ); if ( is_array( $ids ) && in_array( $level_id, array_map( 'intval', $ids ), true ) ) { $listed[] = (int) $row['post_id']; } }
		$own = ( 'courses' === $object_type ) ? $crev : $brev;
		$gids = array_values( array_unique( array_map( 'intval', $gids ) ) );
		$gothers = array(); $rg = 0; $req_group = false;
		foreach ( $grows as $row ) { $pid = (int) $row['post_id']; $gid = (int) $row['meta_value']; if ( $pid === $object_id ) { $rg = $gid; } elseif ( $gid > 0 && in_array( $gid, $gids, true ) ) { $gothers[] = $pid; } }
		if ( $rg > 0 && in_array( $rg, $gids, true ) ) {
			$ex = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$wpdb->pmpro_groups}` WHERE id = %d", $rg ) );
			if ( false === $ex || '' !== $wpdb->last_error ) {
				return 'ineligible';
			}
			$req_group = ( (int) $ex === $rg );
		}
		if ( ( 'courses' === $object_type ? $brev : $crev ) > 0 || ( in_array( $object_id, $listed, true ) && $own > 0 && $own !== $object_id ) ) {
			return 'ownership_conflict';
		}
		$others = array_diff( array_filter( array_merge( $pages, $listed, $crev > 0 ? array( $crev ) : array(), $brev > 0 ? array( $brev ) : array(), $gothers ) ), array( $object_id, 0 ) );
		if ( ( in_array( $object_id, $pages, true ) || in_array( $object_id, $listed, true ) || $own === $object_id || $req_group ) && $others ) {
			return 'shared_unlink';
		}
		$ok = ( '1' === $managed && $own === $object_id && ( ( 'courses' === $object_type && in_array( $object_id, $pages, true ) ) || ( 'course-bundle' === $object_type && $req_group ) ) );
		return ( $ok && ! in_array( $model, array( 'category_wise_membership', 'full_website_membership' ), true ) ) ? 'allowed' : 'ineligible';
	}
}

<?php
require_once __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__, 2 ) . '/includes/utilities/class-pmpro-level-deletion-guard.php';
$lid = 980000000 + (int) getmypid();
try {
	tutorpress_pmpro_lds_require_local_site();
	$g = '\\TUTORPRESS_PMPRO\\PMPro_Level_Deletion_Guard';
	$cases = array(
		array( 'm', 'admin_cancelled', false ), array( 'm', 'cancelled', false ), array( 'm', 'changed', false ), array( 'm', 'admin_changed', false ), array( 'm', 'expired', false ), array( 'm', 'inactive', false ),
		array( 'm', 'active', true ), array( 'm', '', true ), array( 'm', 'unknown', true ),
		array( 'o', 'token', true ), array( 'o', 'pending', true ), array( 'o', 'review', true ), array( 'o', '', true ), array( 'o', 'unknown', true ),
		array( 'o', 'success', false ), array( 'o', 'refunded', false ), array( 'o', 'error', false ), array( 'o', 'voided', false ), array( 'o', 'cancelled', false ),
		array( 's', 'cancelled', true ), array( 's', 'active', true ), array( 's', '', true ),
	);
	foreach ( $cases as $c ) {
		$got = 'm' === $c[0] ? $g::membership_status_blocks_deletion( $c[1] ) : ( 'o' === $c[0] ? $g::order_status_blocks_deletion( $c[1] ) : $g::subscription_row_blocks_deletion( $c[1] ) );
		tutorpress_pmpro_lds_assert( $got === $c[2], $c[0] . ':' . (string) $c[1] );
	}
	tutorpress_pmpro_lds_pass( 'status policy' );
	global $wpdb;
	$mu = array( 'user_id' => 0, 'membership_id' => $lid, 'code_id' => 0, 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'startdate' => '2000-01-01 00:00:00' );
	foreach ( array( 'expired', 'active' ) as $st ) { $wpdb->insert( $wpdb->pmpro_memberships_users, array_merge( $mu, array( 'status' => $st ) ) ); }
	$m = $g::membership_inventory( $lid );
	tutorpress_pmpro_lds_assert( ! empty( $m['complete'] ) && ! empty( $m['protected'] ) && 2 === (int) $m['total'], 'm-inv' );
	$sub = array( 'user_id' => $lid, 'membership_level_id' => $lid, 'gateway' => 'none', 'gateway_environment' => 'test' );
	foreach ( array_merge( array( 'active', 'cancelled', '', 'paused' ), array_fill( 0, 101, 'cancelled' ) ) as $i => $st ) { $wpdb->insert( $wpdb->pmpro_subscriptions, array_merge( $sub, array( 'subscription_transaction_id' => 'tp2-' . $lid . '-' . $i, 'status' => $st ) ) ); }
	$s = $g::subscription_inventory( $lid );
	tutorpress_pmpro_lds_assert( ! empty( $s['complete'] ) && ! empty( $s['protected'] ) && (int) $s['total'] >= 101, 's-inv' );
	foreach ( array( 'pmpro_memberships_users' => 'membership_inventory', 'pmpro_subscriptions' => 'subscription_inventory' ) as $p => $fn ) { $GLOBALS['tutorpress_pmpro_lds_reg']['wpdb'][ $p ] = $wpdb->$p; $wpdb->$p = 'tp_lds_missing'; $f = $g::$fn( $lid ); tutorpress_pmpro_lds_assert( empty( $f['complete'] ) && ! empty( $f['protected'] ), 'fail-' . $p ); }
	tutorpress_pmpro_lds_pass( 'inventory' );
	$ord = array( 'membership_id' => $lid, 'user_id' => 0, 'billing_country' => '', 'billing_phone' => '', 'gateway' => 'none', 'gateway_environment' => 'test', 'payment_transaction_id' => '', 'subscription_transaction_id' => '', 'affiliate_id' => '', 'affiliate_subid' => '', 'notes' => '' );
	foreach ( array_merge( array( 'token', 'pending', 'review', '', 'unknown', 'success', 'refunded', 'error', 'voided', 'cancelled' ), array_fill( 0, 101, 'success' ) ) as $i => $st ) { $wpdb->insert( $wpdb->pmpro_membership_orders, array_merge( $ord, array( 'code' => 'tp3-' . $lid . '-' . $i, 'status' => $st ) ) ); }
	$o = $g::order_inventory( $lid );
	tutorpress_pmpro_lds_assert( ! empty( $o['complete'] ) && ! empty( $o['protected'] ) && (int) $o['total'] >= 101 && 'unsaved_checkout_race' === $o['residual'], 'o-inv' );
	$GLOBALS['tutorpress_pmpro_lds_reg']['wpdb']['pmpro_membership_orders'] = $wpdb->pmpro_membership_orders;
	$wpdb->pmpro_membership_orders = 'tp_lds_missing';
	$fo = $g::order_inventory( $lid );
	tutorpress_pmpro_lds_assert( empty( $fo['complete'] ) && ! empty( $fo['protected'] ) && 'unsaved_checkout_race' === $fo['residual'], 'o-fail' );
	tutorpress_pmpro_lds_pass( 'order inventory' );
	foreach ( $GLOBALS['tutorpress_pmpro_lds_reg']['wpdb'] as $p => $v ) { $wpdb->$p = $v; }
	$a = $lid + 2; $b = $lid + 3; $e = $lid + 1; $lm = $wpdb->pmpro_membership_levelmeta;
	foreach ( array( array( 'tutorpress_managed', '1' ), array( 'tutorpress_course_id', (string) $a ) ) as $m ) { $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => $m[0], 'meta_value' => $m[1] ) ); }
	$wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e, 'page_id' => $a ) );
	tutorpress_pmpro_lds_assert( 'allowed' === $g::evaluate( $e, $a, 'courses' ) && 'ineligible' === $g::evaluate( 0, $a, 'courses' ) && 'ownership_conflict' === $g::evaluate( $e, $a, 'course-bundle' ), 'no-pm' );
	$wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e ) ); tutorpress_pmpro_lds_assert( 'ineligible' === $g::evaluate( $e, $a, 'courses' ), 'rev-only' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$lm} WHERE pmpro_membership_level_id = %d", $e ) ); $wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e, 'page_id' => $a ) ); tutorpress_pmpro_lds_assert( 'ineligible' === $g::evaluate( $e, $a, 'courses' ), 'page-only' );
	update_post_meta( $a, '_tutorpress_pmpro_levels', array( $e ) ); $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $b ) ); tutorpress_pmpro_lds_assert( 'ownership_conflict' === $g::evaluate( $e, $a, 'courses' ), 'conflict' );
	delete_post_meta( $a, '_tutorpress_pmpro_levels' ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$lm} WHERE pmpro_membership_level_id = %d", $e ) ); $wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e ) );
	$wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $a ) ); $wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e, 'page_id' => $b ) );
	tutorpress_pmpro_lds_assert( 'shared_unlink' === $g::evaluate( $e, $a, 'courses' ) && 'protected' === $g::evaluate( $lid, $a, 'courses' ), 'shared-prot' );
	$GLOBALS['tutorpress_pmpro_lds_reg']['wpdb']['pmpro_membership_levelmeta'] = $wpdb->pmpro_membership_levelmeta; $wpdb->pmpro_membership_levelmeta = 'tp_lds_missing';
	tutorpress_pmpro_lds_assert( 'ineligible' === $g::evaluate( $e, $a, 'courses' ), 'fail-lm' );
	tutorpress_pmpro_lds_pass( 'course ownership' );
	foreach ( $GLOBALS['tutorpress_pmpro_lds_reg']['wpdb'] as $p => $v ) { $wpdb->$p = $v; }
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$lm} WHERE pmpro_membership_level_id = %d", $e ) ); $wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e ) );
	foreach ( array( array( 'tutorpress_managed', '1' ), array( 'tutorpress_course_id', (string) $a ), array( 'TUTORPRESS_PMPRO_membership_model', 'category_wise_membership' ) ) as $m ) { $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => $m[0], 'meta_value' => $m[1] ) ); }
	$wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e, 'page_id' => $a ) );
	tutorpress_pmpro_lds_assert( 'ineligible' === $g::evaluate( $e, $a, 'courses' ), 'cat-wise' );
	$wpdb->update( $lm, array( 'meta_value' => 'full_website_membership' ), array( 'pmpro_membership_level_id' => $e, 'meta_key' => 'TUTORPRESS_PMPRO_membership_model' ) );
	tutorpress_pmpro_lds_assert( 'ineligible' === $g::evaluate( $e, $a, 'courses' ), 'full-site' );
	$wpdb->delete( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => 'TUTORPRESS_PMPRO_membership_model' ) );
	tutorpress_pmpro_lds_assert( 'allowed' === $g::evaluate( $e, $a, 'courses' ), 'model-gate' );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$lm} WHERE pmpro_membership_level_id = %d", $e ) ); $wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e ) ); $c = $lid + 4;
	$wpdb->insert( $wpdb->pmpro_groups, array( 'name' => 'tp4b-' . $e, 'allow_multiple_selections' => 0 ) ); $gid = (int) $wpdb->insert_id;
	$wpdb->insert( $wpdb->pmpro_membership_levels_groups, array( 'level' => $e, 'group' => $gid ) );
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $c, 'meta_key' => '_tutorpress_pmpro_group_id', 'meta_value' => (string) $gid ) );
	foreach ( array( array( 'tutorpress_managed', '1' ), array( 'tutorpress_bundle_id', (string) $c ) ) as $m ) { $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => $m[0], 'meta_value' => $m[1] ) ); }
	tutorpress_pmpro_lds_assert( 'allowed' === $g::evaluate( $e, $c, 'course-bundle' ), 'bundle' );
	tutorpress_pmpro_lds_pass( 'bundle domain' );
} catch ( Throwable $e ) { exit( 1 ); } finally { global $wpdb; foreach ( $GLOBALS['tutorpress_pmpro_lds_reg']['wpdb'] as $p => $v ) { $wpdb->$p = $v; } $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $lid ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_subscriptions} WHERE membership_level_id = %d", $lid ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_orders} WHERE membership_id = %d", $lid ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d", $lid + 1 ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d", $lid + 1 ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d", $lid + 1 ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_groups} WHERE name = %s", 'tp4b-' . ( $lid + 1 ) ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE ( post_id IN (%d,%d) AND meta_key = %s ) OR ( post_id = %d AND meta_key = %s )", $lid + 2, $lid + 3, '_tutorpress_pmpro_levels', $lid + 4, '_tutorpress_pmpro_group_id' ) ); tutorpress_pmpro_lds_cleanup(); }

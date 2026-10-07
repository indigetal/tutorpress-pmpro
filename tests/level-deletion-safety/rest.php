<?php
require_once __DIR__ . '/bootstrap.php';
$lid = 980000000 + (int) getmypid();
$tutorpress_pmpro_lds_failed = false;
try {
	tutorpress_pmpro_lds_require_local_site(); require_once dirname( __DIR__, 2 ) . '/includes/utilities/class-pmpro-level-cleanup.php'; require_once dirname( __DIR__, 2 ) . '/includes/rest/class-pmpro-subscriptions-controller.php'; $ctl = new TutorPress_PMPro_Subscriptions_Controller(); $c = '\\TUTORPRESS_PMPRO\\PMPro_Level_Deletion_Coordinator'; $e = $lid + 1; $a = $lid + 2; $b = $lid + 3; $f = $lid + 4; $g = $lid + 5; global $wpdb; wp_set_current_user( 1 ); tutorpress_pmpro_lds_assert( $a === (int) wp_insert_post( array( 'import_id' => $a, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp10a-' . $a ) ) && $b === (int) wp_insert_post( array( 'import_id' => $b, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'tp10a-' . $b ) ), 'posts' ); $GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $a; $GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $b; if ( '1' === getenv( 'TUTORPRESS_PMPRO_LDS_FORCE_REST_FAILURE' ) ) { tutorpress_pmpro_lds_assert( false, 'rest-forced-failure' ); } if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); } $req = function( $id, $oid ) { $r = new WP_REST_Request( 'DELETE', '/tutorpress/v1/subscriptions/' . (int) $id ); $r->set_param( 'id', (int) $id ); if ( $oid ) { $r->set_param( 'object_id', $oid ); } return $r; }; $err = function( $r ) { $d = $r instanceof WP_Error ? null : $r->get_data(); return $r instanceof WP_Error ? array( (int) ( $r->get_error_data()['status'] ?? 0 ), $r->get_error_code() ) : array( (int) $r->get_status(), ( is_array( $d ) && is_array( $d['data'] ?? null ) && ! empty( $d['data']['warning'] ) ) ? 'warn' : 'ok' ); }; $lm = $wpdb->pmpro_membership_levelmeta; $mk = function( $id, $rev, $page ) use ( $wpdb, $lm, $a, $c ) { $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $id, 'name' => 'tp10a-' . $id, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) ); foreach ( array( array( 'tutorpress_managed', '1' ), array( 'tutorpress_course_id', (string) $rev ) ) as $m ) { $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $id, 'meta_key' => $m[0], 'meta_value' => $m[1] ) ); } $wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $id, 'page_id' => $page ) ); update_post_meta( $a, '_tutorpress_pmpro_levels', array( $id ) ); if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); } $GLOBALS['tutorpress_pmpro_lds_reg']['locks'][] = $c::advisory_lock_name( $id ); };
	tutorpress_pmpro_lds_assert( array( 400, 'missing_object_id' ) === $err( $ctl->delete_subscription_plan( $req( $e, 0 ) ) ) && array( 404, 'invalid_course' ) === $err( $ctl->delete_subscription_plan( $req( $e, $b ) ) ) && array( 404, 'missing' ) === $err( $ctl->delete_subscription_plan( $req( $lid, $a ) ) ), 'map-404' ); $mk( $e, $a, $a ); $wpdb->insert( $wpdb->pmpro_memberships_users, array( 'user_id' => 0, 'membership_id' => $e, 'status' => 'active', 'code_id' => 0, 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'startdate' => '2000-01-01 00:00:00' ) ); $st = '\\TUTORPRESS_PMPRO\\PMPro_Level_Removal_State'; $clr = function() use ( $a, $st ) { delete_post_meta( $a, $st::META_KEY ); wp_cache_delete( $a, 'post_meta' ); $st::invalidate_object( $a ); }; $snap = function() use ( $wpdb, $a, $e, $st ) { return array( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $a, $st::META_KEY ) ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d", $e ) ), (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $e ) ) ); }; tutorpress_pmpro_lds_assert( array( 200, 'ok' ) === $err( $ctl->delete_subscription_plan( $req( $e, $a ) ) ) && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $e ) ), 'prot' ); $clr(); $wpdb->delete( $wpdb->pmpro_memberships_users, array( 'membership_id' => $e ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s", $e, 'tutorpress_course_id' ) ); $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $b ) ); $own0 = $snap(); tutorpress_pmpro_lds_assert( array( 409, 'ownership_conflict' ) === $err( $ctl->delete_subscription_plan( $req( $e, $a ) ) ) && $own0 === $snap(), 'own' ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s", $e, 'tutorpress_managed' ) ); $wpdb->query( $wpdb->prepare( "UPDATE {$lm} SET meta_value = %s WHERE pmpro_membership_level_id = %d AND meta_key = %s", (string) $a, $e, 'tutorpress_course_id' ) ); tutorpress_pmpro_lds_assert( array( 409, 'ineligible' ) === $err( $ctl->delete_subscription_plan( $req( $e, $a ) ) ), 'inel' ); $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => 'tutorpress_managed', 'meta_value' => '1' ) ); $GLOBALS['tutorpress_pmpro_lds_lock'] = '0'; tutorpress_pmpro_lds_assert( array( 409, 'busy' ) === $err( $ctl->delete_subscription_plan( $req( $e, $a ) ) ), 'busy' ); unset( $GLOBALS['tutorpress_pmpro_lds_lock'] ); $GLOBALS['tutorpress_pmpro_lds_reg']['wpdb']['pmpro_membership_levels'] = $wpdb->pmpro_membership_levels; $wpdb->pmpro_membership_levels = 'tp_lds_missing'; tutorpress_pmpro_lds_assert( array( 500, 'infrastructure' ) === $err( $ctl->delete_subscription_plan( $req( $e, $a ) ) ), 'infra' ); $wpdb->pmpro_membership_levels = $GLOBALS['tutorpress_pmpro_lds_reg']['wpdb']['pmpro_membership_levels'];
	$wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e, 'page_id' => $b ) ); $shared = $err( $ctl->delete_subscription_plan( $req( $e, $a ) ) ); tutorpress_pmpro_lds_assert( in_array( $shared, array( array( 200, 'ok' ), array( 200, 'warn' ) ), true ) && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $e ) ), 'shared' ); $clr(); $wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e ) ); $wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $e, 'page_id' => $a ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s", $e, 'tutorpress_course_id' ) ); $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $e, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $a ) ); update_post_meta( $a, '_tutorpress_pmpro_levels', array( $e ) ); $rf = function( $id ) use ( $wpdb ) { $wpdb->delete( $wpdb->pmpro_membership_levels, array( 'id' => $id ), array( '%d' ) ); }; add_action( 'pmpro_delete_membership_level', $rf, 1 ); tutorpress_pmpro_lds_assert( array( 409, 'conflict' ) === $err( $ctl->delete_subscription_plan( $req( $e, $a ) ) ), 'race' ); remove_action( 'pmpro_delete_membership_level', $rf, 1 ); $mk( $f, $a, $a ); $GLOBALS['tutorpress_pmpro_lds_unlock'] = '0'; tutorpress_pmpro_lds_assert( array( 200, 'warn' ) === $err( $ctl->delete_subscription_plan( $req( $f, $a ) ) ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $f ) ), 'warn' ); unset( $GLOBALS['tutorpress_pmpro_lds_unlock'] ); $mk( $g, $a, $a ); tutorpress_pmpro_lds_assert( array( 200, 'ok' ) === $err( $ctl->delete_subscription_plan( $req( $g, $a ) ) ) && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $g ) ), 'ok' );
	$uid = wp_insert_user( array( 'user_login' => 'tp10a-' . $lid, 'user_pass' => 'x', 'role' => 'subscriber' ) ); wp_set_current_user( $uid ); $n = 0; $qf = function( $sql ) use ( &$n ) { if ( false !== stripos( $sql, 'GET_LOCK' ) ) { $n++; } return $sql; }; add_filter( 'query', $qf ); $forb0 = $snap(); $d = rest_do_request( $req( $a, $a ) )->get_data(); tutorpress_pmpro_lds_assert( is_array( $d ) && 'rest_forbidden' === ( $d['code'] ?? '' ) && 403 === (int) ( $d['data']['status'] ?? 0 ) && false !== strpos( (string) ( $d['message'] ?? '' ), 'authorzied' ) && 0 === $n && $forb0 === $snap(), 'tutor-id' ); remove_filter( 'query', $qf ); wp_set_current_user( 1 ); $md = rest_do_request( $req( $lid, 0 ) )->get_data(); tutorpress_pmpro_lds_assert( 'missing_object_id' === ( $md['code'] ?? '' ) && 400 === (int) ( $md['data']['status'] ?? 0 ), 'perm-miss' ); $ed = wp_insert_user( array( 'user_login' => 'tp10a-e-' . $lid, 'user_pass' => 'x', 'role' => 'author' ) ); ( new WP_User( $ed ) )->add_cap( 'pmpro_membershiplevels' ); wp_set_current_user( $ed ); $fd = rest_do_request( $req( $lid, $a ) )->get_data(); tutorpress_pmpro_lds_assert( current_user_can( 'edit_posts' ) && current_user_can( 'pmpro_membershiplevels' ) && ! current_user_can( 'edit_post', $a ) && 'rest_forbidden' === ( $fd['code'] ?? '' ) && 403 === (int) ( $fd['data']['status'] ?? 0 ), 'perm-edit-posts' ); $inst = wp_insert_user( array( 'user_login' => 'tp10a-i-' . $lid, 'user_pass' => 'x', 'role' => 'author' ) ); ( new WP_User( $inst ) )->remove_cap( 'pmpro_membershiplevels' ); wp_update_post( array( 'ID' => $a, 'post_author' => $inst ) ); wp_set_current_user( $inst ); 		$ad = rest_do_request( $req( $lid, $a ) )->get_data(); tutorpress_pmpro_lds_assert( current_user_can( 'edit_post', $a ) && ! current_user_can( 'pmpro_membershiplevels' ) && 'missing' === ( $ad['code'] ?? '' ) && 404 === (int) ( $ad['data']['status'] ?? 0 ), 'perm-instr' ); wp_set_current_user( 1 ); tutorpress_pmpro_lds_pass( 'rest-delete' );
	if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); } $h = $lid + 6; tutorpress_pmpro_lds_assert( $h === (int) wp_insert_post( array( 'import_id' => $h, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp10b-' . $h ) ), 'bpost' ); $GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $h; if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); } $cr = function( $oid ) { $r = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions' ); $r->set_param( 'object_id', $oid ); $r->set_param( 'plan_name', 'tp10b' ); $r->set_param( 'regular_price', 1 ); return $r; }; $cid = function( $r ) { $d = ( $r instanceof WP_Error ) ? array() : $r->get_data(); return (int) ( is_array( $d ) ? ( $d['data']['id'] ?? 0 ) : 0 ); }; $getp = function( $oid, $key, $meth ) use ( $ctl ) { $r = new WP_REST_Request( 'GET', '/tutorpress/v1/subscriptions' ); $r->set_param( $key, $oid ); $d = $ctl->$meth( $r )->get_data(); return array_map( 'intval', wp_list_pluck( (array) ( is_array( $d ) && isset( $d['data']['plans'] ) ? $d['data']['plans'] : array() ), 'id' ) ); };
	$p1 = $cid( $ctl->create_subscription_plan( $cr( $a ) ) ); $p2 = $cid( $ctl->create_subscription_plan( $cr( $a ) ) ); $pm = array_map( 'intval', (array) get_post_meta( $a, '_tutorpress_pmpro_levels', true ) ); $gp = $getp( $a, 'course_id', 'get_course_subscriptions' ); $mi = apply_filters( 'tutor_course_mini_info', array(), get_post( $a ) ); $bp = array_map( 'intval', wp_list_pluck( (array) ( is_array( $mi ) && isset( $mi['plans'] ) ? $mi['plans'] : array() ), 'id' ) ); tutorpress_pmpro_lds_assert( $p1 && $p2 && $p1 !== $p2 && in_array( $p1, $pm, true ) && in_array( $p2, $pm, true ) && in_array( $p1, $gp, true ) && in_array( $p2, $gp, true ) && ( array() === $bp || ( in_array( $p1, $bp, true ) && in_array( $p2, $bp, true ) ) ) && $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p1, $a ) ), 'create-vis' );
	$n0 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" ); $dr = function( $id, $oid ) { $r = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/' . (int) $id . '/duplicate' ); $r->set_param( 'id', (int) $id ); if ( $oid ) { $r->set_param( 'object_id', $oid ); } return $r; }; tutorpress_pmpro_lds_assert( array( 400, 'missing_object_id' ) === $err( $ctl->duplicate_subscription_plan( $dr( $p1, 0 ) ) ) && $n0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" ) && array( 404, 'invalid_course' ) === $err( $ctl->duplicate_subscription_plan( $dr( $p1, $b ) ) ) && $n0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" ), 'dup-pre' );
	$d1 = $cid( $ctl->duplicate_subscription_plan( $dr( $p1, $a ) ) ); $pm2 = array_map( 'intval', (array) get_post_meta( $a, '_tutorpress_pmpro_levels', true ) ); tutorpress_pmpro_lds_assert( $d1 && in_array( $d1, $pm2, true ) && '1' === (string) get_pmpro_membership_level_meta( $d1, 'tutorpress_managed', true ) && $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $d1, $a ) ), 'dup-crs' );
	$p3 = $cid( $ctl->create_subscription_plan( $cr( $h ) ) ); $gid = (int) get_post_meta( $h, '_tutorpress_pmpro_group_id', true ); tutorpress_pmpro_lds_assert( $p3 && $gid && $wpdb->get_var( $wpdb->prepare( "SELECT level FROM {$wpdb->pmpro_membership_levels_groups} WHERE level = %d AND `group` = %d", $p3, $gid ) ) && in_array( $p3, $getp( $h, 'bundle_id', 'get_bundle_subscriptions' ), true ), 'bundle' ); tutorpress_pmpro_lds_pass( 'rest-create' );
	$state = '\\TUTORPRESS_PMPRO\\PMPro_Level_Removal_State';
	$mkmap = function( $pairs, $kinds, $removed ) {
		return array(
			'version'             => 1,
			'levels'              => $pairs,
			'blocked_kinds'       => $kinds,
			'removed_restriction' => $removed,
		);
	};
	update_post_meta( $a, $state::META_KEY, $mkmap( array( (int) $p1 => array( 'state' => 'retired', 'kind' => 'one_time' ) ), array( 'one_time' ), false ) );
	$state::invalidate_object( $a );
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $a, $state::META_KEY ) );
	$got = maybe_unserialize( $raw );
	tutorpress_pmpro_lds_assert( is_array( $got ) && 'retired' === ( $got['levels'][ (int) $p1 ]['state'] ?? '' ) && 'one_time' === ( $got['levels'][ (int) $p1 ]['kind'] ?? '' ) && in_array( 'one_time', (array) ( $got['blocked_kinds'] ?? array() ), true ) && false === ( $got['removed_restriction'] ?? null ), 'map-retired' );
	$vis = $getp( $a, 'course_id', 'get_course_subscriptions' );
	tutorpress_pmpro_lds_assert( ! in_array( (int) $p1, $vis, true ) && in_array( (int) $p2, $vis, true ) && in_array( (int) $d1, $vis, true ), 'get-retired' );
	update_post_meta( $a, $state::META_KEY, $mkmap( array( (int) $p1 => array( 'state' => 'retired', 'kind' => 'one_time' ), (int) $p2 => array( 'state' => 'unlinked', 'kind' => 'recurring' ) ), array( 'one_time', 'recurring' ), true ) );
	$state::invalidate_object( $a );
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $a, $state::META_KEY ) );
	$got = maybe_unserialize( $raw );
	tutorpress_pmpro_lds_assert( is_array( $got ) && 'retired' === ( $got['levels'][ (int) $p1 ]['state'] ?? '' ) && 'unlinked' === ( $got['levels'][ (int) $p2 ]['state'] ?? '' ) && 'recurring' === ( $got['levels'][ (int) $p2 ]['kind'] ?? '' ) && in_array( 'recurring', (array) ( $got['blocked_kinds'] ?? array() ), true ) && true === ( $got['removed_restriction'] ?? null ), 'map-unlinked' );
	$vis = $getp( $a, 'course_id', 'get_course_subscriptions' );
	tutorpress_pmpro_lds_assert( ! in_array( (int) $p1, $vis, true ) && ! in_array( (int) $p2, $vis, true ) && in_array( (int) $d1, $vis, true ), 'get-unlinked' );
	update_post_meta( $h, $state::META_KEY, $mkmap( array( (int) $p3 => array( 'state' => 'unlinked', 'kind' => 'one_time' ) ), array( 'one_time' ), true ) );
	$state::invalidate_object( $h );
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $h, $state::META_KEY ) );
	$got = maybe_unserialize( $raw );
	tutorpress_pmpro_lds_assert( is_array( $got ) && 'unlinked' === ( $got['levels'][ (int) $p3 ]['state'] ?? '' ) && 'one_time' === ( $got['levels'][ (int) $p3 ]['kind'] ?? '' ) && true === ( $got['removed_restriction'] ?? null ), 'map-bundle' );
	$bvis = $getp( $h, 'bundle_id', 'get_bundle_subscriptions' );
	tutorpress_pmpro_lds_assert( ! in_array( (int) $p3, $bvis, true ), 'get-bundle' );
	add_post_meta( $a, $state::META_KEY, array( 'version' => 2 ), false );
	wp_cache_delete( $a, 'post_meta' );
	$state::invalidate_object( $a );
	$nrows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $a, $state::META_KEY ) );
	$bad = $getp( $a, 'course_id', 'get_course_subscriptions' );
	tutorpress_pmpro_lds_assert( 2 === $nrows && array() === $bad, 'get-invalid' );
	tutorpress_pmpro_lds_pass( 'rest-get-removal' );
	delete_post_meta( $a, $state::META_KEY );
	wp_cache_delete( $a, 'post_meta' );
	$state::invalidate_object( $a );
	update_post_meta( $a, $state::META_KEY, $mkmap( array( (int) $p1 => array( 'state' => 'retired', 'kind' => 'one_time' ) ), array( 'one_time' ), false ) );
	$state::invalidate_object( $a );
	$name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $p1 ) );
	$page = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p1, $a ) );
	$rev = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $p1, 'tutorpress_course_id' ) );
	$ur = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/' . (int) $p1 );
	$ur->set_param( 'id', (int) $p1 );
	$ur->set_param( 'object_id', $a );
	$ur->set_param( 'plan_name', 'tp9a2-stale' );
	$ur->set_param( 'payment_type', 'recurring' );
	tutorpress_pmpro_lds_assert( array( 409, 'conflict' ) === $err( $ctl->update_subscription_plan( $ur ) ), 'upd-marked' );
	$name2 = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $p1 ) );
	$page2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p1, $a ) );
	$rev2 = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $p1, 'tutorpress_course_id' ) );
	tutorpress_pmpro_lds_assert( $name === $name2 && $page === $page2 && (string) $rev === (string) $rev2, 'upd-marked-keep' );
	$p2name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $p2 ) );
	add_post_meta( $a, $state::META_KEY, array( 'version' => 2 ), false );
	wp_cache_delete( $a, 'post_meta' );
	$state::invalidate_object( $a );
	$ur->set_param( 'id', (int) $p2 );
	$ur->set_param( 'plan_name', 'tp9a2-invalid' );
	tutorpress_pmpro_lds_assert( array( 500, 'invalid_state' ) === $err( $ctl->update_subscription_plan( $ur ) ), 'upd-invalid' );
	$p2name2 = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $p2 ) );
	tutorpress_pmpro_lds_assert( $p2name === $p2name2, 'upd-invalid-keep' );
	tutorpress_pmpro_lds_pass( 'rest-update-guard' );
	$sr = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/sort' );
	$sr->set_param( 'object_id', $a );
	$sr->set_param( 'ordered_ids', array( (int) $p2, (int) $d1 ) );
	$pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p1, $a ) );
	tutorpress_pmpro_lds_assert( array( 500, 'invalid_state' ) === $err( $ctl->sort_subscription_plans( $sr ) ), 'sort-invalid' );
	$pages2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p1, $a ) );
	tutorpress_pmpro_lds_assert( $pages === $pages2, 'sort-invalid-keep' );
	delete_post_meta( $a, $state::META_KEY );
	wp_cache_delete( $a, 'post_meta' );
	$state::invalidate_object( $a );
	update_post_meta( $a, $state::META_KEY, $mkmap( array( (int) $p1 => array( 'state' => 'retired', 'kind' => 'one_time' ) ), array( 'one_time' ), false ) );
	$state::invalidate_object( $a );
	tutorpress_pmpro_lds_assert( array( 200, 'ok' ) === $err( $ctl->sort_subscription_plans( $sr ) ), 'sort-visible' );
	tutorpress_pmpro_lds_pass( 'rest-sort-guard' );
	\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $a, $p1 );
	$kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p1, $a ) );
	tutorpress_pmpro_lds_assert( 1 === $kept, 'sort-retired-before' );
	tutorpress_pmpro_lds_assert( array( 200, 'ok' ) === $err( $ctl->sort_subscription_plans( $sr ) ), 'sort-retired' );
	$kept2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p1, $a ) );
	tutorpress_pmpro_lds_assert( 1 === $kept2, 'sort-retired-keep' );
	delete_post_meta( $a, $state::META_KEY );
	wp_cache_delete( $a, 'post_meta' );
	$state::invalidate_object( $a );
	update_post_meta( $a, $state::META_KEY, $mkmap( array( (int) $p2 => array( 'state' => 'unlinked', 'kind' => 'recurring' ) ), array( 'recurring' ), true ) );
	$state::invalidate_object( $a );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p2, $a ) );
	$p2pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p2, $a ) );
	\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $a, $p2 );
	$p2pages2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $p2, $a ) );
	tutorpress_pmpro_lds_assert( 0 === $p2pages && 0 === $p2pages2, 'ensure-unlinked' );
	delete_post_meta( $a, $state::META_KEY );
	wp_cache_delete( $a, 'post_meta' );
	$state::invalidate_object( $a );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $d1, $a ) );
	$d1pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $d1, $a ) );
	\TUTORPRESS_PMPRO\PMPro_Association::ensure_course_level_association( $a, $d1 );
	$d1pages2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $d1, $a ) );
	tutorpress_pmpro_lds_assert( 0 === $d1pages && 1 === $d1pages2, 'ensure-unmarked' );
	tutorpress_pmpro_lds_pass( 'rest-association-guard' );
	$c1 = $lid + 7;
	$c2 = $lid + 8;
	$c3 = $lid + 9;
	$c4 = $lid + 10;
	$c5 = $lid + 11;
	foreach ( array( $c1, $c2, $c3, $c4, $c5 ) as $pid15 ) {
		$GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $pid15;
	}
	tutorpress_pmpro_lds_assert( $c1 === (int) wp_insert_post( array( 'import_id' => $c1, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp15b-' . $c1 ) ) && $c2 === (int) wp_insert_post( array( 'import_id' => $c2, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp15b-' . $c2 ) ) && $c3 === (int) wp_insert_post( array( 'import_id' => $c3, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp15b-' . $c3 ) ) && $c4 === (int) wp_insert_post( array( 'import_id' => $c4, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp15b-' . $c4 ) ) && $c5 === (int) wp_insert_post( array( 'import_id' => $c5, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp15b-' . $c5 ) ), '15b-posts' );
	$seed = function( $id, $owner, $pages, $signups, $with_member ) use ( $wpdb, $lm, $c ) {
		$wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $id, 'name' => 'tp15b-' . $id, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => $signups ) );
		unset( $GLOBALS['pmpro_levels'][ $id ] );
		$wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $id, 'meta_key' => 'tutorpress_managed', 'meta_value' => '1' ) );
		$wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $id, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $owner ) );
		foreach ( $pages as $page_id ) {
			$wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $id, 'page_id' => $page_id ) );
			$list   = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $page_id, '_tutorpress_pmpro_levels', true ) ) ) );
			$list[] = (int) $id;
			update_post_meta( $page_id, '_tutorpress_pmpro_levels', $list );
		}
		if ( $with_member ) {
			$wpdb->insert( $wpdb->pmpro_memberships_users, array( 'user_id' => 0, 'membership_id' => $id, 'status' => 'active', 'code_id' => 0, 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'startdate' => '2000-01-01 00:00:00' ) );
		}
		if ( true === $c::session_in_transaction() ) {
			$wpdb->query( 'COMMIT' );
		}
		$GLOBALS['tutorpress_pmpro_lds_reg']['locks'][] = $c::advisory_lock_name( $id );
	};
	$read = function( $course_id ) use ( $wpdb, $state ) {
		wp_cache_delete( $course_id, 'post_meta' );
		$state::invalidate_object( $course_id );
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $course_id, $state::META_KEY ) );
		return is_string( $raw ) ? maybe_unserialize( $raw ) : null;
	};
	$sp  = $lid + 12;
	$seed( $sp, $c1, array( $c1, $c2 ), 1, true );
	$rsp = $ctl->delete_subscription_plan( $req( $sp, $c1 ) );
	$pl  = $read( $c1 );
	tutorpress_pmpro_lds_assert( in_array( $err( $rsp ), array( array( 200, 'ok' ), array( 200, 'warn' ) ), true ) && 'PMPro membership level removed.' === (string) ( $rsp->get_data()['message'] ?? '' ) && is_array( $pl ) && is_int( $pl['version'] ?? null ) && 1 === $pl['version'] && array( $sp ) === array_keys( $pl['levels'] ) && 'unlinked' === ( $pl['levels'][ $sp ]['state'] ?? '' ) && 'one_time' === ( $pl['levels'][ $sp ]['kind'] ?? '' ) && array( 'one_time' ) === ( $pl['blocked_kinds'] ?? null ) && true === ( $pl['removed_restriction'] ?? null ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $c1, $state::META_KEY ) ) && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT allow_signups FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $sp ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $sp, $c1 ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $sp, 'tutorpress_course_id' ) ) && ! in_array( $sp, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $c1, '_tutorpress_pmpro_levels' ) ) ) ), true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $sp, $c2 ) ) && in_array( $sp, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $c2, '_tutorpress_pmpro_levels' ) ) ) ), true ) && (string) $sp === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $sp ) ) && (string) $sp === (string) $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $sp ) ), '15b-shared-prot' );
	$su  = $lid + 13;
	$seed( $su, $c3, array( $c3, $c2 ), 1, false );
	$rsu = $ctl->delete_subscription_plan( $req( $su, $c3 ) );
	$plu = $read( $c3 );
	tutorpress_pmpro_lds_assert( in_array( $err( $rsu ), array( array( 200, 'ok' ), array( 200, 'warn' ) ), true ) && 'PMPro membership level removed.' === (string) ( $rsu->get_data()['message'] ?? '' ) && is_array( $plu ) && is_int( $plu['version'] ?? null ) && 1 === $plu['version'] && array( $su ) === array_keys( $plu['levels'] ) && 'unlinked' === ( $plu['levels'][ $su ]['state'] ?? '' ) && 'one_time' === ( $plu['levels'][ $su ]['kind'] ?? '' ) && array( 'one_time' ) === ( $plu['blocked_kinds'] ?? null ) && true === ( $plu['removed_restriction'] ?? null ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $c3, $state::META_KEY ) ) && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT allow_signups FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $su ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $su, $c3 ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $su, 'tutorpress_course_id' ) ) && ! in_array( $su, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $c3, '_tutorpress_pmpro_levels' ) ) ) ), true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $su, $c2 ) ) && in_array( $su, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $c2, '_tutorpress_pmpro_levels' ) ) ) ), true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $sp, $c2 ) ) && (string) $su === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $su ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $su ) ), '15b-shared-unprot' );
	$so  = $lid + 14;
	$seed( $so, $c4, array( $c4 ), 1, true );
	$rso = $ctl->delete_subscription_plan( $req( $so, $c4 ) );
	$plo = $read( $c4 );
	tutorpress_pmpro_lds_assert( array( 200, 'ok' ) === $err( $rso ) && 'PMPro membership level removed.' === (string) ( $rso->get_data()['message'] ?? '' ) && is_array( $plo ) && is_int( $plo['version'] ?? null ) && 1 === $plo['version'] && array( $so ) === array_keys( $plo['levels'] ) && 'retired' === ( $plo['levels'][ $so ]['state'] ?? '' ) && 'one_time' === ( $plo['levels'][ $so ]['kind'] ?? '' ) && array( 'one_time' ) === ( $plo['blocked_kinds'] ?? null ) && false === ( $plo['removed_restriction'] ?? null ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $c4, $state::META_KEY ) ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT allow_signups FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $so ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $so, $c4 ) ) && (string) $c4 === (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $so, 'tutorpress_course_id' ) ) && in_array( $so, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $c4, '_tutorpress_pmpro_levels' ) ) ) ), true ) && (string) $so === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $so ) ) && (string) $so === (string) $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $so ) ), '15b-sole-prot' );
	$sd  = $lid + 15;
	$seed( $sd, $c5, array( $c5 ), 1, false );
	$rsd = $ctl->delete_subscription_plan( $req( $sd, $c5 ) );
	$pld = $read( $c5 );
	tutorpress_pmpro_lds_assert( array( 200, 'ok' ) === $err( $rsd ) && 'PMPro membership level removed.' === (string) ( $rsd->get_data()['message'] ?? '' ) && null === $pld && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $c5, $state::META_KEY ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $sd ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $sd, $c5 ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $sd, 'tutorpress_course_id' ) ) && ! in_array( $sd, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $c5, '_tutorpress_pmpro_levels' ) ) ) ), true ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $sd ) ), '15b-sole-unprot' );
	tutorpress_pmpro_lds_pass( 'rest-course-removal' );
	$b1 = $lid + 16;
	$b2 = $lid + 17;
	$b3 = $lid + 18;
	$b4 = $lid + 19;
	$b5 = $lid + 20;
	foreach ( array( $b1, $b2, $b3, $b4, $b5 ) as $pid15c ) {
		$GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $pid15c;
	}
	tutorpress_pmpro_lds_assert( $b1 === (int) wp_insert_post( array( 'import_id' => $b1, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp15c-' . $b1 ) ) && $b2 === (int) wp_insert_post( array( 'import_id' => $b2, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp15c-' . $b2 ) ) && $b3 === (int) wp_insert_post( array( 'import_id' => $b3, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp15c-' . $b3 ) ) && $b4 === (int) wp_insert_post( array( 'import_id' => $b4, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp15c-' . $b4 ) ) && $b5 === (int) wp_insert_post( array( 'import_id' => $b5, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp15c-' . $b5 ) ), '15c-posts' );
	$bseed = function( $id, $owner, $pages, $signups, $with_member, $group_post ) use ( $wpdb, $lm, $c ) {
		$wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $id, 'name' => 'tp15c-' . $id, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => $signups ) );
		unset( $GLOBALS['pmpro_levels'][ $id ] );
		$wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $id, 'meta_key' => 'tutorpress_managed', 'meta_value' => '1' ) );
		$wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $id, 'meta_key' => 'tutorpress_bundle_id', 'meta_value' => (string) $owner ) );
		foreach ( $pages as $page_id ) {
			$wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $id, 'page_id' => $page_id ) );
			$list   = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $page_id, '_tutorpress_pmpro_levels', true ) ) ) );
			$list[] = (int) $id;
			update_post_meta( $page_id, '_tutorpress_pmpro_levels', $list );
		}
		if ( $with_member ) {
			$wpdb->insert( $wpdb->pmpro_memberships_users, array( 'user_id' => 0, 'membership_id' => $id, 'status' => 'active', 'code_id' => 0, 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => 'Month', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'startdate' => '2000-01-01 00:00:00' ) );
		}
		if ( $group_post ) {
			$wpdb->insert( $wpdb->pmpro_groups, array( 'name' => 'tp15c-' . $id, 'allow_multiple_selections' => 0 ) );
			$gid = (int) $wpdb->insert_id;
			update_post_meta( $group_post, '_tutorpress_pmpro_group_id', $gid );
			$wpdb->insert( $wpdb->pmpro_membership_levels_groups, array( 'level' => $id, 'group' => $gid ) );
			$GLOBALS['tutorpress_pmpro_lds_15c_groups'][ $id ] = $gid;
		}
		if ( true === $c::session_in_transaction() ) {
			$wpdb->query( 'COMMIT' );
		}
		$GLOBALS['tutorpress_pmpro_lds_reg']['locks'][] = $c::advisory_lock_name( $id );
	};
	$bsp  = $lid + 21;
	$bseed( $bsp, $b1, array( $b1, $b2 ), 1, true, 0 );
	$rbsp = $ctl->delete_subscription_plan( $req( $bsp, $b1 ) );
	$plb  = $read( $b1 );
	tutorpress_pmpro_lds_assert( in_array( $err( $rbsp ), array( array( 200, 'ok' ), array( 200, 'warn' ) ), true ) && 'PMPro membership level removed.' === (string) ( $rbsp->get_data()['message'] ?? '' ) && is_array( $plb ) && is_int( $plb['version'] ?? null ) && 1 === $plb['version'] && array( $bsp ) === array_keys( $plb['levels'] ) && 'unlinked' === ( $plb['levels'][ $bsp ]['state'] ?? '' ) && 'one_time' === ( $plb['levels'][ $bsp ]['kind'] ?? '' ) && array( 'one_time' ) === ( $plb['blocked_kinds'] ?? null ) && true === ( $plb['removed_restriction'] ?? null ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $b1, $state::META_KEY ) ) && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT allow_signups FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bsp ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bsp, $b1 ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $bsp, 'tutorpress_bundle_id' ) ) && ! in_array( $bsp, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b1, '_tutorpress_pmpro_levels' ) ) ) ), true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bsp, $b2 ) ) && in_array( $bsp, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b2, '_tutorpress_pmpro_levels' ) ) ) ), true ) && (string) $bsp === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bsp ) ) && (string) $bsp === (string) $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $bsp ) ), '15c-shared-prot' );
	$GLOBALS['tutorpress_pmpro_lds_flush_group'] = 1;
	$rbsp2 = $ctl->delete_subscription_plan( $req( $bsp, $b1 ) );
	$plb2  = $read( $b1 );
	tutorpress_pmpro_lds_assert( array( 200, 'warn' ) === $err( $rbsp2 ) && 'PMPro membership level removed.' === (string) ( $rbsp2->get_data()['message'] ?? '' ) && $plb2 === $plb && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $b1, $state::META_KEY ) ) && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT allow_signups FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bsp ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bsp, $b1 ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $bsp, 'tutorpress_bundle_id' ) ) && ! in_array( $bsp, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b1, '_tutorpress_pmpro_levels' ) ) ) ), true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bsp, $b2 ) ) && in_array( $bsp, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b2, '_tutorpress_pmpro_levels' ) ) ) ), true ) && (string) $bsp === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bsp ) ) && (string) $bsp === (string) $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $bsp ) ), '15c-retry' );
	unset( $GLOBALS['tutorpress_pmpro_lds_flush_group'] );
	$bsu  = $lid + 22;
	$bseed( $bsu, $b3, array( $b3, $b2 ), 1, false, 0 );
	$rbsu = $ctl->delete_subscription_plan( $req( $bsu, $b3 ) );
	$plbu = $read( $b3 );
	tutorpress_pmpro_lds_assert( in_array( $err( $rbsu ), array( array( 200, 'ok' ), array( 200, 'warn' ) ), true ) && 'PMPro membership level removed.' === (string) ( $rbsu->get_data()['message'] ?? '' ) && is_array( $plbu ) && is_int( $plbu['version'] ?? null ) && 1 === $plbu['version'] && array( $bsu ) === array_keys( $plbu['levels'] ) && 'unlinked' === ( $plbu['levels'][ $bsu ]['state'] ?? '' ) && 'one_time' === ( $plbu['levels'][ $bsu ]['kind'] ?? '' ) && array( 'one_time' ) === ( $plbu['blocked_kinds'] ?? null ) && true === ( $plbu['removed_restriction'] ?? null ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $b3, $state::META_KEY ) ) && '1' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT allow_signups FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bsu ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bsu, $b3 ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $bsu, 'tutorpress_bundle_id' ) ) && ! in_array( $bsu, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b3, '_tutorpress_pmpro_levels' ) ) ) ), true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bsu, $b2 ) ) && in_array( $bsu, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b2, '_tutorpress_pmpro_levels' ) ) ) ), true ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bsp, $b2 ) ) && (string) $bsu === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bsu ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $bsu ) ), '15c-shared-unprot' );
	$bso  = $lid + 23;
	$bseed( $bso, $b4, array( $b4 ), 1, true, $b4 );
	$rbso = $ctl->delete_subscription_plan( $req( $bso, $b4 ) );
	$plbo = $read( $b4 );
	tutorpress_pmpro_lds_assert( array( 200, 'ok' ) === $err( $rbso ) && 'PMPro membership level removed.' === (string) ( $rbso->get_data()['message'] ?? '' ) && is_array( $plbo ) && is_int( $plbo['version'] ?? null ) && 1 === $plbo['version'] && array( $bso ) === array_keys( $plbo['levels'] ) && 'retired' === ( $plbo['levels'][ $bso ]['state'] ?? '' ) && 'one_time' === ( $plbo['levels'][ $bso ]['kind'] ?? '' ) && array( 'one_time' ) === ( $plbo['blocked_kinds'] ?? null ) && false === ( $plbo['removed_restriction'] ?? null ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $b4, $state::META_KEY ) ) && '0' === (string) $wpdb->get_var( $wpdb->prepare( "SELECT allow_signups FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bso ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bso, $b4 ) ) && (string) $b4 === (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $bso, 'tutorpress_bundle_id' ) ) && in_array( $bso, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b4, '_tutorpress_pmpro_levels' ) ) ) ), true ) && (string) $bso === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bso ) ) && (string) $bso === (string) $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $bso ) ) && (int) ( $GLOBALS['tutorpress_pmpro_lds_15c_groups'][ $bso ] ?? 0 ) > 0 && (string) $GLOBALS['tutorpress_pmpro_lds_15c_groups'][ $bso ] === (string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_groups} WHERE id = %d", (int) $GLOBALS['tutorpress_pmpro_lds_15c_groups'][ $bso ] ) ) && (string) $GLOBALS['tutorpress_pmpro_lds_15c_groups'][ $bso ] === (string) $wpdb->get_var( $wpdb->prepare( "SELECT `group` FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d", $bso ) ) && (string) $GLOBALS['tutorpress_pmpro_lds_15c_groups'][ $bso ] === (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b4, '_tutorpress_pmpro_group_id' ) ), '15c-sole-prot' );
	$bsd  = $lid + 24;
	$bseed( $bsd, $b5, array( $b5 ), 1, false, $b5 );
	$rbsd = $ctl->delete_subscription_plan( $req( $bsd, $b5 ) );
	$plbd = $read( $b5 );
	tutorpress_pmpro_lds_assert( array( 200, 'ok' ) === $err( $rbsd ) && 'PMPro membership level removed.' === (string) ( $rbsd->get_data()['message'] ?? '' ) && null === $plbd && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $b5, $state::META_KEY ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bsd ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $bsd, $b5 ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $bsd, 'tutorpress_bundle_id' ) ) && ! in_array( $bsd, array_map( 'intval', (array) maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b5, '_tutorpress_pmpro_levels' ) ) ) ), true ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $bsd ) ) && (int) ( $GLOBALS['tutorpress_pmpro_lds_15c_groups'][ $bsd ] ?? 0 ) > 0 && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_groups} WHERE id = %d", (int) $GLOBALS['tutorpress_pmpro_lds_15c_groups'][ $bsd ] ) ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $b5, '_tutorpress_pmpro_group_id' ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d", $bsd ) ), '15c-sole-unprot' );
	tutorpress_pmpro_lds_pass( 'rest-bundle-removal' );
	$ac  = $lid + 25;
	$nx  = $lid + 26;
	$rid = $lid + 27;
	tutorpress_pmpro_lds_assert( $ac === (int) wp_insert_post( array( 'import_id' => $ac, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp12a-' . $ac ) ), '12a-course-post' );
	$GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $ac;
	wp_set_current_user( $ed );
	tutorpress_pmpro_lds_assert( current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_post', $ac ), '12a-course-editor' );
	$course_auth_snap = function() use ( $wpdb, $ac, $rid, $lm ) {
		return array(
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $ac ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $ac ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$lm} WHERE meta_key = %s AND meta_value = %s", 'tutorpress_course_id', (string) $ac ) ),
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $rid ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d", $rid ) ),
		);
	};
	$course_auth_dispatch = function( $route, $object_id ) use ( $rid ) {
		$paths = array(
			'create' => array( 'POST', '/tutorpress/v1/subscriptions' ),
			'update' => array( 'POST', '/tutorpress/v1/subscriptions/' . (int) $rid ),
			'delete' => array( 'DELETE', '/tutorpress/v1/subscriptions/' . (int) $rid ),
			'duplicate' => array( 'POST', '/tutorpress/v1/subscriptions/' . (int) $rid . '/duplicate' ),
			'sort' => array( 'POST', '/tutorpress/v1/subscriptions/sort' ),
			'editor-sort' => array( 'PUT', '/tutorpress/v1/courses/' . (int) $object_id . '/subscriptions/sort' ),
		);
		$request = new WP_REST_Request( $paths[ $route ][0], $paths[ $route ][1] );
		$request->set_param( 'object_id', (int) $object_id );
		$request->set_param( 'id', (int) $rid );
		if ( 'create' === $route ) {
			$request->set_param( 'plan_name', 'tp12a' );
			$request->set_param( 'regular_price', 1 );
		}
		if ( 'sort' === $route ) {
			$request->set_param( 'ordered_ids', array( (int) $rid ) );
		}
		if ( 'editor-sort' === $route ) {
			$request->set_url_params( array( 'course_id' => (int) $object_id ) );
			$request->set_param( 'plan_order', array( (int) $rid ) );
		}
		return $request;
	};
	$course_auth_body = function( $response ) {
		$data = $response->get_data();
		return array( (int) $response->get_status(), ( is_array( $data ) && isset( $data['code'] ) ) ? (string) $data['code'] : '' );
	};
	$course_auth_cases = array(
		'edit-posts'  => array( $ed, $ac, 403, 'rest_forbidden' ),
		'missing'     => array( 1, 0, 400, 'missing_object_id' ),
		'nonexistent' => array( 1, $nx, 404, 'invalid_course' ),
		'wrong-type'  => array( 1, $b, 404, 'invalid_course' ),
	);
	foreach ( array( 'create', 'update', 'delete' ) as $route ) {
		foreach ( $course_auth_cases as $label => $case ) {
			wp_set_current_user( $case[0] );
			$before = $course_auth_snap();
			$body   = $course_auth_body( rest_do_request( $course_auth_dispatch( $route, $case[1] ) ) );
			tutorpress_pmpro_lds_assert( array( $case[2], $case[3] ) === $body && $before === $course_auth_snap(), '12a-course-' . $route . '-' . $label );
		}
	}
	tutorpress_pmpro_lds_pass( 'course-auth-object' );
	foreach ( array( 'duplicate', 'sort', 'editor-sort' ) as $route ) {
		foreach ( $course_auth_cases as $label => $case ) {
			wp_set_current_user( $case[0] );
			$before = $course_auth_snap();
			$body   = $course_auth_body( rest_do_request( $course_auth_dispatch( $route, $case[1] ) ) );
			tutorpress_pmpro_lds_assert( array( $case[2], $case[3] ) === $body && $before === $course_auth_snap(), '12a-course-' . $route . '-' . $label );
		}
	}
	tutorpress_pmpro_lds_pass( 'course-auth-duplicate-sort' );
	wp_set_current_user( 1 );
	tutorpress_pmpro_lds_assert( current_user_can( 'edit_post', $ac ), '12a-course-author' );
	$course_auth_success = function( $response ) {
		$data = $response->get_data();
		$id   = 0;
		if ( is_array( $data ) && is_array( $data['data'] ?? null ) ) {
			$id = (int) ( $data['data']['id'] ?? 0 );
		}
		return array( (int) $response->get_status(), is_array( $data ) && ! empty( $data['success'] ), $id );
	};
	$created = $course_auth_success( rest_do_request( $course_auth_dispatch( 'create', $ac ) ) );
	$cok     = $created[2];
	tutorpress_pmpro_lds_assert( array( 200, true, $cok ) === $created && $cok > 0 && (string) $ac === (string) get_pmpro_membership_level_meta( $cok, 'tutorpress_course_id', true ), '12a-course-create-ok' );
	$course_auth_owned = function( $route, $plan_id ) use ( $ac ) {
		$paths = array(
			'update'    => array( 'POST', '/tutorpress/v1/subscriptions/' . (int) $plan_id ),
			'duplicate' => array( 'POST', '/tutorpress/v1/subscriptions/' . (int) $plan_id . '/duplicate' ),
			'sort'        => array( 'POST', '/tutorpress/v1/subscriptions/sort' ),
			'editor-sort' => array( 'PUT', '/tutorpress/v1/courses/' . (int) $ac . '/subscriptions/sort' ),
			'delete'      => array( 'DELETE', '/tutorpress/v1/subscriptions/' . (int) $plan_id ),
		);
		$request = new WP_REST_Request( $paths[ $route ][0], $paths[ $route ][1] );
		$request->set_param( 'object_id', (int) $ac );
		$request->set_param( 'id', (int) $plan_id );
		if ( 'update' === $route ) {
			$request->set_param( 'plan_name', 'tp12a-ok' );
		}
		if ( 'sort' === $route ) {
			$request->set_param( 'ordered_ids', array( (int) $plan_id ) );
		}
		if ( 'editor-sort' === $route ) {
			$request->set_url_params( array( 'course_id' => (int) $ac ) );
			$request->set_param( 'plan_order', array( (int) $plan_id ) );
		}
		return $request;
	};
	$updated = $course_auth_success( rest_do_request( $course_auth_owned( 'update', $cok ) ) );
	$uname   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $cok ) );
	tutorpress_pmpro_lds_assert( array( 200, true, $cok ) === $updated && 'tp12a-ok' === $uname && (string) $ac === (string) get_pmpro_membership_level_meta( $cok, 'tutorpress_course_id', true ), '12a-course-update-ok' );
	$duplicated = $course_auth_success( rest_do_request( $course_auth_owned( 'duplicate', $cok ) ) );
	$dok        = $duplicated[2];
	tutorpress_pmpro_lds_assert( array( 200, true, $dok ) === $duplicated && $dok > 0 && $dok !== $cok && (string) $ac === (string) get_pmpro_membership_level_meta( $dok, 'tutorpress_course_id', true ), '12a-course-duplicate-ok' );
	tutorpress_pmpro_lds_pass( 'course-auth-success' );
	$sort_request  = $course_auth_owned( 'sort', $cok );
	$sort_response = rest_do_request( $sort_request );
	$sorted        = $course_auth_success( $sort_response );
	$sort_list     = $sort_response->get_data()['data'] ?? null;
	tutorpress_pmpro_lds_assert( (int) $cok === (int) $sort_request->get_param( 'id' ), '12a-course-sort-id' );
	tutorpress_pmpro_lds_assert( array( 200, true, 0 ) === $sorted && is_array( $sort_list ) && in_array( (int) $cok, array_map( 'intval', $sort_list ), true ), '12a-course-sort-ok' );
	$editor_request  = $course_auth_owned( 'editor-sort', $cok );
	$editor_response = rest_do_request( $editor_request );
	$editor_sorted   = $course_auth_success( $editor_response );
	$editor_list     = $editor_response->get_data()['data'] ?? null;
	tutorpress_pmpro_lds_assert( (int) $cok === (int) $editor_request->get_param( 'id' ) && (int) $ac === (int) ( $editor_request->get_url_params()['course_id'] ?? 0 ), '12a-course-editor-sort-id' );
	tutorpress_pmpro_lds_assert( array( 200, true, 0 ) === $editor_sorted && is_array( $editor_list ) && in_array( (int) $cok, array_map( 'intval', $editor_list ), true ), '12a-course-editor-sort-ok' );
	$delete_request  = $course_auth_owned( 'delete', $dok );
	$delete_response = rest_do_request( $delete_request );
	$deleted         = $course_auth_success( $delete_response );
	$delete_payload  = $delete_response->get_data()['data'] ?? null;
	tutorpress_pmpro_lds_assert( (int) $dok === (int) $delete_request->get_param( 'id' ), '12a-course-delete-id' );
	tutorpress_pmpro_lds_assert( array( 200, true, 0 ) === $deleted && ( null === $delete_payload || ( is_array( $delete_payload ) && ! empty( $delete_payload['warning'] ) ) ), '12a-course-delete-ok' );
	tutorpress_pmpro_lds_pass( 'course-auth-sort-delete' );
	$ab  = $lid + 28;
	$bnx = $lid + 29;
	tutorpress_pmpro_lds_assert( $ab === (int) wp_insert_post( array( 'import_id' => $ab, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp12a-' . $ab ) ), '12a-bundle-post' );
	$GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $ab;
	wp_set_current_user( $ed );
	tutorpress_pmpro_lds_assert( current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_post', $ab ), '12a-bundle-editor' );
	$bundle_auth_snap = function() use ( $wpdb, $ab, $rid, $lm ) {
		return array(
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $ab ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $ab ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$lm} WHERE meta_key = %s AND meta_value = %s", 'tutorpress_bundle_id', (string) $ab ) ),
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $rid ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d", $rid ) ),
		);
	};
	$bundle_auth_dispatch = function( $route, $object_id ) use ( $rid ) {
		$paths = array(
			'create' => array( 'POST', '/tutorpress/v1/subscriptions' ),
			'update' => array( 'POST', '/tutorpress/v1/subscriptions/' . (int) $rid ),
			'delete' => array( 'DELETE', '/tutorpress/v1/subscriptions/' . (int) $rid ),
			'duplicate' => array( 'POST', '/tutorpress/v1/subscriptions/' . (int) $rid . '/duplicate' ),
			'sort' => array( 'POST', '/tutorpress/v1/subscriptions/sort' ),
			'editor-sort' => array( 'PUT', '/tutorpress/v1/bundles/' . (int) $object_id . '/subscriptions/sort' ),
		);
		$request = new WP_REST_Request( $paths[ $route ][0], $paths[ $route ][1] );
		$request->set_param( 'object_id', (int) $object_id );
		$request->set_param( 'id', (int) $rid );
		if ( 'create' === $route ) {
			$request->set_param( 'plan_name', 'tp12a' );
			$request->set_param( 'regular_price', 1 );
		}
		if ( 'sort' === $route ) {
			$request->set_param( 'ordered_ids', array( (int) $rid ) );
		}
		if ( 'editor-sort' === $route ) {
			$request->set_url_params( array( 'bundle_id' => (int) $object_id ) );
			$request->set_param( 'plan_order', array( (int) $rid ) );
		}
		return $request;
	};
	$bundle_auth_cases = array(
		'edit-posts'  => array( $ed, $ab, 403, 'rest_forbidden' ),
		'missing'     => array( 1, 0, 400, 'missing_object_id' ),
		'nonexistent' => array( 1, $bnx, 404, 'invalid_course' ),
		'wrong-type'  => array( 1, $b, 404, 'invalid_course' ),
	);
	foreach ( array( 'create', 'update', 'delete' ) as $route ) {
		foreach ( $bundle_auth_cases as $label => $case ) {
			wp_set_current_user( $case[0] );
			$before = $bundle_auth_snap();
			$body   = $course_auth_body( rest_do_request( $bundle_auth_dispatch( $route, $case[1] ) ) );
			tutorpress_pmpro_lds_assert( array( $case[2], $case[3] ) === $body && $before === $bundle_auth_snap(), '12a-bundle-' . $route . '-' . $label );
		}
	}
	tutorpress_pmpro_lds_pass( 'bundle-auth-object' );
	$bundle_editor_cases = array(
		'edit-posts'  => array( $ed, $ab, 403, 'rest_forbidden' ),
		'missing'     => array( 1, 0, 400, 'missing_object_id' ),
		'nonexistent' => array( 1, $bnx, 404, 'invalid_bundle' ),
		'plain-post'  => array( 1, $b, 404, 'invalid_bundle' ),
		'course'      => array( 1, $ac, 404, 'invalid_bundle' ),
	);
	foreach ( $bundle_editor_cases as $label => $case ) {
		wp_set_current_user( $case[0] );
		$request = $bundle_auth_dispatch( 'editor-sort', $case[1] );
		tutorpress_pmpro_lds_assert( (int) $rid === (int) $request->get_param( 'id' ) && (int) $case[1] === (int) ( $request->get_url_params()['bundle_id'] ?? 0 ), '12a-bundle-editor-sort-route-' . $label );
		$before  = $bundle_auth_snap();
		$body    = $course_auth_body( rest_do_request( $request ) );
		tutorpress_pmpro_lds_assert( array( $case[2], $case[3] ) === $body && $before === $bundle_auth_snap(), '12a-bundle-editor-sort-' . $label );
	}
	tutorpress_pmpro_lds_pass( 'bundle-auth-editor-sort' );
	foreach ( array( 'duplicate', 'sort' ) as $route ) {
		foreach ( $bundle_auth_cases as $label => $case ) {
			wp_set_current_user( $case[0] );
			$request = $bundle_auth_dispatch( $route, $case[1] );
			tutorpress_pmpro_lds_assert( (int) $rid === (int) $request->get_param( 'id' ), '12a-bundle-' . $route . '-id-' . $label );
			$before  = $bundle_auth_snap();
			$body    = $course_auth_body( rest_do_request( $request ) );
			tutorpress_pmpro_lds_assert( array( $case[2], $case[3] ) === $body && $before === $bundle_auth_snap(), '12a-bundle-' . $route . '-' . $label );
		}
	}
	tutorpress_pmpro_lds_pass( 'bundle-auth-duplicate-sort' );
	wp_set_current_user( 1 );
	tutorpress_pmpro_lds_assert( current_user_can( 'edit_post', $ab ), '12a-bundle-author' );
	$created = $course_auth_success( rest_do_request( $bundle_auth_dispatch( 'create', $ab ) ) );
	$bok     = $created[2];
	tutorpress_pmpro_lds_assert( array( 200, true, $bok ) === $created && $bok > 0 && (string) $ab === (string) get_pmpro_membership_level_meta( $bok, 'tutorpress_bundle_id', true ), '12a-bundle-create-ok' );
	$bundle_auth_owned = function( $route, $plan_id ) use ( $ab ) {
		$paths = array(
			'update'    => array( 'POST', '/tutorpress/v1/subscriptions/' . (int) $plan_id ),
			'duplicate' => array( 'POST', '/tutorpress/v1/subscriptions/' . (int) $plan_id . '/duplicate' ),
			'sort'        => array( 'POST', '/tutorpress/v1/subscriptions/sort' ),
			'editor-sort' => array( 'PUT', '/tutorpress/v1/bundles/' . (int) $ab . '/subscriptions/sort' ),
			'delete'      => array( 'DELETE', '/tutorpress/v1/subscriptions/' . (int) $plan_id ),
		);
		$request = new WP_REST_Request( $paths[ $route ][0], $paths[ $route ][1] );
		$request->set_param( 'object_id', (int) $ab );
		$request->set_param( 'id', (int) $plan_id );
		if ( 'update' === $route ) {
			$request->set_param( 'plan_name', 'tp12a-ok' );
		}
		if ( 'sort' === $route ) {
			$request->set_param( 'ordered_ids', array( (int) $plan_id ) );
		}
		if ( 'editor-sort' === $route ) {
			$request->set_url_params( array( 'bundle_id' => (int) $ab ) );
			$request->set_param( 'plan_order', array( (int) $plan_id ) );
		}
		return $request;
	};
	$updated = $course_auth_success( rest_do_request( $bundle_auth_owned( 'update', $bok ) ) );
	$uname   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $bok ) );
	tutorpress_pmpro_lds_assert( array( 200, true, $bok ) === $updated && 'tp12a-ok' === $uname && (string) $ab === (string) get_pmpro_membership_level_meta( $bok, 'tutorpress_bundle_id', true ), '12a-bundle-update-ok' );
	$duplicated = $course_auth_success( rest_do_request( $bundle_auth_owned( 'duplicate', $bok ) ) );
	$bdk        = $duplicated[2];
	tutorpress_pmpro_lds_assert( array( 200, true, $bdk ) === $duplicated && $bdk > 0 && $bdk !== $bok && (string) $ab === (string) get_pmpro_membership_level_meta( $bdk, 'tutorpress_bundle_id', true ), '12a-bundle-duplicate-ok' );
	tutorpress_pmpro_lds_pass( 'bundle-auth-success' );
	$sort_request  = $bundle_auth_owned( 'sort', $bok );
	$sort_response = rest_do_request( $sort_request );
	$sorted        = $course_auth_success( $sort_response );
	$sort_list     = $sort_response->get_data()['data'] ?? null;
	tutorpress_pmpro_lds_assert( (int) $bok === (int) $sort_request->get_param( 'id' ), '12a-bundle-sort-id' );
	tutorpress_pmpro_lds_assert( array( 200, true, 0 ) === $sorted && is_array( $sort_list ) && in_array( (int) $bok, array_map( 'intval', $sort_list ), true ), '12a-bundle-sort-ok' );
	$editor_request  = $bundle_auth_owned( 'editor-sort', $bok );
	$editor_response = rest_do_request( $editor_request );
	$editor_sorted   = $course_auth_success( $editor_response );
	$editor_list     = $editor_response->get_data()['data'] ?? null;
	tutorpress_pmpro_lds_assert( (int) $bok === (int) $editor_request->get_param( 'id' ) && (int) $ab === (int) ( $editor_request->get_url_params()['bundle_id'] ?? 0 ), '12a-bundle-editor-sort-id' );
	tutorpress_pmpro_lds_assert( array( 200, true, 0 ) === $editor_sorted && is_array( $editor_list ) && in_array( (int) $bok, array_map( 'intval', $editor_list ), true ), '12a-bundle-editor-sort-ok' );
	$delete_request  = $bundle_auth_owned( 'delete', $bdk );
	$delete_response = rest_do_request( $delete_request );
	$deleted         = $course_auth_success( $delete_response );
	$delete_payload  = $delete_response->get_data()['data'] ?? null;
	tutorpress_pmpro_lds_assert( (int) $bdk === (int) $delete_request->get_param( 'id' ), '12a-bundle-delete-id' );
	tutorpress_pmpro_lds_assert( array( 200, true, 0 ) === $deleted && ( null === $delete_payload || ( is_array( $delete_payload ) && ! empty( $delete_payload['warning'] ) ) ), '12a-bundle-delete-ok' );
	tutorpress_pmpro_lds_pass( 'bundle-auth-sort-delete' );
	$course_direct_handlers = array(
		'create'      => 'create_subscription_plan',
		'update'      => 'update_subscription_plan',
		'delete'      => 'delete_subscription_plan',
		'duplicate'   => 'duplicate_subscription_plan',
		'sort'        => 'sort_subscription_plans',
		'editor-sort' => 'sort_editor_course_plans',
	);
	foreach ( $course_direct_handlers as $route => $method ) {
		foreach ( $course_auth_cases as $label => $case ) {
			wp_set_current_user( $case[0] );
			$before = $course_auth_snap();
			$body   = $err( $ctl->$method( $course_auth_dispatch( $route, $case[1] ) ) );
			tutorpress_pmpro_lds_assert( array( $case[2], $case[3] ) === $body && $before === $course_auth_snap(), '12a-direct-course-' . $route . '-' . $label );
		}
	}
	tutorpress_pmpro_lds_pass( 'course-auth-direct' );
	$bundle_direct_handlers = array(
		'create'    => 'create_subscription_plan',
		'update'    => 'update_subscription_plan',
		'delete'    => 'delete_subscription_plan',
		'duplicate' => 'duplicate_subscription_plan',
		'sort'      => 'sort_subscription_plans',
	);
	foreach ( $bundle_direct_handlers as $route => $method ) {
		foreach ( $bundle_auth_cases as $label => $case ) {
			wp_set_current_user( $case[0] );
			$before = $bundle_auth_snap();
			$body   = $err( $ctl->$method( $bundle_auth_dispatch( $route, $case[1] ) ) );
			tutorpress_pmpro_lds_assert( array( $case[2], $case[3] ) === $body && $before === $bundle_auth_snap(), '12a-direct-bundle-' . $route . '-' . $label );
		}
	}
	tutorpress_pmpro_lds_pass( 'bundle-auth-direct' );
	$bundle_direct_editor_cases = array(
		'edit-posts'  => array( $ed, $ab, 403, 'rest_forbidden' ),
		'missing'     => array( 1, 0, 400, 'missing_object_id' ),
		'nonexistent' => array( 1, $bnx, 404, 'invalid_course' ),
		'plain-post'  => array( 1, $b, 404, 'invalid_course' ),
	);
	foreach ( $bundle_direct_editor_cases as $label => $case ) {
		wp_set_current_user( $case[0] );
		$request = $bundle_auth_dispatch( 'editor-sort', $case[1] );
		$before  = $bundle_auth_snap();
		$body    = $err( $ctl->sort_editor_bundle_plans( $request ) );
		tutorpress_pmpro_lds_assert( array( $case[2], $case[3] ) === $body && $before === $bundle_auth_snap(), '12a-direct-bundle-editor-sort-' . $label );
	}
	tutorpress_pmpro_lds_pass( 'bundle-auth-direct-editor' );
	wp_set_current_user( 1 );
	$own = $lid + 30;
	$inserted = $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $own, 'name' => 'tp12b-' . $own, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) );
	tutorpress_pmpro_lds_assert( false !== $inserted, '12b-update-level' );
	unset( $GLOBALS['pmpro_levels'][ $own ] );
	$meta_inserted = $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $own, 'meta_key' => 'tutorpress_bundle_id', 'meta_value' => (string) $ab ) );
	tutorpress_pmpro_lds_assert( false !== $meta_inserted, '12b-update-meta' );
	if ( true === $c::session_in_transaction() ) {
		$wpdb->query( 'COMMIT' );
	}
	$update_owner_request = function( $plan_id ) use ( $ab ) {
		$request = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/' . (int) $plan_id );
		$request->set_param( 'id', (int) $plan_id );
		$request->set_param( 'object_id', (int) $ab );
		$request->set_param( 'plan_name', 'tp12b-ok' );
		return $request;
	};
	$update_owner_snap = function( $plan_id ) use ( $wpdb, $lm, $ab ) {
		return array(
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $plan_id ) ),
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $plan_id, 'tutorpress_bundle_id' ) ),
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $plan_id, 'tutorpress_course_id' ) ),
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $plan_id, 'tutorpress_managed' ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d", $plan_id ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d", $plan_id ) ),
			(string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $ab, '_tutorpress_pmpro_levels' ) ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" ),
		);
	};
	$owned_before = $update_owner_snap( $own );
	tutorpress_pmpro_lds_assert( 'tp12b-' . $own === $owned_before[0] && (string) $ab === $owned_before[1] && '' === $owned_before[2] && '' === $owned_before[3] && 0 === $owned_before[4] && 0 === $owned_before[5] && ! in_array( $own, array_map( 'intval', (array) maybe_unserialize( $owned_before[6] ) ), true ), '12b-update-owned-pre' );
	$owned_response = $ctl->update_subscription_plan( $update_owner_request( $own ) );
	$owned_data     = $owned_response->get_data();
	tutorpress_pmpro_lds_assert( 200 === (int) $owned_response->get_status() && is_array( $owned_data ) && ! empty( $owned_data['success'] ) && $own === (int) ( $owned_data['data']['id'] ?? 0 ), '12b-update-owned-ok' );
	$owned_after = $update_owner_snap( $own );
	tutorpress_pmpro_lds_assert( 'tp12b-ok' === $owned_after[0] && (string) $ab === $owned_after[1] && '' === $owned_after[2] && '' === $owned_after[3] && 0 === $owned_after[4] && 0 === $owned_after[5] && $owned_before[6] === $owned_after[6] && $owned_before[7] === $owned_after[7], '12b-update-owned-stored' );
	tutorpress_pmpro_lds_pass( 'update-ownership-owned' );
	$frn = $lid + 31;
	$opp = $lid + 32;
	$uno = $lid + 33;
	$mis = $lid + 34;
	foreach ( array( $frn, $opp, $uno ) as $reject_id ) {
		$reject_inserted = $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $reject_id, 'name' => 'tp12b-' . $reject_id, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) );
		tutorpress_pmpro_lds_assert( false !== $reject_inserted, '12b-update-reject-level' );
		unset( $GLOBALS['pmpro_levels'][ $reject_id ] );
	}
	foreach (
		array(
			array( $frn, 'tutorpress_bundle_id', (string) $h ),
			array( $opp, 'tutorpress_bundle_id', (string) $ab ),
			array( $opp, 'tutorpress_course_id', (string) $ac ),
		) as $reject_row
	) {
		$reject_meta_inserted = $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $reject_row[0], 'meta_key' => $reject_row[1], 'meta_value' => $reject_row[2] ) );
		tutorpress_pmpro_lds_assert( false !== $reject_meta_inserted, '12b-update-reject-meta' );
	}
	if ( true === $c::session_in_transaction() ) {
		$wpdb->query( 'COMMIT' );
	}
	$update_reject_cases = array(
		'foreign'  => array( $frn, 409, 'ownership_conflict', 'tp12b-' . $frn, (string) $h, '' ),
		'opposite' => array( $opp, 409, 'ownership_conflict', 'tp12b-' . $opp, (string) $ab, (string) $ac ),
		'unowned'  => array( $uno, 409, 'ownership_conflict', 'tp12b-' . $uno, '', '' ),
		'missing'  => array( $mis, 404, 'level_not_found', '', '', '' ),
	);
	foreach ( $update_reject_cases as $label => $case ) {
		$before = $update_owner_snap( $case[0] );
		tutorpress_pmpro_lds_assert(
			$case[3] === $before[0] && $case[4] === $before[1] && $case[5] === $before[2] && '' === $before[3] && 0 === $before[4] && 0 === $before[5] && ! in_array( $case[0], array_map( 'intval', (array) maybe_unserialize( $before[6] ) ), true ),
			'12b-update-reject-pre-' . $label
		);
		$body = $err( $ctl->update_subscription_plan( $update_owner_request( $case[0] ) ) );
		tutorpress_pmpro_lds_assert( array( $case[1], $case[2] ) === $body && $before === $update_owner_snap( $case[0] ), '12b-update-reject-' . $label );
	}
	tutorpress_pmpro_lds_pass( 'update-ownership-reject' );
	$duplicate_owner_request = function( $plan_id ) use ( $ab ) {
		$request = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/' . (int) $plan_id . '/duplicate' );
		$request->set_param( 'id', (int) $plan_id );
		$request->set_param( 'object_id', (int) $ab );
		return $request;
	};
	$owned_dup_before = $update_owner_snap( $own );
	tutorpress_pmpro_lds_assert( 'tp12b-ok' === $owned_dup_before[0] && (string) $ab === $owned_dup_before[1] && '' === $owned_dup_before[2] && '' === $owned_dup_before[3] && 0 === $owned_dup_before[4] && 0 === $owned_dup_before[5] && ! in_array( $own, array_map( 'intval', (array) maybe_unserialize( $owned_dup_before[6] ) ), true ), '12b-duplicate-owned-pre' );
	unset( $GLOBALS['pmpro_levels'][ $own ] );
	$owned_dup_response = $ctl->duplicate_subscription_plan( $duplicate_owner_request( $own ) );
	$owned_dup_data     = $owned_dup_response->get_data();
	$dup                = (int) ( $owned_dup_data['data']['id'] ?? 0 );
	tutorpress_pmpro_lds_assert( 200 === (int) $owned_dup_response->get_status() && is_array( $owned_dup_data ) && ! empty( $owned_dup_data['success'] ) && $dup > 0 && $dup !== $own, '12b-duplicate-owned-ok' );
	$owned_dup_source = $update_owner_snap( $own );
	$owned_dup_dest   = $update_owner_snap( $dup );
	tutorpress_pmpro_lds_assert(
		array_slice( $owned_dup_before, 0, 6 ) === array_slice( $owned_dup_source, 0, 6 ) && 'tp12b-ok (Copy)' === $owned_dup_dest[0] && (string) $ab === $owned_dup_dest[1] && '' === $owned_dup_dest[2] && '1' === $owned_dup_dest[3] && 0 === $owned_dup_dest[4] && 1 === $owned_dup_dest[5] && $owned_dup_before[7] + 1 === $owned_dup_dest[7] && in_array( $dup, array_map( 'intval', (array) maybe_unserialize( $owned_dup_dest[6] ) ), true ) && ! in_array( $own, array_map( 'intval', (array) maybe_unserialize( $owned_dup_dest[6] ) ), true ),
		'12b-duplicate-owned-stored'
	);
	$duplicate_reject_cases = array(
		'foreign'  => array( $frn, 409, 'ownership_conflict', 'tp12b-' . $frn, (string) $h, '' ),
		'opposite' => array( $opp, 409, 'ownership_conflict', 'tp12b-' . $opp, (string) $ab, (string) $ac ),
		'unowned'  => array( $uno, 409, 'ownership_conflict', 'tp12b-' . $uno, '', '' ),
		'missing'  => array( $mis, 404, 'level_not_found', '', '', '' ),
	);
	foreach ( $duplicate_reject_cases as $label => $case ) {
		$before = $update_owner_snap( $case[0] );
		tutorpress_pmpro_lds_assert(
			$case[3] === $before[0] && $case[4] === $before[1] && $case[5] === $before[2] && '' === $before[3] && 0 === $before[4] && 0 === $before[5] && ! in_array( $case[0], array_map( 'intval', (array) maybe_unserialize( $before[6] ) ), true ),
			'12b-duplicate-reject-pre-' . $label
		);
		$body = $err( $ctl->duplicate_subscription_plan( $duplicate_owner_request( $case[0] ) ) );
		tutorpress_pmpro_lds_assert( array( $case[1], $case[2] ) === $body && $before === $update_owner_snap( $case[0] ), '12b-duplicate-reject-' . $label );
	}
	tutorpress_pmpro_lds_pass( 'duplicate-ownership' );
	$m13 = $lid + 35;
	$d13 = 0;
	$copied13 = array(
		'provide_certificate' => '0',
		'is_featured' => '',
		'sale_price_from' => '2026-10-01 00:00:00',
		'sale_price_to' => '',
		'tutorpress_sale_price_from' => '2026-10-02 00:00:00',
		'tutorpress_sale_price_to' => '2026-11-01 00:00:00',
		'tutorpress_regular_price' => '0',
		'tutorpress_sale_price' => '0',
	);
	$read13 = function( $level_id ) use ( $wpdb, $lm, $copied13 ) {
		$out = array();
		foreach ( array_merge( array( 'sale_price' ), array_keys( $copied13 ) ) as $key ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_value FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key = %s LIMIT 1", $level_id, $key ) );
			$out[ $key ] = null === $row ? null : (string) $row->meta_value;
		}
		return $out;
	};
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $m13, 'name' => 'tp13a-' . $m13, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) ), '13a-level' );
	foreach ( $copied13 as $meta_key => $meta_value ) {
		tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $m13, 'meta_key' => $meta_key, 'meta_value' => $meta_value ) ), '13a-meta' );
	}
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $m13, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $ac ) ), '13a-owner' );
	if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); }
	unset( $GLOBALS['pmpro_levels'][ $m13 ] );
	$expected13 = array_merge( array( 'sale_price' => null ), $copied13 );
	tutorpress_pmpro_lds_assert( $expected13 === $read13( $m13 ), '13a-source' );
	$request13 = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/' . $m13 . '/duplicate' );
	$request13->set_param( 'id', $m13 );
	$request13->set_param( 'object_id', $ac );
	$response13 = $ctl->duplicate_subscription_plan( $request13 );
	$data13 = $response13 instanceof WP_REST_Response ? $response13->get_data() : array();
	$d13 = (int) ( $data13['data']['id'] ?? 0 );
	tutorpress_pmpro_lds_assert( $response13 instanceof WP_REST_Response && 200 === (int) $response13->get_status() && ! empty( $data13['success'] ) && $d13 > 0 && $d13 !== $m13, '13a-duplicate-ok' );
	unset( $GLOBALS['pmpro_levels'][ $d13 ] );
	tutorpress_pmpro_lds_assert( $expected13 === $read13( $m13 ) && $expected13 === $read13( $d13 ), '13a-allowlist' );
	$absent13 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key IN (%s,%s,%s,%s)", $d13, 'tutorpress_bundle_id', '_tutorpress_pmpro_level_removal_state', 'tutorpress_bundle_price', 'tutorpress_bundle_total_value' ) );
	tutorpress_pmpro_lds_assert(
		(string) $ac === (string) get_pmpro_membership_level_meta( $d13, 'tutorpress_course_id', true )
		&& '1' === (string) get_pmpro_membership_level_meta( $d13, 'tutorpress_managed', true )
		&& 0 === $absent13,
		'13a-markers'
	);
	tutorpress_pmpro_lds_assert( '2026-10-02 00:00:00' === ( $data13['data']['sale_price_from'] ?? null ) && '2026-11-01 00:00:00' === ( $data13['data']['sale_price_to'] ?? null ), '13a-response-dates' );
	tutorpress_pmpro_lds_pass( 'duplicate-metadata' );
	$m13b = $lid + 36;
	$d13b = 0;
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $m13b, 'name' => 'tp13a-' . $m13b, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) ), '13a-bundle-level' );
	foreach ( $copied13 as $meta_key => $meta_value ) {
		tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $m13b, 'meta_key' => $meta_key, 'meta_value' => $meta_value ) ), '13a-bundle-meta' );
	}
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $m13b, 'meta_key' => 'tutorpress_bundle_id', 'meta_value' => (string) $ab ) ), '13a-bundle-owner' );
	if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); }
	unset( $GLOBALS['pmpro_levels'][ $m13b ] );
	tutorpress_pmpro_lds_assert( $expected13 === $read13( $m13b ), '13a-bundle-source' );
	$request13b = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/' . $m13b . '/duplicate' );
	$request13b->set_param( 'id', $m13b );
	$request13b->set_param( 'object_id', $ab );
	$response13b = $ctl->duplicate_subscription_plan( $request13b );
	$data13b = $response13b instanceof WP_REST_Response ? $response13b->get_data() : array();
	$d13b = (int) ( $data13b['data']['id'] ?? 0 );
	tutorpress_pmpro_lds_assert( $response13b instanceof WP_REST_Response && 200 === (int) $response13b->get_status() && ! empty( $data13b['success'] ) && $d13b > 0 && $d13b !== $m13b, '13a-bundle-duplicate-ok' );
	unset( $GLOBALS['pmpro_levels'][ $d13b ] );
	tutorpress_pmpro_lds_assert( $expected13 === $read13( $m13b ) && $expected13 === $read13( $d13b ), '13a-bundle-allowlist' );
	$absent13b = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$lm} WHERE pmpro_membership_level_id = %d AND meta_key IN (%s,%s,%s,%s)", $d13b, 'tutorpress_course_id', '_tutorpress_pmpro_level_removal_state', 'tutorpress_bundle_price', 'tutorpress_bundle_total_value' ) );
	tutorpress_pmpro_lds_assert(
		(string) $ab === (string) get_pmpro_membership_level_meta( $d13b, 'tutorpress_bundle_id', true )
		&& '1' === (string) get_pmpro_membership_level_meta( $d13b, 'tutorpress_managed', true )
		&& 0 === $absent13b,
		'13a-bundle-markers'
	);
	tutorpress_pmpro_lds_assert( '2026-10-02 00:00:00' === ( $data13b['data']['sale_price_from'] ?? null ) && '2026-11-01 00:00:00' === ( $data13b['data']['sale_price_to'] ?? null ), '13a-bundle-response-dates' );
	tutorpress_pmpro_lds_pass( 'duplicate-metadata-bundle' );
	$v13 = $lid + 37;
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $v13, 'name' => 'tp13b-' . $v13, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 1, 'cycle_number' => 1, 'cycle_period' => 'Month', 'billing_limit' => 3, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) ), '13b-level' );
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $v13, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $ac ) ), '13b-owner' );
	if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); }
	unset( $GLOBALS['pmpro_levels'][ $v13 ] );
	$bill13 = (string) $wpdb->get_var( $wpdb->prepare( "SELECT billing_limit FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $v13 ) );
	$page13 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d", $v13 ) );
	$count13 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" );
	foreach ( array( 'neg' => -7, 'neg-string' => '-7', 'plus' => '+7', 'float' => 7.5, 'decimal' => '7.5', 'decimal-zero' => '7.0', 'array' => array( 7 ), 'object' => new stdClass(), 'true' => true, 'false' => false, 'null' => null, 'empty' => '' ) as $label => $bad ) {
		$ur = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/' . $v13 );
		$ur->set_param( 'id', $v13 );
		$ur->set_param( 'object_id', $ac );
		$ur->set_param( 'payment_type', 'plus' === $label ? 'one_time' : 'recurring' );
		$ur->set_param( 'recurring_limit', $bad );
		$cr = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions' );
		$cr->set_param( 'object_id', $ac );
		$cr->set_param( 'plan_name', 'tp13b-bad' );
		$cr->set_param( 'regular_price', 1 );
		$cr->set_param( 'payment_type', 'plus' === $label ? 'one_time' : 'recurring' );
		$cr->set_param( 'recurring_limit', $bad );
		tutorpress_pmpro_lds_assert(
			array( 400, 'invalid_recurring_limit' ) === $err( $ctl->update_subscription_plan( $ur ) )
			&& array( 400, 'invalid_recurring_limit' ) === $err( $ctl->create_subscription_plan( $cr ) )
			&& $bill13 === (string) $wpdb->get_var( $wpdb->prepare( "SELECT billing_limit FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $v13 ) )
			&& $page13 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d", $v13 ) )
			&& $count13 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" ),
			'13b-reject-' . $label
		);
	}
	tutorpress_pmpro_lds_pass( 'recurring-limit-reject' );
	$u13    = $lid + 38;
	$made13 = array();
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $u13, 'name' => 'tp13b-' . $u13, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 1, 'cycle_number' => 1, 'cycle_period' => 'Month', 'billing_limit' => 3, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) ), '13b-values-level' );
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $u13, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $ac ) ), '13b-values-owner' );
	if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); }
	unset( $GLOBALS['pmpro_levels'][ $u13 ] );
	$bill13 = function( $id ) use ( $wpdb ) {
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT billing_limit FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $id ) );
	};
	$ask13 = function( $path, $body, $limit ) {
		$r = new WP_REST_Request( 'POST', $path );
		foreach ( $body as $k => $v ) { $r->set_param( $k, $v ); }
		if ( null !== $limit ) { $r->set_param( 'recurring_limit', $limit ); }
		return $r;
	};
	$see13 = function( $res ) {
		$d = $res instanceof WP_REST_Response ? $res->get_data() : array();
		return array( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && ! empty( $d['success'] ), $d['data']['recurring_limit'] ?? null, (int) ( $d['data']['id'] ?? 0 ) );
	};
	foreach ( array( array( 7, 7 ), array( '7', 7 ), array( 0, 0 ), array( 7, 7 ), array( '0', 0 ), array( 7, 7 ), array( null, 7 ), array( 7, 0, 'one_time' ), array( 7, 7 ), array( null, 0, 'one_time' ) ) as $i => $case ) {
		$res  = $ctl->update_subscription_plan( $ask13( '/tutorpress/v1/subscriptions/' . $u13, array( 'id' => $u13, 'object_id' => $ac, 'payment_type' => $case[2] ?? 'recurring' ), $case[0] ) );
		$seen = $see13( $res );
		tutorpress_pmpro_lds_assert( true === $seen[0] && $case[1] === $seen[1] && (string) $case[1] === $bill13( $u13 ), '13b-update-' . $i );
	}
	foreach ( array( array( 'tp13b-c7', 7, 7 ), array( 'tp13b-c7s', '7', 7 ), array( 'tp13b-c0', null, 0 ) ) as $i => $case ) {
		$res      = $ctl->create_subscription_plan( $ask13( '/tutorpress/v1/subscriptions', array( 'object_id' => $ac, 'plan_name' => $case[0], 'regular_price' => 1, 'payment_type' => 'recurring', 'recurring_value' => 1, 'recurring_interval' => 'month' ), $case[1] ) );
		$seen     = $see13( $res );
		$made13[] = $seen[2];
		tutorpress_pmpro_lds_assert( true === $seen[0] && $seen[2] > 0 && $case[2] === $seen[1] && (string) $case[2] === $bill13( $seen[2] ), '13b-create-' . $i );
	}
	$levels13 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" );
	$held13   = $bill13( $u13 );
	foreach ( array( '/tutorpress/v1/subscriptions', '/tutorpress/v1/subscriptions/' . $u13 ) as $i => $path ) {
		$body = array( 'object_id' => $ac, 'payment_type' => 'recurring', 'recurring_limit' => '+7' );
		if ( 0 === $i ) { $body['plan_name'] = 'tp13b-bad'; $body['regular_price'] = 1; }
		$r = new WP_REST_Request( 'POST', $path );
		$r->set_header( 'Content-Type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
		$d = rest_do_request( $r )->get_data();
		tutorpress_pmpro_lds_assert( 'invalid_recurring_limit' === ( $d['code'] ?? '' ) && 400 === (int) ( $d['data']['status'] ?? 0 ), '13b-route-' . $i );
	}
	tutorpress_pmpro_lds_assert( $levels13 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels}" ) && $held13 === $bill13( $u13 ) && null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE name = %s", 'tp13b-bad' ) ), '13b-route-unchanged' );
	tutorpress_pmpro_lds_pass( 'recurring-limit-values' );
	$r13 = $lid + 39;
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $r13, 'name' => 'tp13r-' . $r13, 'description' => '', 'confirmation' => '', 'initial_payment' => 1, 'billing_amount' => 2, 'cycle_number' => 1, 'cycle_period' => 'Month', 'billing_limit' => 4, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) ), '13b-read-level' );
	tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $r13, 'meta_key' => 'tutorpress_course_id', 'meta_value' => (string) $ac ) ), '13b-read-owner' );
	if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); }
	unset( $GLOBALS['pmpro_levels'][ $r13 ] );
	$GLOBALS['pmpro_levels'][ $r13 ] = (object) array( 'id' => $r13, 'name' => 'primed', 'billing_limit' => 99, 'initial_payment' => 1.11, 'billing_amount' => 9, 'cycle_number' => 9, 'cycle_period' => 'Day' );
	$sale = $ctl->update_subscription_plan( $ask13( '/tutorpress/v1/subscriptions/' . $r13, array( 'id' => $r13, 'object_id' => $ac, 'payment_type' => 'recurring', 'enrollment_fee' => 15.15, 'recurring_price' => 4 ), 9 ) );
	$sale_row = $wpdb->get_row( $wpdb->prepare( "SELECT billing_limit, initial_payment, billing_amount FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $r13 ) );
	$sale_body = $sale instanceof WP_REST_Response ? $sale->get_data() : array();
	tutorpress_pmpro_lds_assert(
		$sale instanceof WP_REST_Response && 200 === (int) $sale->get_status() && ! empty( $sale_body['success'] )
		&& intval( $sale_row->billing_limit ) === ( $sale_body['data']['recurring_limit'] ?? null )
		&& 99 !== ( $sale_body['data']['recurring_limit'] ?? null )
		&& floatval( $sale_row->initial_payment ) === ( $sale_body['data']['enrollment_fee'] ?? null )
		&& 1.11 !== ( $sale_body['data']['enrollment_fee'] ?? null )
		&& floatval( $sale_row->billing_amount ) === ( $sale_body['data']['regular_price'] ?? null ),
		'13b-read-sale'
	);
	$zeroed = false;
	$zf = function( $sql ) use ( $wpdb, $r13, &$zeroed ) {
		if ( 0 === stripos( ltrim( $sql ), 'UPDATE' ) && false !== strpos( $sql, $wpdb->pmpro_membership_levels ) && false !== strpos( $sql, (string) $r13 ) ) {
			$zeroed = true;
			return preg_replace( '/\bWHERE\b/', 'WHERE 1=0 AND', $sql, 1 );
		}
		return $sql;
	};
	$GLOBALS['tutorpress_pmpro_lds_reg']['hooks'][] = array( 'query', $zf );
	add_filter( 'query', $zf );
	$wpdb->last_error = ''; $held = $bill13( $r13 );
	$zero = $ctl->update_subscription_plan( $ask13( '/tutorpress/v1/subscriptions/' . $r13, array( 'id' => $r13, 'object_id' => $ac, 'payment_type' => 'recurring' ), 4 ) );
	remove_filter( 'query', $zf ); $wpdb->last_error = '';
	$zero_seen = $see13( $zero );
	tutorpress_pmpro_lds_assert( true === $zeroed && true === $zero_seen[0] && 4 !== $zero_seen[1] && (int) $held === $zero_seen[1] && $held === $bill13( $r13 ), '13b-read-zero' );
	$rf = function( $sql ) use ( $wpdb, $r13 ) {
		if ( 0 === stripos( ltrim( $sql ), 'SELECT *' ) && false !== strpos( $sql, $wpdb->pmpro_membership_levels ) && false !== strpos( $sql, 'WHERE id = ' . $r13 ) ) {
			return 'SELECT no_such_13b_col FROM ' . $wpdb->pmpro_membership_levels . ' WHERE id = ' . (int) $r13;
		}
		return $sql;
	};
	$GLOBALS['tutorpress_pmpro_lds_reg']['hooks'][] = array( 'query', $rf );
	add_filter( 'query', $rf );
	$wpdb->last_error = '';
	$fail = $ctl->update_subscription_plan( $ask13( '/tutorpress/v1/subscriptions/' . $r13, array( 'id' => $r13, 'object_id' => $ac, 'payment_type' => 'recurring', 'plan_name' => 'tp13r-kept' ), 6 ) );
	remove_filter( 'query', $rf );
	$kept = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $r13 ) );
	$wpdb->last_error = '';
	tutorpress_pmpro_lds_assert( array( 500, 'persisted_level_read_failed' ) === $err( $fail ) && 'tp13r-kept' === $kept && '6' === $bill13( $r13 ), '13b-read-fail' );
	tutorpress_pmpro_lds_pass( 'recurring-limit-readback' );
	$c13 = $lid + 56;
	$b13 = $lid + 57;
	tutorpress_pmpro_lds_assert( $c13 === (int) wp_insert_post( array( 'import_id' => $c13, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp13o-' . $c13 ) ), '13c-course-post' );
	$GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $c13;
	tutorpress_pmpro_lds_assert( $b13 === (int) wp_insert_post( array( 'import_id' => $b13, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp13o-' . $b13 ) ), '13c-bundle-post' );
	$GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $b13;
	$ord13      = array();
	$seed_order = array( 5, 0, 6, 3, 4, 2, 1 );
	foreach ( array( array( $c13, 'tutorpress_course_id', 'course_id', 'get_course_subscriptions', $lid + 40 ), array( $b13, 'tutorpress_bundle_id', 'bundle_id', 'get_bundle_subscriptions', $lid + 48 ) ) as $spec ) {
		$ids = array();
		foreach ( $seed_order as $n ) {
			$id = $spec[4] + $n;
			tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $id, 'name' => 'tp13o-' . $id, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ) ), '13c-level' );
			tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $id, 'meta_key' => $spec[1], 'meta_value' => (string) $spec[0] ) ), '13c-rev' );
			if ( 3 === $n ) {
				tutorpress_pmpro_lds_assert( false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $id, 'meta_key' => $spec[1], 'meta_value' => (string) $spec[0] ) ), '13c-dup' );
			}
			$ids[ $n ] = $id;
		}
		update_post_meta( $spec[0], '_tutorpress_pmpro_levels', array( $ids[2], $ids[1], $spec[4] + 7, $ids[2] ) );
		update_post_meta( $spec[0], $state::META_KEY, $mkmap( array( (int) $ids[4] => array( 'state' => 'retired', 'kind' => 'one_time' ), (int) $ids[6] => array( 'state' => 'unlinked', 'kind' => 'one_time' ) ), array( 'one_time' ), true ) );
		$state::invalidate_object( $spec[0] );
		$ord13[] = array( $spec[0], $spec[2], $spec[3], array( $ids[2], $ids[1], $ids[2], $ids[0], $ids[3], $ids[5] ), array_merge( $ids, array( $spec[4] + 7 ) ) );
	}
	if ( true === $c::session_in_transaction() ) { $wpdb->query( 'COMMIT' ); }
	$shot13 = function ( $post_id, $level_ids ) use ( $wpdb, $lm ) {
		$ph = implode( ',', array_fill( 0, count( $level_ids ), '%d' ) );
		return array(
			$wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $post_id ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( "SELECT meta_id, pmpro_membership_level_id, meta_key, meta_value FROM {$lm} WHERE pmpro_membership_level_id IN ($ph) ORDER BY meta_id", ...$level_ids ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( "SELECT membership_id, page_id FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id IN ($ph) ORDER BY membership_id, page_id", ...$level_ids ), ARRAY_A ),
			$wpdb->get_results( $wpdb->prepare( "SELECT id, level, `group` FROM {$wpdb->pmpro_membership_levels_groups} WHERE level IN ($ph) ORDER BY id", ...$level_ids ), ARRAY_A ),
			$wpdb->get_results( "SELECT id, name FROM {$wpdb->pmpro_groups} ORDER BY id", ARRAY_A ),
		);
	};
	foreach ( array( 0, 1 ) as $who ) {
		wp_set_current_user( $who );
		foreach ( $ord13 as $row ) {
			$before = $shot13( $row[0], $row[4] );
			tutorpress_pmpro_lds_assert( $row[3] === $getp( $row[0], $row[1], $row[2] ) && $before === $shot13( $row[0], $row[4] ), '13c-' . $row[1] . '-' . $who );
		}
	}
	wp_set_current_user( 1 );
	tutorpress_pmpro_lds_pass( 'discovery-order' );
	$qc = $lid + 58;
	$qb = $lid + 59;
	tutorpress_pmpro_lds_assert( $qc === (int) wp_insert_post( array( 'import_id' => $qc, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'tp13q-' . $qc ) ), '13q-course' );
	$GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $qc;
	tutorpress_pmpro_lds_assert( $qb === (int) wp_insert_post( array( 'import_id' => $qb, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'tp13q-' . $qb ) ), '13q-bundle' );
	$GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $qb;
	$qrow = function ( $id ) use ( $wpdb ) {
		return false !== $wpdb->insert( $wpdb->pmpro_membership_levels, array( 'id' => $id, 'name' => 'tp13q-' . $id, 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0 ), array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d' ) );
	};
	$qrev = function ( $id, $key, $oid ) use ( $wpdb, $lm ) {
		return false !== $wpdb->insert( $lm, array( 'pmpro_membership_level_id' => $id, 'meta_key' => $key, 'meta_value' => (string) (int) $oid ), array( '%d', '%s', '%s' ) );
	};
	$qget = function ( $oid, $param, $meth, $key ) use ( $ctl, $wpdb ) {
		$n = 0;
		$f = function ( $sql ) use ( &$n, $wpdb, $key, $oid ) {
			if ( 0 === stripos( ltrim( (string) $sql ), 'SELECT' ) && false !== strpos( $sql, $wpdb->pmpro_membership_levels ) && false !== strpos( $sql, $wpdb->pmpro_membership_levelmeta ) && false !== strpos( $sql, $key ) && false !== strpos( $sql, (string) (int) $oid ) ) {
				++$n;
			}
			return $sql;
		};
		$GLOBALS['tutorpress_pmpro_lds_reg']['hooks'][] = array( 'query', $f );
		add_filter( 'query', $f );
		$wpdb->last_error = '';
		$r = new WP_REST_Request( 'GET', '/tutorpress/v1/subscriptions' );
		$r->set_param( $param, $oid );
		$res = $ctl->$meth( $r );
		remove_filter( 'query', $f );
		$wpdb->last_error = '';
		return array( $n, $res );
	};
	foreach ( array( array( $qc, 'tutorpress_course_id', 'course_id', 'get_course_subscriptions', $lid + 60 ), array( $qb, 'tutorpress_bundle_id', 'bundle_id', 'get_bundle_subscriptions', $lid + 63 ) ) as $spec ) {
		$canon = $spec[4];
		tutorpress_pmpro_lds_assert( $qrow( $canon ) && $qrow( $canon + 1 ) && $qrow( $canon + 2 ), '13q-levels' );
		update_post_meta( $spec[0], '_tutorpress_pmpro_levels', array( $canon ) );
		list( $n, $res ) = $qget( $spec[0], $spec[2], $spec[3], $spec[1] );
		$d = $res instanceof WP_Error ? array() : $res->get_data();
		$ids = array_map( 'intval', wp_list_pluck( (array) ( $d['data']['plans'] ?? array() ), 'id' ) );
		tutorpress_pmpro_lds_assert( 1 === $n && true === ( $d['success'] ?? null ) && isset( $d['message'], $d['data'] ) && array( $canon ) === $ids, '13q-zero' );
		tutorpress_pmpro_lds_assert( $qrev( $canon + 1, $spec[1], $spec[0] ), '13q-one-row' );
		$hit = $qget( $spec[0], $spec[2], $spec[3], $spec[1] );
		tutorpress_pmpro_lds_assert( 1 === $hit[0] && ! ( $hit[1] instanceof WP_Error ), '13q-one' );
		tutorpress_pmpro_lds_assert( $qrev( $canon + 2, $spec[1], $spec[0] ), '13q-many-row' );
		$hit = $qget( $spec[0], $spec[2], $spec[3], $spec[1] );
		tutorpress_pmpro_lds_assert( 1 === $hit[0] && ! ( $hit[1] instanceof WP_Error ), '13q-many' );
		tutorpress_pmpro_lds_assert( $qrev( $canon + 1, $spec[1], $spec[0] ), '13q-dup-row' );
		$hit = $qget( $spec[0], $spec[2], $spec[3], $spec[1] );
		tutorpress_pmpro_lds_assert( 1 === $hit[0] && ! ( $hit[1] instanceof WP_Error ), '13q-dup' );
	}
	tutorpress_pmpro_lds_pass( 'discovery-query-count' );
	foreach ( array( array( $qc, 'tutorpress_course_id', 'course_id', 'get_course_subscriptions', $lid + 60 ), array( $qb, 'tutorpress_bundle_id', 'bundle_id', 'get_bundle_subscriptions', $lid + 63 ) ) as $spec ) {
		$qf = function ( $sql ) use ( $wpdb, $spec ) {
			if ( 0 === stripos( ltrim( (string) $sql ), 'SELECT' ) && false !== strpos( $sql, $wpdb->pmpro_membership_levels ) && false !== strpos( $sql, $wpdb->pmpro_membership_levelmeta ) && false !== strpos( $sql, $spec[1] ) && false !== strpos( $sql, (string) (int) $spec[0] ) ) {
				return 'SELECT no_such_13q_col FROM ' . $wpdb->pmpro_membership_levels . ' WHERE id = ' . (int) $spec[4];
			}
			return $sql;
		};
		$GLOBALS['tutorpress_pmpro_lds_reg']['hooks'][] = array( 'query', $qf );
		add_filter( 'query', $qf );
		$wpdb->last_error = '';
		$req13q = new WP_REST_Request( 'GET', '/tutorpress/v1/subscriptions' );
		$req13q->set_param( $spec[2], $spec[0] );
		$res13q = $ctl->{$spec[3]}( $req13q );
		remove_filter( 'query', $qf );
		tutorpress_pmpro_lds_assert( array( 500, 'database_error' ) === $err( $res13q ), '13q-db-' . $spec[2] );
		$wpdb->last_error = '';
	}
	tutorpress_pmpro_lds_pass( 'discovery-query-error' );
	$rm13 = new ReflectionMethod( $ctl, 'delete_subscription_plan' );
	$src13 = implode( '', array_slice( file( $rm13->getFileName() ), $rm13->getStartLine() - 1, $rm13->getEndLine() - $rm13->getStartLine() + 1 ) );
	tutorpress_pmpro_lds_assert( false !== strpos( $src13, "array( 'ok', 'committed_with_warning' )" ) && false === strpos( $src13, "'retired'" ) && false === strpos( $src13, "'unlinked'" ), '13d-success-list' );
	tutorpress_pmpro_lds_pass( 'removal-success-list' );
} catch ( Throwable $ex ) { fwrite( STDERR, $ex->getMessage() . ' ' . $ex->getFile() . ':' . $ex->getLine() . "\n" ); $tutorpress_pmpro_lds_failed = true; } finally { global $wpdb; $c = '\\TUTORPRESS_PMPRO\\PMPro_Level_Deletion_Coordinator'; $c::disarm_shutdown_guard(); $c::restore_deletion_listener(); $wpdb->query( 'ROLLBACK' ); unset( $GLOBALS['tutorpress_pmpro_lds_lock'], $GLOBALS['tutorpress_pmpro_lds_unlock'], $GLOBALS['tutorpress_pmpro_lds_in_txn'], $GLOBALS['tutorpress_pmpro_lds_txn'], $GLOBALS['tutorpress_pmpro_lds_cache_false'], $GLOBALS['tutorpress_pmpro_lds_flush_group'] ); foreach ( $GLOBALS['tutorpress_pmpro_lds_reg']['wpdb'] as $p => $v ) { $wpdb->$p = $v; } $ga = (int) get_post_meta( $a, '_tutorpress_pmpro_group_id', true ); $gh = (int) get_post_meta( $h ?? 0, '_tutorpress_pmpro_group_id', true ); foreach ( array( $lid + 1, $lid + 4, $lid + 5, $lid + 12, $lid + 13, $lid + 14, $lid + 15, $lid + 21, $lid + 22, $lid + 23, $lid + 24, $p1 ?? 0, $p2 ?? 0, $d1 ?? 0, $p3 ?? 0, $cok ?? 0, $dok ?? 0, $bok ?? 0, $bdk ?? 0, $own ?? 0, $frn ?? 0, $opp ?? 0, $uno ?? 0, $mis ?? 0, $m13 ?? 0, $d13 ?? 0, $m13b ?? 0, $d13b ?? 0, $v13 ?? 0, $u13 ?? 0, $r13 ?? 0, $lid + 40, $lid + 41, $lid + 42, $lid + 43, $lid + 44, $lid + 45, $lid + 46, $lid + 47, $lid + 48, $lid + 49, $lid + 50, $lid + 51, $lid + 52, $lid + 53, $lid + 54, $lid + 55, $lid + 60, $lid + 61, $lid + 62, $lid + 63, $lid + 64, $lid + 65, ...( $made13 ?? array() ), $dup ?? 0 ) as $x ) { if ( ! $x ) { continue; } $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $x ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d", $x ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d", $x ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_users} WHERE membership_id = %d", $x ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d", $x ) ); } foreach ( array_filter( array( $ga, $gh, (int) get_post_meta( $ac ?? 0, '_tutorpress_pmpro_group_id', true ), (int) get_post_meta( $ab ?? 0, '_tutorpress_pmpro_group_id', true ), (int) ( ( $GLOBALS['tutorpress_pmpro_lds_15c_groups'] ?? array() )[ $bso ?? 0 ] ?? 0 ), (int) ( ( $GLOBALS['tutorpress_pmpro_lds_15c_groups'] ?? array() )[ $bsd ?? 0 ] ?? 0 ) ) ) as $gg ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levels_groups} WHERE `group` = %d", $gg ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_groups} WHERE id = %d", $gg ) ); } foreach ( array( $uid ?? 0, $ed ?? 0, $inst ?? 0 ) as $u ) { if ( ! empty( $u ) && ! is_wp_error( $u ) ) { wp_delete_user( (int) $u ); } } tutorpress_pmpro_lds_cleanup(); if ( '' !== (string) $wpdb->last_error ) { $tutorpress_pmpro_lds_failed = true; } }
if ( $tutorpress_pmpro_lds_failed ) { exit( 1 ); }

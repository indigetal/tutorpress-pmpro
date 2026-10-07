<?php
require_once __DIR__ . '/bootstrap.php';
$base = 980000000 + (int) getmypid(); $held = $base + 71; $first = $base + 81; $second = $base + 91;
$posts = array(); $levels = array( $held, $first, $second ); $groups = array();
$sub = 0;
$author = 0;
$sort_cleanup = function () use ( &$posts, &$levels, &$groups ) {
	global $wpdb;
	foreach ( $posts as $id ) { $gid = (int) get_post_meta( $id, '_tutorpress_pmpro_group_id', true ); if ( $gid ) { $groups[] = $gid; } $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $id ) ); $GLOBALS['tutorpress_pmpro_lds_reg']['posts'][] = $id; }
	foreach ( $levels as $lid ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d", $lid ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d", $lid ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levels_groups} WHERE level = %d", $lid ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $lid ) ); }
	foreach ( $groups as $gid ) { $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_membership_levels_groups} WHERE `group` = %d", $gid ) ); $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_groups} WHERE id = %d", $gid ) ); }
	tutorpress_pmpro_lds_cleanup();
};
try {
	tutorpress_pmpro_lds_require_local_site();
	require_once dirname( __DIR__, 2 ) . '/includes/rest/class-pmpro-subscriptions-controller.php';
	$merge = new ReflectionMethod( 'TutorPress_PMPro_Subscriptions_Controller', 'merge_display_order' );
	$cases = array(
		array( 'dup-slot', array( 1, 9, 1, 2 ), array( 2, 1 ), array( 2, 9, 1, 1 ) ),
		array( 'perm', array( 1, 1, 2 ), array( 2, 1, 1 ), array( 2, 1, 1 ) ), array( 'append', array( 1, 1 ), array( 1, 4, 1 ), array( 1, 1, 4 ) ),
		array( 'empty', array(), array( 2, 1, 2 ), array( 2, 1, 2 ) ), array( 'nonpositive', array( 5, 0, -1, 2 ), array( 2, 5 ), array( 2, 0, -1, 5 ) ),
	);
	foreach ( $cases as $case ) {
		tutorpress_pmpro_lds_assert( $case[3] === $merge->invoke( null, $case[1], $case[2] ), $case[0] );
	}
	tutorpress_pmpro_lds_pass( 'merge-order' );
	$ctl = new TutorPress_PMPro_Subscriptions_Controller(); $preset = array( $first, $held, $second ); $submitted = array( $second, $first ); $expect = array( $second, $held, $first );
	$prior14 = get_current_user_id();
	wp_set_current_user( 1 );
	$malformed = $base + 15;
	tutorpress_pmpro_lds_assert( $malformed === (int) wp_insert_post( array( 'import_id' => $malformed, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es14a-' . $malformed, 'post_author' => 1 ) ), 'malformed-post' );
	$posts[] = $malformed;
	update_post_meta( $malformed, '_tutorpress_pmpro_levels', $preset );
	$snap14 = function () use ( $malformed, $held, $first, $second ) {
		global $wpdb;
		$ids = array( (int) $held, (int) $first, (int) $second );
		$marks = implode( ',', array_fill( 0, 3, '%d' ) );
		$reverse = $wpdb->get_results( $wpdb->prepare( "SELECT pmpro_membership_level_id, meta_key, meta_value FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id IN ($marks) AND meta_key IN (%s,%s) ORDER BY pmpro_membership_level_id, meta_key, meta_id", $ids[0], $ids[1], $ids[2], 'tutorpress_course_id', 'tutorpress_bundle_id' ), ARRAY_A );
		$pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d OR membership_id IN ($marks)", $malformed, $ids[0], $ids[1], $ids[2] ) );
		$gid = (int) get_post_meta( $malformed, '_tutorpress_pmpro_group_id', true );
		$groups = array( $gid, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_groups} WHERE id = %d", $gid ) ) );
		$mappings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE level IN ($marks) OR `group` = %d", $ids[0], $ids[1], $ids[2], $gid ) );
		return array( get_post_meta( $malformed, '_tutorpress_pmpro_levels', true ), $reverse, $pages, $groups, $mappings );
	};
	$before14 = $snap14();
	$path14 = '/tutorpress/v1/courses/' . $malformed . '/subscriptions/sort';
	$bodies14 = array(
		'zero' => '[0]', 'negative' => '[-7]', 'neg-string' => '["-7"]', 'zero-string' => '["0"]',
		'plus' => '["+7"]', 'padded' => '[" 7 "]', 'decimal-string' => '["7.0"]', 'float' => '[7.0]',
		'fraction' => '[7.5]', 'exponent' => '["7e0"]', 'true' => '[true]', 'false' => '[false]',
		'nested' => '[[7]]', 'object-item' => '[{"id":7}]', 'normalized-dup' => '[7,"7"]', 'exact-dup' => '[7,7]',
	);
	foreach ( $bodies14 as $name14 => $body14 ) {
		$req = new WP_REST_Request( 'PUT', $path14 );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( '{"plan_order":' . $body14 . '}' );
		$res = rest_do_request( $req );
		$data = $res->get_data();
		tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 400 === (int) $res->get_status() && is_array( $data ) && 'invalid_plan_order' === ( $data['code'] ?? '' ) && $before14 === $snap14(), 'malformed-' . $name14 );
	}
	$req = new WP_REST_Request( 'PUT', $path14 );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( 'label' => (string) $second ) ) ) );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 400 === (int) $res->get_status() && is_array( $data ) && 'rest_invalid_param' === ( $data['code'] ?? '' ) && $before14 === $snap14(), 'malformed-associative' );
	tutorpress_pmpro_lds_pass( 'malformed-order' );
	wp_set_current_user( $prior14 );
	wp_set_current_user( 1 );
	$oc = $base + 16; $oo = $base + 17; $ob = $base + 18;
	tutorpress_pmpro_lds_assert( $oc === (int) wp_insert_post( array( 'import_id' => $oc, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es14o-course-' . $oc, 'post_author' => 1 ) ), 'own-course' );
	$posts[] = $oc;
	tutorpress_pmpro_lds_assert( $oo === (int) wp_insert_post( array( 'import_id' => $oo, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es14o-other-' . $oo ) ), 'own-other' );
	$posts[] = $oo;
	tutorpress_pmpro_lds_assert( $ob === (int) wp_insert_post( array( 'import_id' => $ob, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'es14o-bundle-' . $ob ) ), 'own-bundle' );
	$posts[] = $ob;
	global $wpdb;
	$own_cols = array( 'description' => '', 'confirmation' => '', 'initial_payment' => 0, 'billing_amount' => 0, 'cycle_number' => 0, 'cycle_period' => '', 'billing_limit' => 0, 'trial_amount' => 0, 'trial_limit' => 0, 'allow_signups' => 0, 'expiration_number' => 0, 'expiration_period' => '' );
	foreach ( array( 72, 73, 74, 75 ) as $own_off ) {
		$own_id = $base + $own_off;
		tutorpress_pmpro_lds_assert( 1 === $wpdb->insert( $wpdb->pmpro_membership_levels, array_merge( array( 'id' => $own_id, 'name' => 'es14o-' . $own_id ), $own_cols ) ), 'own-level-' . $own_off );
		$levels[] = $own_id;
	}
	update_pmpro_membership_level_meta( $base + 72, 'tutorpress_course_id', (string) $oc );
	update_pmpro_membership_level_meta( $base + 73, 'tutorpress_course_id', (string) $oo );
	update_pmpro_membership_level_meta( $base + 74, 'tutorpress_course_id', (string) $oc );
	update_pmpro_membership_level_meta( $base + 74, 'tutorpress_bundle_id', (string) $ob );
	$own72 = pmpro_getLevel( $base + 72 ); $own73 = pmpro_getLevel( $base + 73 ); $own74 = pmpro_getLevel( $base + 74 ); $own75 = pmpro_getLevel( $base + 75 );
	tutorpress_pmpro_lds_assert( is_object( $own72 ) && (int) $own72->id === $base + 72 && ( 'es14o-' . ( $base + 72 ) ) === $own72->name && (string) $oc === (string) get_pmpro_membership_level_meta( $base + 72, 'tutorpress_course_id', true ) && '' === get_pmpro_membership_level_meta( $base + 72, 'tutorpress_bundle_id', true ), 'own-72' );
	tutorpress_pmpro_lds_assert( is_object( $own73 ) && (int) $own73->id === $base + 73 && ( 'es14o-' . ( $base + 73 ) ) === $own73->name && (string) $oo === (string) get_pmpro_membership_level_meta( $base + 73, 'tutorpress_course_id', true ) && '' === get_pmpro_membership_level_meta( $base + 73, 'tutorpress_bundle_id', true ), 'own-73' );
	tutorpress_pmpro_lds_assert( is_object( $own74 ) && (int) $own74->id === $base + 74 && ( 'es14o-' . ( $base + 74 ) ) === $own74->name && (string) $oc === (string) get_pmpro_membership_level_meta( $base + 74, 'tutorpress_course_id', true ) && (string) $ob === (string) get_pmpro_membership_level_meta( $base + 74, 'tutorpress_bundle_id', true ), 'own-74' );
	tutorpress_pmpro_lds_assert( is_object( $own75 ) && (int) $own75->id === $base + 75 && ( 'es14o-' . ( $base + 75 ) ) === $own75->name && '' === get_pmpro_membership_level_meta( $base + 75, 'tutorpress_course_id', true ) && '' === get_pmpro_membership_level_meta( $base + 75, 'tutorpress_bundle_id', true ), 'own-75' );
	tutorpress_pmpro_lds_assert( null === pmpro_getLevel( $base + 76 ) && ! in_array( $base + 76, $levels, true ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levelmeta} WHERE (pmpro_membership_level_id = %d AND meta_key = %s) OR (pmpro_membership_level_id = %d AND meta_key = %s) OR (pmpro_membership_level_id = %d AND meta_key = %s) OR (pmpro_membership_level_id = %d AND meta_key = %s)", $base + 72, 'tutorpress_bundle_id', $base + 73, 'tutorpress_bundle_id', $base + 75, 'tutorpress_course_id', $base + 75, 'tutorpress_bundle_id' ) ), 'own-76' );
	tutorpress_pmpro_lds_pass( 'ownership-fixtures' );
	$own_snap = function () use ( $oc, $oo, $ob, $base ) {
		global $wpdb;
		$ids = array( $base + 72, $base + 73, $base + 74, $base + 75 );
		$marks = implode( ',', array_fill( 0, 4, '%d' ) );
		$own_post_ids = array( (int) $oc, (int) $oo, (int) $ob );
		$reverse = $wpdb->get_results( $wpdb->prepare( "SELECT pmpro_membership_level_id, meta_key, meta_value FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id IN ($marks) AND meta_key IN (%s,%s) ORDER BY pmpro_membership_level_id, meta_key, meta_id", $ids[0], $ids[1], $ids[2], $ids[3], 'tutorpress_course_id', 'tutorpress_bundle_id' ), ARRAY_A );
		$pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id IN (%d,%d,%d) OR membership_id IN ($marks)", $own_post_ids[0], $own_post_ids[1], $own_post_ids[2], $ids[0], $ids[1], $ids[2], $ids[3] ) );
		$gids = array( (int) get_post_meta( $oc, '_tutorpress_pmpro_group_id', true ), (int) get_post_meta( $oo, '_tutorpress_pmpro_group_id', true ), (int) get_post_meta( $ob, '_tutorpress_pmpro_group_id', true ) );
		$groups = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_groups} WHERE id IN (%d,%d,%d)", $gids[0], $gids[1], $gids[2] ) );
		$mappings = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE level IN ($marks) OR `group` IN (%d,%d,%d)", $ids[0], $ids[1], $ids[2], $ids[3], $gids[0], $gids[1], $gids[2] ) );
		$missing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $base + 76 ) );
		return array( get_post_meta( $oc, '_tutorpress_pmpro_levels', true ), get_post_meta( $oo, '_tutorpress_pmpro_levels', true ), get_post_meta( $ob, '_tutorpress_pmpro_levels', true ), $reverse, $pages, $gids, $groups, $mappings, $missing );
	};
	update_post_meta( $oc, '_tutorpress_pmpro_levels', array( $base + 72 ) );
	$own_path = '/tutorpress/v1/courses/' . $oc . '/subscriptions/sort';
	$own_cases = array(
		'missing' => array( array( $base + 72, $base + 76 ), 404, 'level_not_found' ),
		'foreign' => array( array( $base + 72, $base + 73 ), 409, 'ownership_conflict' ),
		'contradictory' => array( array( $base + 72, $base + 74 ), 409, 'ownership_conflict' ),
	);
	foreach ( $own_cases as $own_name => $own_case ) {
		$before_own = $own_snap();
		$req = new WP_REST_Request( 'PUT', $own_path );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'plan_order' => $own_case[0] ) ) );
		$res = rest_do_request( $req );
		$data = $res->get_data();
		tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && $own_case[1] === (int) $res->get_status() && is_array( $data ) && $own_case[2] === ( $data['code'] ?? '' ) && $before_own === $own_snap(), 'submitted-' . $own_name );
	}
	update_post_meta( $oc, '_tutorpress_pmpro_levels', array( $base + 72, $base + 75 ) );
	$before_own = $own_snap();
	$req = new WP_REST_Request( 'PUT', $own_path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $base + 72, $base + 75 ) ) ) );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 409 === (int) $res->get_status() && is_array( $data ) && 'ownership_conflict' === ( $data['code'] ?? '' ) && $before_own === $own_snap(), 'submitted-unlinked' );
	tutorpress_pmpro_lds_pass( 'ownership-submitted' );
	$omit_cases = array(
		'missing' => array( array( $base + 72, $base + 76 ), 404, 'level_not_found' ),
		'foreign' => array( array( $base + 72, $base + 73 ), 409, 'ownership_conflict' ),
		'contradictory' => array( array( $base + 72, $base + 74 ), 409, 'ownership_conflict' ),
		'unlinked' => array( array( $base + 72, $base + 75 ), 409, 'ownership_conflict' ),
	);
	foreach ( $omit_cases as $omit_name => $omit_case ) {
		update_post_meta( $oc, '_tutorpress_pmpro_levels', $omit_case[0] );
		$before_omit = $own_snap();
		$req = new WP_REST_Request( 'PUT', $own_path );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'plan_order' => array( $base + 72 ) ) ) );
		$res = rest_do_request( $req );
		$data = $res->get_data();
		tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && $omit_case[1] === (int) $res->get_status() && is_array( $data ) && $omit_case[2] === ( $data['code'] ?? '' ) && $before_omit === $own_snap(), 'omitted-' . $omit_name );
	}
	tutorpress_pmpro_lds_pass( 'ownership-omitted' );
	wp_set_current_user( $prior14 );
	wp_set_current_user( 1 );
	foreach ( array( 'held' => $held, 'first' => $first, 'second' => $second ) as $fix_name => $fix_id ) {
		tutorpress_pmpro_lds_assert( 1 === $wpdb->insert( $wpdb->pmpro_membership_levels, array_merge( array( 'id' => $fix_id, 'name' => 'es14b-' . $fix_id ), $own_cols ), array( '%d', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%d', '%f', '%d', '%d', '%d', '%s' ) ), 'fix-level-' . $fix_name );
	}
	$own_sort_ids = function ( $object_id ) use ( $held, $first, $second ) {
		$bundle = 'course-bundle' === get_post_type( $object_id );
		$key    = $bundle ? 'tutorpress_bundle_id' : 'tutorpress_course_id';
		$other  = $bundle ? 'tutorpress_course_id' : 'tutorpress_bundle_id';
		foreach ( array( $held, $first, $second ) as $fix_id ) {
			update_pmpro_membership_level_meta( $fix_id, $key, (string) $object_id );
			delete_pmpro_membership_level_meta( $fix_id, $other );
		}
	};
	tutorpress_pmpro_lds_assert( 3 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels} WHERE id IN (%d,%d,%d)", $held, $first, $second ) ) && 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $base + 76 ) ) && in_array( $held, $levels, true ) && in_array( $first, $levels, true ) && in_array( $second, $levels, true ), 'fix-registered' );
	tutorpress_pmpro_lds_pass( 'owned-fixtures' );
	foreach ( array( 'courses' => $base + 1, 'course-bundle' => $base + 2 ) as $type => $oid ) {
		tutorpress_pmpro_lds_assert( $oid === (int) wp_insert_post( array( 'import_id' => $oid, 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'es2-' . $oid, 'post_author' => 1 ) ), $type );
		$posts[] = $oid;
		update_post_meta( $oid, '_tutorpress_pmpro_levels', $preset );
		$own_sort_ids( $oid );
		$req = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/sort' );
		$req->set_param( 'object_id', $oid );
		$req->set_param( 'ordered_ids', $submitted );
		$res = $ctl->sort_subscription_plans( $req );
		$stored = array_map( 'intval', (array) get_post_meta( $oid, '_tutorpress_pmpro_levels', true ) );
		tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && ! empty( $res->get_data()['success'] ) && $expect === $stored && $expect === array_map( 'intval', (array) ( $res->get_data()['data'] ?? array() ) ) && $held === (int) $stored[1], 'slot-' . $type );
	}
	tutorpress_pmpro_lds_pass( 'persist-slots' );
	$empty = $base + 3;
	tutorpress_pmpro_lds_assert( $empty === (int) wp_insert_post( array( 'import_id' => $empty, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es2e-' . $empty ) ), 'empty-post' );
	$posts[] = $empty;
	delete_post_meta( $empty, '_tutorpress_pmpro_levels' );
	$own_sort_ids( $empty );
	$req = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/sort' ); $req->set_param( 'object_id', $empty ); $req->set_param( 'ordered_ids', array( $second, $first ) );
	$res = $ctl->sort_subscription_plans( $req ); $stored = array_map( 'intval', (array) get_post_meta( $empty, '_tutorpress_pmpro_levels', true ) );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && array( $second, $first ) === $stored, 'empty-store' );
	tutorpress_pmpro_lds_pass( 'persist-empty' );
	wp_set_current_user( 1 );
	$course = $base + 6;
	tutorpress_pmpro_lds_assert( $course === (int) wp_insert_post( array( 'import_id' => $course, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es3-' . $course ) ), 'course-put-post' );
	$posts[] = $course;
	update_post_meta( $course, '_tutorpress_pmpro_levels', $preset );
	$own_sort_ids( $course );
	$req = new WP_REST_Request( 'PUT', '/tutorpress/v1/courses/' . $course . '/subscriptions/sort' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $second, $first ) ) ) );
	$res = rest_do_request( $req );
	$stored = array_map( 'intval', (array) get_post_meta( $course, '_tutorpress_pmpro_levels', true ) );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && ! empty( $res->get_data()['success'] ) && $expect === $stored && $expect === array_map( 'intval', (array) ( $res->get_data()['data'] ?? array() ) ), 'course-put' );
	tutorpress_pmpro_lds_pass( 'course-put' );
	$contract = $base + 7;
	tutorpress_pmpro_lds_assert( $contract === (int) wp_insert_post( array( 'import_id' => $contract, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es4-' . $contract ) ), 'course-contract-post' );
	$posts[] = $contract;
	update_post_meta( $contract, '_tutorpress_pmpro_levels', $preset );
	$path = '/tutorpress/v1/courses/' . $contract . '/subscriptions/sort';
	$sub = wp_insert_user( array( 'user_login' => 'es4s-' . $base, 'user_pass' => 'x', 'role' => 'subscriber' ) );
	tutorpress_pmpro_lds_assert( ! is_wp_error( $sub ) && (int) $sub > 0 && in_array( 'subscriber', (array) get_userdata( (int) $sub )->roles, true ), 'course-sub-user' );
	wp_set_current_user( (int) $sub );
	$req = new WP_REST_Request( 'PUT', $path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $second, $first ) ) ) );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 403 === (int) $res->get_status() && is_array( $data ) && 'rest_forbidden' === ( $data['code'] ?? '' ), 'course-forbidden' );
	tutorpress_pmpro_lds_pass( 'course-forbidden' );
	wp_set_current_user( 1 );
	$req = new WP_REST_Request( 'PUT', $path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( '{}' );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 400 === (int) $res->get_status() && is_array( $data ) && 'rest_missing_callback_param' === ( $data['code'] ?? '' ), 'course-missing' );
	tutorpress_pmpro_lds_pass( 'course-missing' );
	$req = new WP_REST_Request( 'PUT', $path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( 'label' => (string) $second ) ) ) );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 400 === (int) $res->get_status() && is_array( $data ) && 'rest_invalid_param' === ( $data['code'] ?? '' ), 'course-invalid' );
	tutorpress_pmpro_lds_pass( 'course-invalid' );
	$req = new WP_REST_Request( 'GET', $path );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 404 === (int) $res->get_status() && is_array( $data ) && 'rest_no_route' === ( $data['code'] ?? '' ), 'course-get-method' );
	tutorpress_pmpro_lds_pass( 'course-get-method' );
	$own_sort_ids( $contract );
	$req = new WP_REST_Request( 'POST', $path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( (string) $second, (string) $first ) ) ) );
	$res = rest_do_request( $req );
	$stored = array_map( 'intval', (array) get_post_meta( $contract, '_tutorpress_pmpro_levels', true ) );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && ! empty( $res->get_data()['success'] ) && $expect === $stored && $expect === array_map( 'intval', (array) ( $res->get_data()['data'] ?? array() ) ), 'course-post-strings' );
	tutorpress_pmpro_lds_pass( 'course-post-strings' );
	$owned = $base + 8;
	tutorpress_pmpro_lds_assert( $owned === (int) wp_insert_post( array( 'import_id' => $owned, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es4a-' . $owned, 'post_author' => 1 ) ), 'course-edit-posts-post' );
	$posts[] = $owned;
	update_post_meta( $owned, '_tutorpress_pmpro_levels', $preset );
	$author = wp_insert_user( array( 'user_login' => 'es4a-' . $base, 'user_pass' => 'x', 'role' => 'author' ) );
	tutorpress_pmpro_lds_assert( ! is_wp_error( $author ) && (int) $author > 0 && in_array( 'author', (array) get_userdata( (int) $author )->roles, true ), 'course-edit-posts-user' );
	wp_set_current_user( (int) $author );
	tutorpress_pmpro_lds_assert( current_user_can( 'edit_posts' ) && ! current_user_can( 'edit_post', $owned ), 'course-edit-posts-caps' );
	$req = new WP_REST_Request( 'PUT', '/tutorpress/v1/courses/' . $owned . '/subscriptions/sort' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $second, $first ) ) ) );
	$edit_pages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $owned ) );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	$stored = array_map( 'intval', (array) get_post_meta( $owned, '_tutorpress_pmpro_levels', true ) );
	$edit_pages_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $owned ) );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 403 === (int) $res->get_status() && is_array( $data ) && 'rest_forbidden' === ( $data['code'] ?? '' ) && $preset === $stored && $edit_pages === $edit_pages_after, 'course-edit-posts' );
	tutorpress_pmpro_lds_pass( 'course-edit-posts' );
	wp_set_current_user( 1 );
	$refresh = $base + 9;
	tutorpress_pmpro_lds_assert( $refresh === (int) wp_insert_post( array( 'import_id' => $refresh, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es4r-' . $refresh, 'post_author' => 1 ) ), 'course-refresh-post' );
	$posts[] = $refresh;
	$make = function( $name ) use ( $ctl, $refresh ) {
		$r = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions' );
		$r->set_param( 'object_id', $refresh );
		$r->set_param( 'plan_name', $name );
		$r->set_param( 'regular_price', 1 );
		$r->set_param( 'payment_type', 'one_time' );
		$res = $ctl->create_subscription_plan( $r );
		$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
		return (int) ( is_array( $data ) ? ( $data['data']['id'] ?? 0 ) : 0 );
	};
	$p1 = $make( 'es4r-a' );
	$p2 = $make( 'es4r-b' );
	$p3 = $make( 'es4r-c' );
	$levels[] = $p1; $levels[] = $p2; $levels[] = $p3;
	$stored = array_map( 'intval', (array) get_post_meta( $refresh, '_tutorpress_pmpro_levels', true ) );
	tutorpress_pmpro_lds_assert( $p1 && $p2 && $p3 && array( $p1, $p2, $p3 ) === $stored, 'course-refresh-order' );
	$state = '\\TUTORPRESS_PMPRO\\PMPro_Level_Removal_State';
	update_post_meta( $refresh, $state::META_KEY, array( 'version' => 1, 'levels' => array( (int) $p1 => array( 'state' => 'retired', 'kind' => 'one_time' ) ), 'blocked_kinds' => array( 'one_time' ), 'removed_restriction' => false ) );
	$state::invalidate_object( $refresh );
	$gid = (int) get_post_meta( $refresh, '_tutorpress_pmpro_group_id', true );
	if ( $gid ) { $groups[] = $gid; }
	$req = new WP_REST_Request( 'PUT', '/tutorpress/v1/courses/' . $refresh . '/subscriptions/sort' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $p3, $p2 ) ) ) );
	$res = rest_do_request( $req );
	$stored = array_map( 'intval', (array) get_post_meta( $refresh, '_tutorpress_pmpro_levels', true ) );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && array( $p1, $p3, $p2 ) === $stored && array( $p1, $p3, $p2 ) === array_map( 'intval', (array) ( $res->get_data()['data'] ?? array() ) ), 'course-refresh-meta' );
	$req = new WP_REST_Request( 'GET', '/tutorpress/v1/courses/' . $refresh . '/subscriptions' );
	$res = rest_do_request( $req );
	$body = $res->get_data();
	$ids = wp_list_pluck( (array) ( is_array( $body ) ? ( $body['data']['plans'] ?? array() ) : array() ), 'id' );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && array( (int) $p3, (int) $p2 ) === $ids, 'course-refresh' );
	tutorpress_pmpro_lds_pass( 'course-refresh' );
	$bundle = $base + 10;
	tutorpress_pmpro_lds_assert( $bundle === (int) wp_insert_post( array( 'import_id' => $bundle, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'es5-' . $bundle, 'post_author' => 1 ) ), 'bundle-put-post' );
	$posts[] = $bundle;
	update_post_meta( $bundle, '_tutorpress_pmpro_levels', $preset );
	$own_sort_ids( $bundle );
	global $wpdb;
	$pages_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $bundle ) );
	$req = new WP_REST_Request( 'PUT', '/tutorpress/v1/bundles/' . $bundle . '/subscriptions/sort' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $second, $first ) ) ) );
	$res = rest_do_request( $req );
	$stored = array_map( 'intval', (array) get_post_meta( $bundle, '_tutorpress_pmpro_levels', true ) );
	$pages_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $bundle ) );
	tutorpress_pmpro_lds_assert( 0 === $pages_before && $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && ! empty( $res->get_data()['success'] ) && $expect === $stored && $expect === array_map( 'intval', (array) ( $res->get_data()['data'] ?? array() ) ) && 0 === $pages_after, 'bundle-put' );
	tutorpress_pmpro_lds_pass( 'bundle-put' );
	$bundle_path = '/tutorpress/v1/bundles/' . $bundle . '/subscriptions/sort';
	wp_set_current_user( (int) $sub );
	$req = new WP_REST_Request( 'PUT', $bundle_path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $second, $first ) ) ) );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 403 === (int) $res->get_status() && is_array( $data ) && 'rest_forbidden' === ( $data['code'] ?? '' ), 'bundle-forbidden' );
	tutorpress_pmpro_lds_pass( 'bundle-forbidden' );
	wp_set_current_user( 1 );
	$req = new WP_REST_Request( 'PUT', $bundle_path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( '{}' );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 400 === (int) $res->get_status() && is_array( $data ) && 'rest_missing_callback_param' === ( $data['code'] ?? '' ), 'bundle-missing' );
	tutorpress_pmpro_lds_pass( 'bundle-missing' );
	$refresh_bundle = $base + 11;
	tutorpress_pmpro_lds_assert( $refresh_bundle === (int) wp_insert_post( array( 'import_id' => $refresh_bundle, 'post_type' => 'course-bundle', 'post_status' => 'publish', 'post_title' => 'es5r-' . $refresh_bundle, 'post_author' => 1 ) ), 'bundle-refresh-post' );
	$posts[] = $refresh_bundle;
	$make_bundle = function ( $name ) use ( $ctl, $refresh_bundle ) {
		$r = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions' );
		$r->set_param( 'object_id', $refresh_bundle );
		$r->set_param( 'plan_name', $name );
		$r->set_param( 'regular_price', 1 );
		$r->set_param( 'payment_type', 'one_time' );
		$res = $ctl->create_subscription_plan( $r );
		$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
		return (int) ( is_array( $data ) ? ( $data['data']['id'] ?? 0 ) : 0 );
	};
	$b1 = $make_bundle( 'es5r-a' );
	$b2 = $make_bundle( 'es5r-b' );
	$levels[] = $b1;
	$levels[] = $b2;
	$gid = (int) get_post_meta( $refresh_bundle, '_tutorpress_pmpro_group_id', true );
	if ( $gid ) { $groups[] = $gid; }
	$req = new WP_REST_Request( 'PUT', '/tutorpress/v1/bundles/' . $refresh_bundle . '/subscriptions/sort' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $b2, $b1 ) ) ) );
	rest_do_request( $req );
	$req = new WP_REST_Request( 'GET', '/tutorpress/v1/bundles/' . $refresh_bundle . '/subscriptions' );
	$res = rest_do_request( $req );
	$body = $res->get_data();
	$ids = wp_list_pluck( (array) ( is_array( $body ) ? ( $body['data']['plans'] ?? array() ) : array() ), 'id' );
	tutorpress_pmpro_lds_assert( $b1 && $b2 && $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && array( (int) $b2, (int) $b1 ) === $ids, 'bundle-refresh' );
	tutorpress_pmpro_lds_pass( 'bundle-refresh' );
	$invalid = $base + 12;
	tutorpress_pmpro_lds_assert( $invalid === (int) wp_insert_post( array( 'import_id' => $invalid, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es6-' . $invalid, 'post_author' => 1 ) ), 'course-invalid-state-post' );
	$posts[] = $invalid;
	update_post_meta( $invalid, '_tutorpress_pmpro_levels', $preset );
	update_post_meta( $invalid, $state::META_KEY, array( 'version' => 1, 'levels' => array( (int) $held => array( 'state' => 'retired', 'kind' => 'one_time' ) ), 'blocked_kinds' => array( 'one_time' ), 'removed_restriction' => false ) );
	add_post_meta( $invalid, $state::META_KEY, array( 'version' => 2 ), false );
	wp_cache_delete( $invalid, 'post_meta' );
	$state::invalidate_object( $invalid );
	$wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $held, 'page_id' => $invalid ) );
	$pages_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $held, $invalid ) );
	$req = new WP_REST_Request( 'PUT', '/tutorpress/v1/courses/' . $invalid . '/subscriptions/sort' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $second, $first ) ) ) );
	$res = rest_do_request( $req );
	$data = $res->get_data();
	$stored = array_map( 'intval', (array) get_post_meta( $invalid, '_tutorpress_pmpro_levels', true ) );
	$pages_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $held, $invalid ) );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 500 === (int) $res->get_status() && is_array( $data ) && 'invalid_state' === ( $data['code'] ?? '' ) && $preset === $stored && 1 === $pages_before && 1 === $pages_after, 'course-invalid-state' );
	tutorpress_pmpro_lds_pass( 'course-invalid-state' );
	$retired = $base + 13;
	tutorpress_pmpro_lds_assert( $retired === (int) wp_insert_post( array( 'import_id' => $retired, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es6r-' . $retired, 'post_author' => 1 ) ), 'course-retired-post' );
	$posts[] = $retired;
	$make_retired = function ( $name ) use ( $ctl, $retired ) {
		$r = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions' );
		$r->set_param( 'object_id', $retired );
		$r->set_param( 'plan_name', $name );
		$r->set_param( 'regular_price', 1 );
		$r->set_param( 'payment_type', 'one_time' );
		$res = $ctl->create_subscription_plan( $r );
		$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
		return (int) ( is_array( $data ) ? ( $data['data']['id'] ?? 0 ) : 0 );
	};
	$r1 = $make_retired( 'es6r-a' );
	$r2 = $make_retired( 'es6r-b' );
	$r3 = $make_retired( 'es6r-c' );
	$levels[] = $r1; $levels[] = $r2; $levels[] = $r3;
	$stored = array_map( 'intval', (array) get_post_meta( $retired, '_tutorpress_pmpro_levels', true ) );
	tutorpress_pmpro_lds_assert( $r1 && $r2 && $r3 && array( $r1, $r2, $r3 ) === $stored, 'course-retired-order' );
	update_post_meta( $retired, $state::META_KEY, array( 'version' => 1, 'levels' => array( (int) $r1 => array( 'state' => 'retired', 'kind' => 'one_time' ) ), 'blocked_kinds' => array( 'one_time' ), 'removed_restriction' => false ) );
	$state::invalidate_object( $retired );
	$gid = (int) get_post_meta( $retired, '_tutorpress_pmpro_group_id', true );
	if ( $gid ) { $groups[] = $gid; }
	$pages_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $r1, $retired ) );
	$req = new WP_REST_Request( 'PUT', '/tutorpress/v1/courses/' . $retired . '/subscriptions/sort' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'plan_order' => array( $r3, $r2 ) ) ) );
	$res = rest_do_request( $req );
	$stored = array_map( 'intval', (array) get_post_meta( $retired, '_tutorpress_pmpro_levels', true ) );
	$pages_after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $r1, $retired ) );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && array( $r1, $r3, $r2 ) === $stored && array( $r1, $r3, $r2 ) === array_map( 'intval', (array) ( $res->get_data()['data'] ?? array() ) ) && 1 === $pages_before && 1 === $pages_after, 'course-retired-meta' );
	$req = new WP_REST_Request( 'GET', '/tutorpress/v1/courses/' . $retired . '/subscriptions' );
	$res = rest_do_request( $req );
	$body = $res->get_data();
	$ids = wp_list_pluck( (array) ( is_array( $body ) ? ( $body['data']['plans'] ?? array() ) : array() ), 'id' );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && array( (int) $r3, (int) $r2 ) === $ids, 'course-retired-keep' );
	tutorpress_pmpro_lds_pass( 'course-retired-keep' );
	$legacy = $base + 14;
	tutorpress_pmpro_lds_assert( $legacy === (int) wp_insert_post( array( 'import_id' => $legacy, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es6l-' . $legacy, 'post_author' => 1 ) ), 'legacy-post-post' );
	$posts[] = $legacy;
	update_post_meta( $legacy, '_tutorpress_pmpro_levels', $preset );
	$own_sort_ids( $legacy );
	$req = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/sort' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( array( 'object_id' => $legacy, 'ordered_ids' => array( $second, $first ) ) ) );
	$res = rest_do_request( $req );
	$stored = array_map( 'intval', (array) get_post_meta( $legacy, '_tutorpress_pmpro_levels', true ) );
	$pages = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d ORDER BY membership_id ASC", $legacy ) ) );
	$want = $expect;
	sort( $want );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && ! empty( $res->get_data()['success'] ) && $expect === $stored && $expect === array_map( 'intval', (array) ( $res->get_data()['data'] ?? array() ) ) && $want === $pages, 'legacy-post' );
	tutorpress_pmpro_lds_pass( 'legacy-post' );
	$same = $base + 4;
	tutorpress_pmpro_lds_assert( $same === (int) wp_insert_post( array( 'import_id' => $same, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es2u-' . $same ) ), 'same-post' );
	$posts[] = $same;
	update_post_meta( $same, '_tutorpress_pmpro_levels', array( $first, $second ) );
	$own_sort_ids( $same );
	$writes = 0;
	$count_fn = static function ( $check, $object_id, $meta_key ) use ( &$writes ) { if ( '_tutorpress_pmpro_levels' === $meta_key ) { $writes++; } return null; };
	add_filter( 'update_post_metadata', $count_fn, 10, 5 ); $GLOBALS['tutorpress_pmpro_lds_reg']['hooks'][] = array( 'update_post_metadata', $count_fn, 10 );
	$req = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/sort' ); $req->set_param( 'object_id', $same ); $req->set_param( 'ordered_ids', array( $first, $second ) );
	$res = $ctl->sort_subscription_plans( $req );
	tutorpress_pmpro_lds_assert( $res instanceof WP_REST_Response && 200 === (int) $res->get_status() && 0 === $writes && array( $first, $second ) === array_map( 'intval', (array) ( $res->get_data()['data'] ?? array() ) ), 'unchanged' );
	tutorpress_pmpro_lds_pass( 'persist-unchanged' );
	$false_oid = $base + 5;
	tutorpress_pmpro_lds_assert( $false_oid === (int) wp_insert_post( array( 'import_id' => $false_oid, 'post_type' => 'courses', 'post_status' => 'publish', 'post_title' => 'es2f-' . $false_oid ) ), 'false-post' );
	$posts[] = $false_oid;
	update_post_meta( $false_oid, '_tutorpress_pmpro_levels', $preset );
	$own_sort_ids( $false_oid );
	global $wpdb; $wpdb->insert( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $held, 'page_id' => $false_oid ) );
	$block_fn = static function ( $check, $object_id, $meta_key ) { if ( '_tutorpress_pmpro_levels' === $meta_key ) { return false; } return null; };
	add_filter( 'update_post_metadata', $block_fn, 10, 5 ); $GLOBALS['tutorpress_pmpro_lds_reg']['hooks'][] = array( 'update_post_metadata', $block_fn, 10 );
	$req = new WP_REST_Request( 'POST', '/tutorpress/v1/subscriptions/sort' ); $req->set_param( 'object_id', $false_oid ); $req->set_param( 'ordered_ids', $submitted );
	$res = $ctl->sort_subscription_plans( $req ); $stored = array_map( 'intval', (array) get_post_meta( $false_oid, '_tutorpress_pmpro_levels', true ) );
	$page = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d", $held, $false_oid ) );
	tutorpress_pmpro_lds_assert( $res instanceof WP_Error && 'order_persist_failed' === $res->get_error_code() && 500 === (int) ( $res->get_error_data()['status'] ?? 0 ) && $preset === $stored && 1 === $page, 'false-keep' );
	tutorpress_pmpro_lds_pass( 'persist-false' );
} finally {
	if ( is_int( $sub ) && $sub > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $sub );
	}
	if ( is_int( $author ) && $author > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( (int) $author );
	}
	$sort_cleanup();
}

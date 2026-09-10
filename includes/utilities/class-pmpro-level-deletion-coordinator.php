<?php
namespace TUTORPRESS_PMPRO;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
class PMPro_Level_Deletion_Coordinator {
	private static function relationship_apis_callable() {
		return function_exists( 'pmpro_get_membership_level_relationship_tables' ) && function_exists( 'pmpro_delete_membership_level_relationships' );
	}
	public static function participating_write_tables( $operation ) {
		global $wpdb; $rel = array();
		if ( 'full' === $operation && self::relationship_apis_callable() ) { foreach ( pmpro_get_membership_level_relationship_tables() as $r ) { $rel[] = is_array( $r ) && is_string( $r['table'] ?? '' ) ? $r['table'] : ''; } }
		return 'full' === $operation ? array_values( array_unique( array_merge( $rel, array( $wpdb->pmpro_membership_levels, $wpdb->postmeta, $wpdb->pmpro_groups ) ) ) ) : ( in_array( $operation, array( 'shared', 'stale' ), true ) ? array( $wpdb->pmpro_memberships_pages, $wpdb->pmpro_membership_levels_groups, $wpdb->pmpro_membership_levelmeta, $wpdb->postmeta ) : array() );
	}
	public static function probe_write_tables( $tables ) {
		global $wpdb; $non = false;
		if ( empty( $tables ) ) { return 'error'; }
		foreach ( (array) $tables as $table ) {
			if ( ! is_string( $table ) || '' === $table ) { return 'error'; }
			$engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s', $wpdb->dbname, $table ) );
			if ( null === $engine || false === $engine || '' === $engine || '' !== $wpdb->last_error ) { return 'error'; }
			if ( 0 !== strcasecmp( (string) $engine, 'InnoDB' ) ) { $non = true; }
		}
		return $non ? 'nontransactional' : 'ok';
	}
	public static function session_in_transaction() {
		global $wpdb;
		if ( array_key_exists( 'tutorpress_pmpro_lds_in_txn', $GLOBALS ) ) { return $GLOBALS['tutorpress_pmpro_lds_in_txn']; }
		$v = $wpdb->get_var( 'SELECT @@session.in_transaction' );
		return ( '1' === $v || 1 === $v ) ? true : ( ( '0' === $v || 0 === $v ) ? false : null );
	}
	public static function advisory_lock_name( $level_id ) {
		global $wpdb;
		return 'tp_pmpro_delete:' . substr( md5( (string) $wpdb->dbname . (string) $wpdb->prefix ), 0, 12 ) . ':' . (int) $level_id;
	}
	private static function interpret_lock_cell( $v, $zero, $one ) {
		global $wpdb;
		if ( '1' === $v || 1 === $v ) { return $one; }
		if ( '0' === $v || 0 === $v ) { return $zero; }
		return ( null === $v && ( false !== stripos( (string) $wpdb->last_error, 'unknown function' ) || false !== stripos( (string) $wpdb->last_error, 'does not exist' ) ) ) ? 'lock_unsupported' : 'lock_error';
	}
	public static function acquire_advisory_lock( $level_id ) {
		global $wpdb;
		$level_id = (int) $level_id;
		if ( $level_id <= 0 ) { return 'lock_error'; }
		if ( array_key_exists( 'tutorpress_pmpro_lds_lock', $GLOBALS ) ) { $v = $GLOBALS['tutorpress_pmpro_lds_lock']; } else { $v = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::advisory_lock_name( $level_id ) ) ); }
		return self::interpret_lock_cell( $v, 'busy', 'acquired' );
	}
	public static function release_advisory_lock( $level_id ) {
		global $wpdb;
		$level_id = (int) $level_id;
		if ( $level_id <= 0 ) { return 'lock_error'; }
		if ( array_key_exists( 'tutorpress_pmpro_lds_unlock', $GLOBALS ) ) { $v = $GLOBALS['tutorpress_pmpro_lds_unlock']; } else { $v = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::advisory_lock_name( $level_id ) ) ); }
		return self::interpret_lock_cell( $v, 'lock_release_failed', 'released' );
	}
	private static $g_on = false;
	private static $g_lv = 0;
	private static $g_ps = array();
	private static $ls = false;
	public static function deletion_listener_suppressed() { return self::$ls; }
	public static function restore_deletion_listener() { self::$ls = false; }
	public static function control_transaction( $op ) {
		global $wpdb;
		$sql = array( 'begin' => 'START TRANSACTION', 'commit' => 'COMMIT', 'rollback' => 'ROLLBACK', 'savepoint' => 'SAVEPOINT tp_pmpro_delete_sentinel', 'release_sp' => 'RELEASE SAVEPOINT tp_pmpro_delete_sentinel' );
		if ( ! isset( $sql[ $op ] ) ) { return 'begin_failed'; }
		$in = self::session_in_transaction();
		if ( 'begin' === $op ) {
			if ( true === $in ) { return 'outer_transaction_active'; }
			if ( null === $in ) { return 'transaction_state_unknown'; }
		} elseif ( ( 'savepoint' === $op || 'release_sp' === $op ) && true !== $in ) { return 'hook_transaction_invalidated'; }
		$q = array_key_exists( 'tutorpress_pmpro_lds_txn', $GLOBALS ) ? $GLOBALS['tutorpress_pmpro_lds_txn'] : $wpdb->query( $sql[ $op ] );
		if ( 'begin' === $op ) { return ( false === $q || true !== self::session_in_transaction() ) ? 'begin_failed' : 'ok'; }
		if ( 'commit' === $op ) {
			$in = self::session_in_transaction();
			if ( false !== $q && false === $in ) { return 'ok'; }
			if ( false === $q && true === $in ) { $wpdb->query( 'ROLLBACK' ); return 'commit_failed'; }
			return 'transaction_outcome_unknown';
		}
		if ( 'rollback' === $op ) { return false === $q ? 'rollback_failed' : 'ok'; }
		return false === $q ? 'hook_transaction_invalidated' : 'ok';
	}
	public static function invalidate_deletion_caches( $level_id, $post_ids ) {
		$hit = function( $id, $g ) { return array_key_exists( 'tutorpress_pmpro_lds_cache_false', $GLOBALS ) ? false : wp_cache_delete( $id, $g ); };
		$hit( (int) $level_id, 'pmpro_membership_level_meta' );
		foreach ( (array) $post_ids as $p ) { if ( (int) $p > 0 ) { $hit( (int) $p, 'post_meta' ); } }
		if ( function_exists( 'pmpro_getAllLevels' ) ) { pmpro_getAllLevels( true, true, true ); }
		return 'ok';
	}
	public static function arm_shutdown_guard( $level_id, $post_ids ) {
		self::$g_on = true; self::$g_lv = (int) $level_id; self::$g_ps = (array) $post_ids;
		static $r = false; if ( ! $r ) { register_shutdown_function( array( __CLASS__, 'run_shutdown_guard' ) ); $r = true; }
	}
	public static function disarm_shutdown_guard() { self::$g_on = false; }
	public static function run_shutdown_guard() {
		if ( ! self::$g_on ) { return; }
		self::$g_on = false;
		if ( true === self::session_in_transaction() ) { self::control_transaction( 'rollback' ); }
		self::invalidate_deletion_caches( self::$g_lv, self::$g_ps );
		self::release_advisory_lock( self::$g_lv );
	}
	public static function preflight( $level_id, $object_id, $object_type, $own = false ) {
		if ( ! self::relationship_apis_callable() ) {
			return 'nontransactional';
		}
		$level_id = (int) $level_id;
		if ( $level_id <= 0 ) {
			return PMPro_Level_Deletion_Guard::evaluate( $level_id, $object_id, $object_type );
		}
		global $wpdb;
		if ( empty( $wpdb->pmpro_membership_levels ) ) {
			return 'infrastructure';
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$wpdb->pmpro_membership_levels}` WHERE id = %d", $level_id ) );
		if ( false === $exists || '' !== $wpdb->last_error ) {
			return 'infrastructure';
		}
		$result = ( null === $exists || '' === $exists ) ? 'missing' : PMPro_Level_Deletion_Guard::evaluate( $level_id, $object_id, $object_type );
		if ( 'allowed' !== $result ) { return $result; }
		$probe = self::probe_write_tables( self::participating_write_tables( 'full' ) );
		if ( 'ok' !== $probe ) { return 'error' === $probe ? 'infrastructure' : 'nontransactional'; }
		if ( $own ) { return $result; }
		$in = self::session_in_transaction();
		return true === $in ? 'outer_transaction_active' : ( null === $in ? 'transaction_state_unknown' : $result );
	}
	public static function delete_level( $level_id, $object_id, $object_type ) {
		global $wpdb;
		$level_id = (int) $level_id; $object_id = (int) $object_id;
		$pre = self::preflight( $level_id, $object_id, $object_type );
		if ( 'allowed' !== $pre && 'shared_unlink' !== $pre ) { return $pre; }
		$lock = self::acquire_advisory_lock( $level_id );
		if ( 'acquired' !== $lock ) { return $lock; }
		$begin = self::control_transaction( 'begin' );
		if ( 'ok' !== $begin ) { return 'released' !== self::release_advisory_lock( $level_id ) ? 'lock_release_failed' : $begin; }
		$pre = self::preflight( $level_id, $object_id, $object_type, true );
		$posts = $wpdb->get_col( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type IN ( %s, %s )", '_tutorpress_pmpro_levels', 'courses', 'course-bundle' ) );
		$posts = array_values( array_unique( array_filter( array_map( 'intval', is_array( $posts ) ? array_merge( array( $object_id ), $posts ) : array( $object_id ) ) ) ) );
		$gid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $object_id, '_tutorpress_pmpro_group_id' ) );
		self::arm_shutdown_guard( $level_id, $posts );
		if ( 'shared_unlink' === $pre ) { if ( true === self::session_in_transaction() ) { self::control_transaction( 'rollback' ); } self::disarm_shutdown_guard(); $u = PMPro_Level_Cleanup::unlink_shared_level( $object_id, $level_id ); self::invalidate_deletion_caches( $level_id, $posts ); self::release_advisory_lock( $level_id ); return $u; }
		if ( 'allowed' !== $pre || 'ok' !== self::control_transaction( 'savepoint' ) ) { return self::fail_delete( $level_id, $posts, 'allowed' !== $pre ? $pre : 'hook_transaction_invalidated' ); }
		self::$ls = true;
		try { do_action( 'pmpro_delete_membership_level', $level_id ); } catch ( \Throwable $t ) { return self::fail_delete( $level_id, $posts, 'cleanup_failed' ); }
		$pre = self::preflight( $level_id, $object_id, $object_type, true );
		$g2 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1", $object_id, '_tutorpress_pmpro_group_id' ) );
		if ( 'ok' !== self::control_transaction( 'release_sp' ) ) { return self::fail_delete( $level_id, $posts, 'hook_transaction_invalidated' ); }
		if ( 'allowed' !== $pre || $g2 !== $gid || true !== PMPro_Level_Cleanup::prune_verified_target_duplicates( $level_id ) || true !== PMPro_Level_Cleanup::delete_level_relationships( $level_id ) || true !== PMPro_Level_Cleanup::delete_owned_empty_group( $object_id ) ) { return self::fail_delete( $level_id, $posts, 'missing' === $pre ? 'conflict' : ( 'allowed' !== $pre ? $pre : 'cleanup_failed' ) ); }
		if ( true !== PMPro_Level_Cleanup::delete_level_row( $level_id ) ) { return self::fail_delete( $level_id, $posts, $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $level_id ) ) ? 'cleanup_failed' : 'conflict' ); }
		$cm = self::control_transaction( 'commit' );
		if ( 'commit_failed' === $cm ) { return self::fail_delete( $level_id, $posts, $cm ); }
		if ( 'ok' !== $cm ) { $in = self::session_in_transaction(); $ex = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $level_id ) ); if ( true === $in || null === $in || false === $ex || '' !== $wpdb->last_error ) { return self::fail_delete( $level_id, $posts, 'transaction_outcome_unknown' ); } if ( $ex ) { return self::fail_delete( $level_id, $posts, 'commit_failed' ); } $cm = 'committed_with_warning'; }
		self::disarm_shutdown_guard(); self::restore_deletion_listener(); self::invalidate_deletion_caches( $level_id, $posts ); if ( 'released' !== self::release_advisory_lock( $level_id ) ) { return 'committed_with_warning'; }
		try { do_action( 'tutorpress_pmpro_membership_level_deleted', $level_id, $object_id, 'committed_with_warning' === $cm ? array( 'warning' => true ) : null ); } catch ( \Throwable $t ) { error_log( '[TP-PMPRO] ' . $t->getMessage() ); }
		return 'ok' === $cm ? 'ok' : 'committed_with_warning';
	}
	private static function fail_delete( $level_id, $posts, $code ) {
		if ( true === self::session_in_transaction() ) { self::control_transaction( 'rollback' ); }
		self::disarm_shutdown_guard(); self::restore_deletion_listener(); self::invalidate_deletion_caches( $level_id, $posts ); return 'released' !== self::release_advisory_lock( $level_id ) ? 'lock_release_failed' : $code;
	}
}

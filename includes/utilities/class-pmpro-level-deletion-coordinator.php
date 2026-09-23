<?php
/**
 * PMPro Level Deletion Coordinator
 *
 * Owns the instructor full-delete and shared-unlink path: advisory locks,
 * transaction control, shutdown rollback, cache invalidation, and the
 * ordered write sequence. Guard evaluation decides whether a level may be
 * deleted, unlinked, or retained.
 *
 * This class does not require the cleanup helper by file path. Cleanup methods
 * are invoked only after a decision is committed to a named outcome.
 *
 * Test seams (request globals):
 * - tutorpress_pmpro_lds_lock / tutorpress_pmpro_lds_unlock
 * - tutorpress_pmpro_lds_in_txn / tutorpress_pmpro_lds_txn
 * - tutorpress_pmpro_lds_cache_false
 *
 * @package TutorPress_PMPro
 * @subpackage Utilities
 * @since 1.0.8
 */

namespace TUTORPRESS_PMPRO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PMPro_Level_Deletion_Coordinator
 *
 * Coordinates guarded PMPro level deletion for a requesting course or bundle.
 */
class PMPro_Level_Deletion_Coordinator {

	/**
	 * Whether both PMPro 3.8+ relationship helpers are loaded.
	 *
	 * Missing helpers force a nontransactional result so this addon never
	 * guesses the relationship table set.
	 *
	 * @since 1.0.8
	 *
	 * @return bool True when both relationship functions exist.
	 */
	private static function relationship_apis_callable() {
		return function_exists( 'pmpro_get_membership_level_relationship_tables' ) && function_exists( 'pmpro_delete_membership_level_relationships' );
	}

	/**
	 * Tables written by a full delete, shared unlink, or stale cleanup.
	 *
	 * Full delete unions PMPro relationship tables with the level row,
	 * postmeta, and groups. Shared and stale use the requester-scoped set:
	 * pages, level-group maps, levelmeta, and postmeta.
	 *
	 * @since 1.0.8
	 *
	 * @param string $operation One of full, shared, or stale.
	 * @return string[] Table names. Empty for an unknown operation.
	 */
	public static function participating_write_tables( $operation ) {
		global $wpdb;
		$rel = array();
		if ( 'full' === $operation && self::relationship_apis_callable() ) {
			foreach ( pmpro_get_membership_level_relationship_tables() as $r ) {
				$rel[] = is_array( $r ) && is_string( $r['table'] ?? '' ) ? $r['table'] : '';
			}
		}
		if ( 'full' === $operation ) {
			return array_values(
				array_unique(
					array_merge(
						$rel,
						array( $wpdb->pmpro_membership_levels, $wpdb->postmeta, $wpdb->pmpro_groups )
					)
				)
			);
		}
		if ( in_array( $operation, array( 'shared', 'stale' ), true ) ) {
			return array(
				$wpdb->pmpro_memberships_pages,
				$wpdb->pmpro_membership_levels_groups,
				$wpdb->pmpro_membership_levelmeta,
				$wpdb->postmeta,
			);
		}
		return array();
	}

	/**
	 * Classify whether participating tables can join one transaction.
	 *
	 * Empty or blank names, missing information_schema rows, or query errors
	 * return error. Any non-InnoDB engine is nontransactional.
	 *
	 * @since 1.0.8
	 *
	 * @param string[] $tables Table names to probe.
	 * @return string ok, nontransactional, or error.
	 */
	public static function probe_write_tables( $tables ) {
		global $wpdb;
		$non = false;
		if ( empty( $tables ) ) {
			return 'error';
		}
		foreach ( (array) $tables as $table ) {
			if ( ! is_string( $table ) || '' === $table ) {
				return 'error';
			}
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					$wpdb->dbname,
					$table
				)
			);
			if ( null === $engine || false === $engine || '' === $engine || '' !== $wpdb->last_error ) {
				return 'error';
			}
			if ( 0 !== strcasecmp( (string) $engine, 'InnoDB' ) ) {
				$non = true;
			}
		}
		return $non ? 'nontransactional' : 'ok';
	}

	/**
	 * Whether the current MySQL session is inside a transaction.
	 *
	 * Test seam: tutorpress_pmpro_lds_in_txn. A non-boolean/non-0-1 cell is
	 * unknown (null).
	 *
	 * @since 1.0.8
	 *
	 * @return bool|null True in a transaction, false when idle, null if unknown.
	 */
	public static function session_in_transaction() {
		global $wpdb;
		if ( array_key_exists( 'tutorpress_pmpro_lds_in_txn', $GLOBALS ) ) {
			return $GLOBALS['tutorpress_pmpro_lds_in_txn'];
		}
		$v = $wpdb->get_var( 'SELECT @@session.in_transaction' );
		return ( '1' === $v || 1 === $v ) ? true : ( ( '0' === $v || 0 === $v ) ? false : null );
	}

	/**
	 * Deterministic MySQL advisory lock name for one PMPro level.
	 *
	 * Format tp_pmpro_delete:{12-char md5(dbname+prefix)}:{level_id}. Length is
	 * at most 64 characters for GET_LOCK.
	 *
	 * @since 1.0.8
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return string Lock name.
	 */
	public static function advisory_lock_name( $level_id ) {
		global $wpdb;
		return 'tp_pmpro_delete:' . substr( md5( (string) $wpdb->dbname . (string) $wpdb->prefix ), 0, 12 ) . ':' . (int) $level_id;
	}

	/**
	 * Map a GET_LOCK / RELEASE_LOCK cell to a result code.
	 *
	 * 1 maps to $one, 0 maps to $zero. A NULL cell with last_error mentioning
	 * an unknown/missing function is lock_unsupported; any other failure is
	 * lock_error.
	 *
	 * @since 1.0.8
	 *
	 * @param mixed  $v    Raw lock function result.
	 * @param string $zero  Code when the cell is 0.
	 * @param string $one   Code when the cell is 1.
	 * @return string Lock result code.
	 */
	private static function interpret_lock_cell( $v, $zero, $one ) {
		global $wpdb;
		if ( '1' === $v || 1 === $v ) {
			return $one;
		}
		if ( '0' === $v || 0 === $v ) {
			return $zero;
		}
		return ( null === $v && ( false !== stripos( (string) $wpdb->last_error, 'unknown function' ) || false !== stripos( (string) $wpdb->last_error, 'does not exist' ) ) )
			? 'lock_unsupported'
			: 'lock_error';
	}

	/**
	 * Acquire a nonblocking level advisory lock.
	 *
	 * Non-positive IDs return lock_error without querying. Test seam:
	 * tutorpress_pmpro_lds_lock.
	 *
	 * @since 1.0.8
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return string acquired, busy, lock_unsupported, or lock_error.
	 */
	public static function acquire_advisory_lock( $level_id ) {
		global $wpdb;
		$level_id = (int) $level_id;
		if ( $level_id <= 0 ) {
			return 'lock_error';
		}
		if ( array_key_exists( 'tutorpress_pmpro_lds_lock', $GLOBALS ) ) {
			$v = $GLOBALS['tutorpress_pmpro_lds_lock'];
		} else {
			$v = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::advisory_lock_name( $level_id ) ) );
		}
		return self::interpret_lock_cell( $v, 'busy', 'acquired' );
	}

	/**
	 * Release a level advisory lock held by this connection.
	 *
	 * RELEASE_LOCK 0 is lock_release_failed. Test seam: tutorpress_pmpro_lds_unlock.
	 *
	 * @since 1.0.8
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return string released, lock_release_failed, lock_unsupported, or lock_error.
	 */
	public static function release_advisory_lock( $level_id ) {
		global $wpdb;
		$level_id = (int) $level_id;
		if ( $level_id <= 0 ) {
			return 'lock_error';
		}
		if ( array_key_exists( 'tutorpress_pmpro_lds_unlock', $GLOBALS ) ) {
			$v = $GLOBALS['tutorpress_pmpro_lds_unlock'];
		} else {
			$v = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::advisory_lock_name( $level_id ) ) );
		}
		return self::interpret_lock_cell( $v, 'lock_release_failed', 'released' );
	}

	/**
	 * Whether the shutdown guard is armed.
	 *
	 * @since 1.0.8
	 * @var bool
	 */
	private static $g_on = false;

	/**
	 * Level ID captured for shutdown rollback.
	 *
	 * @since 1.0.8
	 * @var int
	 */
	private static $g_lv = 0;

	/**
	 * Post IDs captured for shutdown cache invalidation.
	 *
	 * @since 1.0.8
	 * @var int[]
	 */
	private static $g_ps = array();

	/**
	 * Object IDs whose removal maps are locked for this delete.
	 *
	 * Empty when no removal-state row names the level. Released in reverse
	 * order. Advisory locks survive ROLLBACK, so every exit releases them.
	 *
	 * @since 1.0.9
	 * @var int[]
	 */
	private static $g_os = array();

	/**
	 * Whether the addon's pmpro_delete_membership_level listener is suppressed.
	 *
	 * @since 1.0.8
	 * @var bool
	 */
	private static $ls = false;

	/**
	 * Whether the addon's deletion listener should skip its own cleanup.
	 *
	 * @since 1.0.8
	 *
	 * @return bool True while a coordinated full delete is in flight.
	 */
	public static function deletion_listener_suppressed() {
		return self::$ls;
	}

	/**
	 * Re-enable the addon's deletion listener after a coordinated attempt.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public static function restore_deletion_listener() {
		self::$ls = false;
	}

	/**
	 * Begin, commit, roll back, or manage the deletion savepoint.
	 *
	 * begin refuses an outer or unknown session transaction. Savepoint
	 * operations require an open transaction. Test seam: tutorpress_pmpro_lds_txn.
	 *
	 * @since 1.0.8
	 *
	 * @param string $op begin, commit, rollback, savepoint, or release_sp.
	 * @return string ok or a transaction failure code.
	 */
	public static function control_transaction( $op ) {
		global $wpdb;
		$sql = array(
			'begin'      => 'START TRANSACTION',
			'commit'     => 'COMMIT',
			'rollback'   => 'ROLLBACK',
			'savepoint'  => 'SAVEPOINT tp_pmpro_delete_sentinel',
			'release_sp' => 'RELEASE SAVEPOINT tp_pmpro_delete_sentinel',
		);
		if ( ! isset( $sql[ $op ] ) ) {
			return 'begin_failed';
		}
		$in = self::session_in_transaction();
		if ( 'begin' === $op ) {
			if ( true === $in ) {
				return 'outer_transaction_active';
			}
			if ( null === $in ) {
				return 'transaction_state_unknown';
			}
		} elseif ( ( 'savepoint' === $op || 'release_sp' === $op ) && true !== $in ) {
			return 'hook_transaction_invalidated';
		}
		$q = array_key_exists( 'tutorpress_pmpro_lds_txn', $GLOBALS ) ? $GLOBALS['tutorpress_pmpro_lds_txn'] : $wpdb->query( $sql[ $op ] );
		if ( 'begin' === $op ) {
			return ( false === $q || true !== self::session_in_transaction() ) ? 'begin_failed' : 'ok';
		}
		if ( 'commit' === $op ) {
			$in = self::session_in_transaction();
			if ( false !== $q && false === $in ) {
				return 'ok';
			}
			if ( false === $q && true === $in ) {
				$wpdb->query( 'ROLLBACK' );
				return 'commit_failed';
			}
			return 'transaction_outcome_unknown';
		}
		if ( 'rollback' === $op ) {
			return false === $q ? 'rollback_failed' : 'ok';
		}
		return false === $q ? 'hook_transaction_invalidated' : 'ok';
	}

	/**
	 * Drop level-meta and post_meta cache entries after a deletion attempt.
	 *
	 * Always returns ok. A false wp_cache_delete result is ignored. Test seam:
	 * tutorpress_pmpro_lds_cache_false.
	 *
	 * @since 1.0.8
	 *
	 * @param int   $level_id PMPro membership level ID.
	 * @param int[] $post_ids Object IDs whose post_meta cache should be dropped.
	 * @return string ok.
	 */
	public static function invalidate_deletion_caches( $level_id, $post_ids ) {
		$hit = function( $id, $g ) {
			return array_key_exists( 'tutorpress_pmpro_lds_cache_false', $GLOBALS ) ? false : wp_cache_delete( $id, $g );
		};
		$hit( (int) $level_id, 'pmpro_membership_level_meta' );
		foreach ( (array) $post_ids as $p ) {
			if ( (int) $p > 0 ) {
				$hit( (int) $p, 'post_meta' );
			}
		}
		if ( function_exists( 'pmpro_getAllLevels' ) ) {
			pmpro_getAllLevels( true, true, true );
		}
		return 'ok';
	}

	/**
	 * Arm a once-registered shutdown handler to roll back a stranded transaction.
	 *
	 * @since 1.0.8
	 *
	 * @param int   $level_id Level ID to unlock on shutdown.
	 * @param int[] $post_ids Object IDs to invalidate on shutdown.
	 * @return void
	 */
	public static function arm_shutdown_guard( $level_id, $post_ids ) {
		self::$g_on = true;
		self::$g_lv = (int) $level_id;
		self::$g_ps = (array) $post_ids;
		static $r = false;
		if ( ! $r ) {
			register_shutdown_function( array( __CLASS__, 'run_shutdown_guard' ) );
			$r = true;
		}
	}

	/**
	 * Disarm the shutdown guard after a normal return path.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public static function disarm_shutdown_guard() {
		self::$g_on = false;
	}

	/**
	 * Rollback, invalidate caches, and release the level lock if still armed.
	 *
	 * @since 1.0.8
	 *
	 * @return void
	 */
	public static function run_shutdown_guard() {
		if ( ! self::$g_on ) {
			self::release_state_locks();
			return;
		}
		self::$g_on = false;
		if ( true === self::session_in_transaction() ) {
			self::control_transaction( 'rollback' );
		}
		self::invalidate_deletion_caches( self::$g_lv, self::$g_ps );
		self::release_state_locks();
		self::release_advisory_lock( self::$g_lv );
	}

	/**
	 * Invalidate removal-state storage and release object locks in reverse order.
	 *
	 * Callers release the level lock afterward. A transaction rollback does
	 * not release these advisory locks.
	 *
	 * @since 1.0.9
	 *
	 * @return string released, or the first object-lock release failure.
	 */
	private static function release_state_locks() {
		foreach ( self::$g_os as $oid ) {
			PMPro_Level_Removal_State::invalidate_object_storage( $oid );
		}
		$code = 'released';
		foreach ( array_reverse( self::$g_os ) as $oid ) {
			$released = PMPro_Level_Removal_State::release_object_lock( $oid );
			if ( 'released' !== $released && 'released' === $code ) {
				$code = $released;
			}
		}
		self::$g_os = array();
		return $code;
	}

	/**
	 * Lock every object whose removal map names this level.
	 *
	 * Call only while the level advisory lock is held and before START
	 * TRANSACTION. An empty match list takes no object locks. A lock
	 * failure releases object locks already acquired.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return string ok, invalid_state, state_read_error, or a lock code.
	 */
	private static function lock_state_objects( $level_id ) {
		$found = PMPro_Level_Removal_State::get_level_matches( (int) $level_id );
		if ( 'ok' !== ( $found['result'] ?? '' ) ) {
			return (string) ( $found['result'] ?? 'state_read_error' );
		}
		$ids = array_map( 'absint', array_keys( (array) ( $found['matches'] ?? array() ) ) );
		sort( $ids, SORT_NUMERIC );
		foreach ( $ids as $oid ) {
			if ( $oid <= 0 ) {
				continue;
			}
			$lock = PMPro_Level_Removal_State::acquire_object_lock( $oid );
			if ( 'acquired' !== $lock ) {
				self::release_state_locks();
				return $lock;
			}
			self::$g_os[] = $oid;
		}
		return 'ok';
	}

	/**
	 * Remove this level's pair from each locked object map.
	 *
	 * Requires the caller transaction and the object advisory locks.
	 * remove_pair() takes the row lock. invalid_state and state_read_error
	 * pass through. Any other failure, or a payload that still names the
	 * level, is cleanup_failed.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return string ok, invalid_state, state_read_error, or cleanup_failed.
	 */
	private static function remove_state_pairs( $level_id ) {
		foreach ( self::$g_os as $oid ) {
			$removed = PMPro_Level_Removal_State::remove_pair( $oid, (int) $level_id );
			if ( 'ok' !== ( $removed['result'] ?? '' ) ) {
				$result = (string) ( $removed['result'] ?? 'cleanup_failed' );
				return ( 'invalid_state' === $result || 'state_read_error' === $result ) ? $result : 'cleanup_failed';
			}
			if ( isset( $removed['payload']['levels'][ (int) $level_id ] ) ) {
				return 'cleanup_failed';
			}
		}
		return 'ok';
	}

	/**
	 * Decide whether a delete may proceed, must unlink, or must stop.
	 *
	 * Missing relationship helpers are nontransactional. A missing levels
	 * table or query error is infrastructure. When $own is true, an allowed
	 * result skips the outer-transaction check (in-lock recheck).
	 *
	 * @since 1.0.8
	 *
	 * @param int    $level_id    PMPro membership level ID.
	 * @param int    $object_id   Requesting course or bundle ID.
	 * @param string $object_type courses or course-bundle.
	 * @param bool   $own         True to skip the outer-transaction probe.
	 * @return string Guard code, missing, infrastructure, nontransactional, outer_transaction_active, or transaction_state_unknown.
	 */
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
		if ( 'allowed' !== $result ) {
			return $result;
		}
		$probe = self::probe_write_tables( self::participating_write_tables( 'full' ) );
		if ( 'ok' !== $probe ) {
			return 'error' === $probe ? 'infrastructure' : 'nontransactional';
		}
		if ( $own ) {
			return $result;
		}
		$in = self::session_in_transaction();
		return true === $in ? 'outer_transaction_active' : ( null === $in ? 'transaction_state_unknown' : $result );
	}

	/**
	 * Delete a sole-owned level or unlink a shared level from one object.
	 *
	 * First preflight returns immediately for any outcome other than allowed
	 * or shared_unlink. After the level lock, removal-state objects are
	 * locked in ascending ID order, then START TRANSACTION. A second
	 * in-lock preflight decides the branch.
	 *
	 * Shared unlink rolls back this transaction, releases object locks, then
	 * delegates requester association removal. Sole allowed deletion fires
	 * pmpro_delete_membership_level under a savepoint, writes remaining rows,
	 * removes pair entries, and commits.
	 *
	 * @since 1.0.8
	 *
	 * @param int    $level_id    PMPro membership level ID.
	 * @param int    $object_id   Requesting course or bundle ID.
	 * @param string $object_type courses or course-bundle.
	 * @return string Outcome code (ok, committed_with_warning, or a failure family).
	 */
	public static function delete_level( $level_id, $object_id, $object_type ) {
		global $wpdb;
		$level_id  = (int) $level_id;
		$object_id = (int) $object_id;
		$pre       = self::preflight( $level_id, $object_id, $object_type );
		if ( 'allowed' !== $pre && 'shared_unlink' !== $pre ) {
			return $pre;
		}
		$lock = self::acquire_advisory_lock( $level_id );
		if ( 'acquired' !== $lock ) {
			return $lock;
		}
		$state = self::lock_state_objects( $level_id );
		if ( 'ok' !== $state ) {
			return 'released' !== self::release_advisory_lock( $level_id ) ? 'lock_release_failed' : $state;
		}
		$begin = self::control_transaction( 'begin' );
		if ( 'ok' !== $begin ) {
			$objects = self::release_state_locks();
			$level   = self::release_advisory_lock( $level_id );
			return ( 'released' !== $objects || 'released' !== $level ) ? 'lock_release_failed' : $begin;
		}
		$pre   = self::preflight( $level_id, $object_id, $object_type, true );
		$posts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type IN ( %s, %s )",
				'_tutorpress_pmpro_levels',
				'courses',
				'course-bundle'
			)
		);
		$posts = array_values(
			array_unique(
				array_filter(
					array_map(
						'intval',
						is_array( $posts ) ? array_merge( array( $object_id ), $posts ) : array( $object_id )
					)
				)
			)
		);
		$gid = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$object_id,
				'_tutorpress_pmpro_group_id'
			)
		);
		self::arm_shutdown_guard( $level_id, $posts );
		if ( 'shared_unlink' === $pre ) {
			if ( true === self::session_in_transaction() ) {
				self::control_transaction( 'rollback' );
			}
			self::disarm_shutdown_guard();
			self::release_state_locks();
			$u = PMPro_Level_Cleanup::unlink_shared_level( $object_id, $level_id );
			self::invalidate_deletion_caches( $level_id, $posts );
			self::release_advisory_lock( $level_id );
			return $u;
		}
		if ( 'allowed' !== $pre || 'ok' !== self::control_transaction( 'savepoint' ) ) {
			return self::fail_delete( $level_id, $posts, 'allowed' !== $pre ? $pre : 'hook_transaction_invalidated' );
		}
		self::$ls = true;
		try {
			do_action( 'pmpro_delete_membership_level', $level_id );
		} catch ( \Throwable $t ) {
			return self::fail_delete( $level_id, $posts, 'cleanup_failed' );
		}
		$pre = self::preflight( $level_id, $object_id, $object_type, true );
		$g2  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1",
				$object_id,
				'_tutorpress_pmpro_group_id'
			)
		);
		if ( 'ok' !== self::control_transaction( 'release_sp' ) ) {
			return self::fail_delete( $level_id, $posts, 'hook_transaction_invalidated' );
		}
		if (
			'allowed' !== $pre
			|| $g2 !== $gid
			|| true !== PMPro_Level_Cleanup::prune_verified_target_duplicates( $level_id )
			|| true !== PMPro_Level_Cleanup::delete_level_relationships( $level_id )
			|| true !== PMPro_Level_Cleanup::delete_owned_empty_group( $object_id )
		) {
			return self::fail_delete( $level_id, $posts, 'missing' === $pre ? 'conflict' : ( 'allowed' !== $pre ? $pre : 'cleanup_failed' ) );
		}
		if ( true !== PMPro_Level_Cleanup::delete_level_row( $level_id ) ) {
			return self::fail_delete(
				$level_id,
				$posts,
				$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $level_id ) ) ? 'cleanup_failed' : 'conflict'
			);
		}
		$pairs = self::remove_state_pairs( $level_id );
		if ( 'ok' !== $pairs ) {
			return self::fail_delete( $level_id, $posts, $pairs );
		}
		$cm = self::control_transaction( 'commit' );
		if ( 'commit_failed' === $cm ) {
			return self::fail_delete( $level_id, $posts, $cm );
		}
		if ( 'ok' !== $cm ) {
			$in = self::session_in_transaction();
			$ex = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $level_id ) );
			if ( true === $in || null === $in || false === $ex || '' !== $wpdb->last_error ) {
				return self::fail_delete( $level_id, $posts, 'transaction_outcome_unknown' );
			}
			if ( $ex ) {
				return self::fail_delete( $level_id, $posts, 'commit_failed' );
			}
			$cm = 'committed_with_warning';
		}
		self::disarm_shutdown_guard();
		self::restore_deletion_listener();
		self::invalidate_deletion_caches( $level_id, $posts );
		$objects = self::release_state_locks();
		if ( 'released' !== $objects || 'released' !== self::release_advisory_lock( $level_id ) ) {
			return 'committed_with_warning';
		}
		try {
			do_action(
				'tutorpress_pmpro_membership_level_deleted',
				$level_id,
				$object_id,
				'committed_with_warning' === $cm ? array( 'warning' => true ) : null
			);
		} catch ( \Throwable $t ) {
			error_log( '[TP-PMPRO] ' . $t->getMessage() );
		}
		return 'ok' === $cm ? 'ok' : 'committed_with_warning';
	}

	/**
	 * Roll back, restore listeners, invalidate caches, and release the lock.
	 *
	 * A failed lock release replaces $code with lock_release_failed.
	 *
	 * @since 1.0.8
	 *
	 * @param int    $level_id Level ID to unlock.
	 * @param int[]  $posts    Object IDs to invalidate.
	 * @param string $code    Failure code to return when the lock releases.
	 * @return string $code or lock_release_failed.
	 */
	private static function fail_delete( $level_id, $posts, $code ) {
		if ( true === self::session_in_transaction() ) {
			self::control_transaction( 'rollback' );
		}
		self::disarm_shutdown_guard();
		self::restore_deletion_listener();
		self::invalidate_deletion_caches( $level_id, $posts );
		$objects = self::release_state_locks();
		return ( 'released' !== $objects || 'released' !== self::release_advisory_lock( $level_id ) ) ? 'lock_release_failed' : $code;
	}
}

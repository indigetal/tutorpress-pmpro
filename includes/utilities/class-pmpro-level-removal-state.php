<?php
/**
 * PMPro Level Removal State
 *
 * Canonical object-scoped map of instructor removal intent for a course or
 * bundle. Stored as one postmeta row per object under META_KEY.
 *
 * Pair states:
 * - retired: sole protected level kept for existing access, hidden from sale
 * - unlinked: requester association removed; the level remains for others
 *
 * This class owns reads, kind classification, and object advisory locks. Callers
 * own transactions, lock order (level first, then ascending object IDs), and
 * cache cleanup after commit, rollback, or an uncertain outcome.
 *
 * Result codes used by object/level readers:
 * - ok: readable state (payload may be null for legacy no-state)
 * - invalid_state: duplicate rows or a noncanonical payload
 * - state_read_error: a custom $wpdb query reported failure
 * - unavailable: live kind could not be classified from a level row
 *
 * Empty metadata from get_post_meta() is treated as legacy no-state because
 * that API cannot distinguish absence from a cache-loading failure.
 *
 * @package TutorPress_PMPro
 * @subpackage Utilities
 * @since 1.0.9
 */

namespace TUTORPRESS_PMPRO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PMPro_Level_Removal_State
 *
 * Read model and object-lock primitive for `_tutorpress_pmpro_level_removal_state`.
 */
class PMPro_Level_Removal_State {

	/**
	 * Postmeta key for the canonical removal-state map.
	 *
	 * @since 1.0.9
	 * @var string
	 */
	const META_KEY = '_tutorpress_pmpro_level_removal_state';

	/**
	 * Canonical schema version. Any other integer is invalid_state.
	 *
	 * @since 1.0.9
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * Pair state: level remains, but is not offered for sale on this object.
	 *
	 * @since 1.0.9
	 * @var string
	 */
	const STATE_RETIRED = 'retired';

	/**
	 * Pair state: this object's association was removed; the level may remain.
	 *
	 * @since 1.0.9
	 * @var string
	 */
	const STATE_UNLINKED = 'unlinked';

	/**
	 * Plan kind: billing_amount <= 0 and cycle_number === 0.
	 *
	 * @since 1.0.9
	 * @var string
	 */
	const KIND_ONE_TIME = 'one_time';

	/**
	 * Plan kind: any live row that is not one-time.
	 *
	 * @since 1.0.9
	 * @var string
	 */
	const KIND_RECURRING = 'recurring';

	/**
	 * Request-scoped parsed object-state memo, keyed by object ID.
	 *
	 * Invalidated by invalidate_object(). Mutation callers must also delete
	 * the WordPress post_meta cache for the object, or a primed cache can
	 * survive a raw database delete.
	 *
	 * @since 1.0.9
	 * @var array<int, array<string, mixed>>
	 */
	private static $object_state_memo = array();

	/**
	 * Drop the parsed request memo for one object.
	 *
	 * Does not touch the WordPress post_meta cache. Callers that mutated
	 * rows must also wp_cache_delete( $object_id, 'post_meta' ).
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return void
	 */
	public static function invalidate_object( $object_id ) {
		$object_id = absint( $object_id );
		if ( $object_id > 0 ) {
			unset( self::$object_state_memo[ $object_id ] );
		}
	}

	/**
	 * Drop the request memo and WordPress post_meta cache for one object.
	 *
	 * Callers use this after commit, rollback, or an uncertain outcome.
	 * Mutation helpers must not call it. invalidate_object() remains
	 * memo-only so a primed post_meta cache can still be observed.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return void
	 */
	public static function invalidate_object_storage( $object_id ) {
		$object_id = (int) $object_id;
		self::invalidate_object( $object_id );
		if ( $object_id > 0 ) {
			wp_cache_delete( $object_id, 'post_meta' );
		}
	}

	/**
	 * Read the canonical removal-state map for one object.
	 *
	 * Uses get_post_meta( ..., false ) so duplicate rows remain visible and
	 * WordPress metadata caching is preserved. An empty result is legacy
	 * no-state (payload null). Duplicate or noncanonical rows are invalid_state.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return array{result: string, payload?: array|null} Object-state result.
	 */
	public static function get_object_state( $object_id ) {
		$object_id = absint( $object_id );
		if ( $object_id <= 0 ) {
			return array( 'result' => 'invalid_state' );
		}
		if ( array_key_exists( $object_id, self::$object_state_memo ) ) {
			return self::$object_state_memo[ $object_id ];
		}
		$rows = get_post_meta( $object_id, self::META_KEY, false );
		if ( ! is_array( $rows ) || array() === $rows ) {
			return self::$object_state_memo[ $object_id ] = array( 'result' => 'ok', 'payload' => null );
		}
		if ( 1 !== count( $rows ) ) {
			return self::$object_state_memo[ $object_id ] = array( 'result' => 'invalid_state' );
		}
		$canonical = self::canonicalize_payload( $rows[0] );
		if ( null === $canonical ) {
			return self::$object_state_memo[ $object_id ] = array( 'result' => 'invalid_state' );
		}
		return self::$object_state_memo[ $object_id ] = array( 'result' => 'ok', 'payload' => $canonical );
	}

	/**
	 * Return one object/level pair, or null when the object has no such pair.
	 *
	 * No-state objects return pair null with result ok. invalid_state and
	 * other read failures are passed through unchanged.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @param int $level_id  PMPro membership level ID.
	 * @return array{result: string, pair?: array{state: string, kind: string}|null} Pair result.
	 */
	public static function get_pair( $object_id, $level_id ) {
		$level_id = absint( $level_id );
		$read     = self::get_object_state( $object_id );
		if ( 'ok' !== $read['result'] ) {
			return $read;
		}
		$pair = ( $level_id > 0 && is_array( $read['payload'] ) && isset( $read['payload']['levels'][ $level_id ] ) )
			? $read['payload']['levels'][ $level_id ]
			: null;
		return array( 'result' => 'ok', 'pair' => $pair );
	}

	/**
	 * Return marked level IDs and pair kinds for one object.
	 *
	 * No-state objects return empty arrays. Kinds are the distinct pair kinds
	 * present on the map, not the historical blocked_kinds tombstone.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return array{result: string, level_ids?: int[], kinds?: string[]} Marked-pair result.
	 */
	public static function get_marked( $object_id ) {
		$read = self::get_object_state( $object_id );
		if ( 'ok' !== $read['result'] ) {
			return $read;
		}
		$ids   = array();
		$kinds = array();
		if ( is_array( $read['payload'] ) ) {
			foreach ( $read['payload']['levels'] as $id => $pair ) {
				$ids[] = (int) $id;
				if ( ! in_array( $pair['kind'], $kinds, true ) ) {
					$kinds[] = $pair['kind'];
				}
			}
		}
		return array( 'result' => 'ok', 'level_ids' => $ids, 'kinds' => $kinds );
	}

	/**
	 * Return blocked_kinds for one object.
	 *
	 * No-state objects return an empty array. This tombstone can outlive pair
	 * entries after a coordinated full level deletion.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return array{result: string, blocked_kinds?: string[]} Blocked-kinds result.
	 */
	public static function get_blocked_kinds( $object_id ) {
		$read = self::get_object_state( $object_id );
		if ( 'ok' !== $read['result'] ) {
			return $read;
		}
		return array(
			'result'        => 'ok',
			'blocked_kinds' => is_array( $read['payload'] ) ? $read['payload']['blocked_kinds'] : array(),
		);
	}

	/**
	 * Return whether this object has a committed unlinked restriction.
	 *
	 * No-state objects return false. Once true, this flag is not cleared
	 * automatically; it prevents falling through to unrestricted access.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return array{result: string, removed_restriction?: bool} Restriction flag result.
	 */
	public static function get_removed_restriction( $object_id ) {
		$read = self::get_object_state( $object_id );
		if ( 'ok' !== $read['result'] ) {
			return $read;
		}
		return array(
			'result'              => 'ok',
			'removed_restriction' => is_array( $read['payload'] ) ? $read['payload']['removed_restriction'] : false,
		);
	}

	/**
	 * Classify a live PMPro level row as one_time or recurring.
	 *
	 * Uses (int) so non-positive IDs stay non-positive and return unavailable
	 * without querying. A missing or incomplete row is unavailable. A query
	 * error is state_read_error.
	 *
	 * One-time only when (float) billing_amount <= 0 and (int) cycle_number === 0;
	 * otherwise recurring. Callers must use this kind before writing pair state.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return array{result: string, kind?: string} Kind classification result.
	 */
	public static function classify_live_kind( $level_id ) {
		global $wpdb;
		$level_id = (int) $level_id;
		if ( $level_id <= 0 ) {
			return array( 'result' => 'unavailable' );
		}
		if ( empty( $wpdb->pmpro_membership_levels ) || ! is_string( $wpdb->pmpro_membership_levels ) ) {
			return array( 'result' => 'state_read_error' );
		}
		$wpdb->last_error = '';
		$row              = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, billing_amount, cycle_number FROM {$wpdb->pmpro_membership_levels} WHERE id = %d",
				$level_id
			),
			ARRAY_A
		);
		if ( false === $row || '' !== (string) $wpdb->last_error ) {
			return array( 'result' => 'state_read_error' );
		}
		if ( ! is_array( $row ) || empty( $row['id'] ) || ! array_key_exists( 'billing_amount', $row ) || ! array_key_exists( 'cycle_number', $row ) ) {
			return array( 'result' => 'unavailable' );
		}
		$kind = ( (float) $row['billing_amount'] <= 0 && 0 === (int) $row['cycle_number'] )
			? self::KIND_ONE_TIME
			: self::KIND_RECURRING;
		return array( 'result' => 'ok', 'kind' => $kind );
	}

	/**
	 * Return a canonical payload or null when the value is not schema-valid.
	 *
	 * Requires exactly the four keys version, levels, blocked_kinds, and
	 * removed_restriction. Pair kinds must be listed in blocked_kinds. An
	 * unlinked pair requires removed_restriction true. Extra keys, duplicate
	 * blocked kinds, or non-list blocked_kinds fail closed.
	 *
	 * @since 1.0.9
	 *
	 * @param mixed $payload Raw meta value, possibly serialized.
	 * @return array|null Canonical map, or null if invalid.
	 */
	private static function canonicalize_payload( $payload ) {
		$payload = maybe_unserialize( $payload );
		$states  = array( self::STATE_RETIRED, self::STATE_UNLINKED );
		$kinds   = array( self::KIND_ONE_TIME, self::KIND_RECURRING );
		if (
			! is_array( $payload )
			|| 4 !== count( $payload )
			|| ! array_key_exists( 'version', $payload )
			|| ! array_key_exists( 'levels', $payload )
			|| ! array_key_exists( 'blocked_kinds', $payload )
			|| ! array_key_exists( 'removed_restriction', $payload )
			|| ! is_int( $payload['version'] )
			|| self::SCHEMA_VERSION !== $payload['version']
			|| ! is_array( $payload['levels'] )
			|| ! is_array( $payload['blocked_kinds'] )
			|| ! is_bool( $payload['removed_restriction'] )
			|| array_values( $payload['blocked_kinds'] ) !== $payload['blocked_kinds']
		) {
			return null;
		}
		$blocked = array();
		foreach ( $payload['blocked_kinds'] as $kind ) {
			if ( ! in_array( $kind, $kinds, true ) || isset( $blocked[ $kind ] ) ) {
				return null;
			}
			$blocked[ $kind ] = true;
		}
		$has_unlinked = false;
		foreach ( $payload['levels'] as $level_id => $pair ) {
			if (
				! is_int( $level_id )
				|| $level_id <= 0
				|| ! is_array( $pair )
				|| 2 !== count( $pair )
				|| ! isset( $pair['state'], $pair['kind'] )
				|| ! in_array( $pair['state'], $states, true )
				|| ! in_array( $pair['kind'], $kinds, true )
				|| ! isset( $blocked[ $pair['kind'] ] )
			) {
				return null;
			}
			if ( self::STATE_UNLINKED === $pair['state'] ) {
				$has_unlinked = true;
			}
		}
		return ( $has_unlinked && true !== $payload['removed_restriction'] ) ? null : $payload;
	}

	/**
	 * Find object/level pairs that apply to a requested PMPro level ID.
	 *
	 * Checkout carries only the level ID. Invalid or unreadable state blocks
	 * this level only when the row belongs to a relationship candidate or the
	 * payload exposes this exact level key. Unrelated malformed rows are ignored.
	 *
	 * Custom SQL failures return state_read_error. Duplicate or noncanonical
	 * rows on a related object return invalid_state. A candidate with no state
	 * is a non-match.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return array{result: string, matches?: array<int, array{state: string, kind: string}>} Level-scoped matches.
	 */
	public static function get_level_matches( $level_id ) {
		global $wpdb;
		$level_id = absint( $level_id );
		if ( $level_id <= 0 ) {
			return array( 'result' => 'invalid_state' );
		}
		$candidates = self::level_candidate_object_ids( $level_id );
		if ( 'ok' !== $candidates['result'] ) {
			return $candidates;
		}
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				self::META_KEY
			),
			ARRAY_A
		);
		if ( false === $rows || ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			return array( 'result' => 'state_read_error' );
		}
		$by_object = array();
		foreach ( $rows as $row ) {
			$oid = absint( $row['post_id'] );
			if ( $oid > 0 ) {
				$by_object[ $oid ][] = $row['meta_value'];
			}
		}
		$matches = array();
		foreach ( $by_object as $oid => $values ) {
			$related   = isset( $candidates['ids'][ $oid ] ) || self::payload_exposes_level( $values, $level_id );
			$canonical = ( 1 === count( $values ) ) ? self::canonicalize_payload( $values[0] ) : null;
			if ( 1 !== count( $values ) || null === $canonical ) {
				if ( $related ) {
					return array( 'result' => 'invalid_state' );
				}
				continue;
			}
			if ( isset( $canonical['levels'][ $level_id ] ) ) {
				$matches[ $oid ] = $canonical['levels'][ $level_id ];
			}
		}
		return array( 'result' => 'ok', 'matches' => $matches );
	}

	/**
	 * Collect course/bundle object IDs associated with a level.
	 *
	 * Candidates come from page mappings, reverse course/bundle owner meta,
	 * and group mappings. Each lookup checks $wpdb->last_error independently.
	 * Non-course/bundle posts are dropped. Missing table properties are
	 * state_read_error.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id PMPro membership level ID.
	 * @return array{result: string, ids?: array<int, true>} Candidate set keyed by object ID.
	 */
	private static function level_candidate_object_ids( $level_id ) {
		global $wpdb;
		if (
			empty( $wpdb->postmeta )
			|| ! is_string( $wpdb->postmeta )
			|| empty( $wpdb->pmpro_memberships_pages )
			|| empty( $wpdb->pmpro_membership_levelmeta )
			|| empty( $wpdb->pmpro_membership_levels_groups )
		) {
			return array( 'result' => 'state_read_error' );
		}
		$types            = array( 'courses', 'course-bundle' );
		$wpdb->last_error = '';
		$pages            = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT page_id FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d",
				$level_id
			)
		);
		if ( false === $pages || ! is_array( $pages ) || '' !== $wpdb->last_error ) {
			return array( 'result' => 'state_read_error' );
		}
		$wpdb->last_error = '';
		$rev              = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d AND meta_key IN (%s,%s)",
				$level_id,
				'tutorpress_course_id',
				'tutorpress_bundle_id'
			)
		);
		if ( false === $rev || ! is_array( $rev ) || '' !== $wpdb->last_error ) {
			return array( 'result' => 'state_read_error' );
		}
		$wpdb->last_error = '';
		$gids             = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT `group` FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d",
				$level_id
			)
		);
		if ( false === $gids || ! is_array( $gids ) || '' !== $wpdb->last_error ) {
			return array( 'result' => 'state_read_error' );
		}
		$wpdb->last_error = '';
		$grows            = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_tutorpress_pmpro_group_id'
			),
			ARRAY_A
		);
		if ( false === $grows || ! is_array( $grows ) || '' !== $wpdb->last_error ) {
			return array( 'result' => 'state_read_error' );
		}
		$gids = array_values( array_unique( array_map( 'absint', $gids ) ) );
		$ids  = array();
		foreach ( array_merge( $pages, $rev ) as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$ids[ $id ] = true;
			}
		}
		foreach ( $grows as $row ) {
			$oid = absint( $row['post_id'] );
			$gid = absint( $row['meta_value'] );
			if ( $oid > 0 && $gid > 0 && in_array( $gid, $gids, true ) ) {
				$ids[ $oid ] = true;
			}
		}
		foreach ( $ids as $id => $_ ) {
			if ( ! in_array( get_post_type( $id ), $types, true ) ) {
				unset( $ids[ $id ] );
			}
		}
		return array( 'result' => 'ok', 'ids' => $ids );
	}

	/**
	 * Whether any raw meta value exposes the requested level key.
	 *
	 * Used so a malformed-but-decodable row that names this level still
	 * fail-closes checkout, even when the object is not a relationship candidate.
	 *
	 * @since 1.0.9
	 *
	 * @param array $values   Raw meta_value list for one object.
	 * @param int   $level_id PMPro membership level ID.
	 * @return bool True when a decodable levels map contains this level ID.
	 */
	private static function payload_exposes_level( $values, $level_id ) {
		foreach ( (array) $values as $raw ) {
			$payload = maybe_unserialize( $raw );
			if (
				is_array( $payload )
				&& isset( $payload['levels'] )
				&& is_array( $payload['levels'] )
				&& array_key_exists( $level_id, $payload['levels'] )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deterministic MySQL advisory lock name for one object's state map.
	 *
	 * Distinct from the deletion coordinator's tp_pmpro_delete: prefix.
	 * Length is at most 64 characters for GET_LOCK.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return string Lock name tp_pmpro_state:{hash}:{object_id}.
	 */
	public static function object_lock_name( $object_id ) {
		global $wpdb;
		return 'tp_pmpro_state:' . substr( md5( (string) $wpdb->dbname . (string) $wpdb->prefix ), 0, 12 ) . ':' . (int) $object_id;
	}

	/**
	 * Map a GET_LOCK / RELEASE_LOCK cell to a result code.
	 *
	 * 1 maps to $one, 0 maps to $zero. A NULL cell with last_error mentioning
	 * an unknown/missing function is lock_unsupported; any other failure is
	 * lock_error. Copied from the deletion coordinator; do not call that class.
	 *
	 * @since 1.0.9
	 *
	 * @param mixed  $v    Raw lock function result.
	 * @param string $zero  Code when the cell is 0 (busy or unowned release).
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
	 * Acquire a nonblocking object advisory lock.
	 *
	 * Uses (int) so non-positive IDs return lock_error without querying.
	 * Test seam: tutorpress_pmpro_lds_object_lock.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return string acquired, busy, lock_unsupported, or lock_error.
	 */
	public static function acquire_object_lock( $object_id ) {
		global $wpdb;
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return 'lock_error';
		}
		if ( array_key_exists( 'tutorpress_pmpro_lds_object_lock', $GLOBALS ) ) {
			$v = $GLOBALS['tutorpress_pmpro_lds_object_lock'];
		} else {
			$v = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', self::object_lock_name( $object_id ) ) );
		}
		return self::interpret_lock_cell( $v, 'busy', 'acquired' );
	}

	/**
	 * Release an object advisory lock held by this connection.
	 *
	 * RELEASE_LOCK 0 means this connection does not own the lock
	 * (lock_release_failed). Test seam: tutorpress_pmpro_lds_object_unlock.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return string released, lock_release_failed, lock_unsupported, or lock_error.
	 */
	public static function release_object_lock( $object_id ) {
		global $wpdb;
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return 'lock_error';
		}
		if ( array_key_exists( 'tutorpress_pmpro_lds_object_unlock', $GLOBALS ) ) {
			$v = $GLOBALS['tutorpress_pmpro_lds_object_unlock'];
		} else {
			$v = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::object_lock_name( $object_id ) ) );
		}
		return self::interpret_lock_cell( $v, 'lock_release_failed', 'released' );
	}

	/**
	 * Load the object's removal-state postmeta row under FOR UPDATE.
	 *
	 * Uses (int) so non-positive IDs return invalid_state without querying.
	 * Callers must already own the object advisory lock and an open
	 * transaction. This method never starts, commits, or rolls back.
	 *
	 * Zero rows are legacy no-state (meta_id 0, payload null). One canonical
	 * row returns its meta_id and payload. Duplicate or noncanonical rows are
	 * invalid_state. A missing postmeta table or query failure is
	 * state_read_error.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @return array{result: string, meta_id?: int, payload?: array|null} Locked-row result.
	 */
	public static function lock_object_row( $object_id ) {
		global $wpdb;
		$object_id = (int) $object_id;
		if ( $object_id <= 0 ) {
			return array( 'result' => 'invalid_state' );
		}
		if ( empty( $wpdb->postmeta ) || ! is_string( $wpdb->postmeta ) ) {
			return array( 'result' => 'state_read_error' );
		}
		$wpdb->last_error = '';
		$rows             = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s FOR UPDATE",
				$object_id,
				self::META_KEY
			),
			ARRAY_A
		);
		if ( false === $rows || ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return array( 'result' => 'state_read_error' );
		}
		if ( array() === $rows ) {
			return array( 'result' => 'ok', 'meta_id' => 0, 'payload' => null );
		}
		if ( 1 !== count( $rows ) ) {
			return array( 'result' => 'invalid_state' );
		}
		$canonical = self::canonicalize_payload( $rows[0]['meta_value'] );
		if ( null === $canonical ) {
			return array( 'result' => 'invalid_state' );
		}
		return array(
			'result'  => 'ok',
			'meta_id' => (int) $rows[0]['meta_id'],
			'payload' => $canonical,
		);
	}

	/**
	 * Insert, update, or delete the locked object state row by meta_id.
	 *
	 * Callers must already own the object advisory lock and an open
	 * transaction. This method never starts, commits, or rolls back.
	 *
	 * meta_id 0 with a canonical payload inserts the sole row. A positive
	 * meta_id updates that row, or deletes it when payload is null. Duplicate
	 * or noncanonical rows, a meta_id mismatch, or an insert onto an
	 * existing row return invalid_state without writing. Database failures
	 * and post-write verify mismatches are state_read_error.
	 *
	 * @since 1.0.9
	 *
	 * @param int        $object_id Course or bundle post ID.
	 * @param int        $meta_id   0 to insert; positive to update or delete.
	 * @param array|null $payload    Canonical map to persist, or null to delete.
	 * @return array{result: string, meta_id?: int, payload?: array|null} Persist result.
	 */
	public static function persist_object_row( $object_id, $meta_id, $payload ) {
		global $wpdb;
		$object_id = (int) $object_id;
		$meta_id   = (int) $meta_id;
		if ( $object_id <= 0 ) {
			return array( 'result' => 'invalid_state' );
		}

		$canonical = null;
		if ( null !== $payload ) {
			$canonical = self::canonicalize_payload( $payload );
			if ( null === $canonical ) {
				return array( 'result' => 'invalid_state' );
			}
		} elseif ( $meta_id <= 0 ) {
			return array( 'result' => 'invalid_state' );
		}
		if ( $meta_id < 0 ) {
			return array( 'result' => 'invalid_state' );
		}

		$locked = self::lock_object_row( $object_id );
		if ( 'ok' !== $locked['result'] ) {
			return $locked;
		}

		$expect_id      = 0;
		$expect_payload = null;

		if ( null === $payload ) {
			if ( $meta_id !== (int) $locked['meta_id'] ) {
				return array( 'result' => 'invalid_state' );
			}
			$wpdb->last_error = '';
			$deleted          = $wpdb->delete(
				$wpdb->postmeta,
				array(
					'meta_id'  => $meta_id,
					'post_id'  => $object_id,
					'meta_key' => self::META_KEY,
				),
				array( '%d', '%d', '%s' )
			);
			if ( false === $deleted || '' !== (string) $wpdb->last_error || 1 !== (int) $deleted ) {
				return array( 'result' => 'state_read_error' );
			}
		} elseif ( 0 === $meta_id ) {
			if ( 0 !== (int) $locked['meta_id'] || null !== $locked['payload'] ) {
				return array( 'result' => 'invalid_state' );
			}
			$wpdb->last_error = '';
			$inserted          = $wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $object_id,
					'meta_key'   => self::META_KEY,
					'meta_value' => maybe_serialize( $canonical ),
				),
				array( '%d', '%s', '%s' )
			);
			$expect_id      = (int) $wpdb->insert_id;
			$expect_payload = $canonical;
			if ( false === $inserted || '' !== (string) $wpdb->last_error || $expect_id <= 0 ) {
				return array( 'result' => 'state_read_error' );
			}
		} else {
			if ( $meta_id !== (int) $locked['meta_id'] ) {
				return array( 'result' => 'invalid_state' );
			}
			$wpdb->last_error = '';
			$updated          = $wpdb->update(
				$wpdb->postmeta,
				array(
					'meta_value' => maybe_serialize( $canonical ),
				),
				array(
					'meta_id'  => $meta_id,
					'post_id'  => $object_id,
					'meta_key' => self::META_KEY,
				),
				array( '%s' ),
				array( '%d', '%d', '%s' )
			);
			if ( false === $updated || '' !== (string) $wpdb->last_error ) {
				return array( 'result' => 'state_read_error' );
			}
			$expect_id      = $meta_id;
			$expect_payload = $canonical;
		}

		$verified = self::lock_object_row( $object_id );
		if ( 'ok' !== $verified['result'] ) {
			return $verified;
		}
		if ( $expect_id !== (int) $verified['meta_id'] || $expect_payload !== $verified['payload'] ) {
			return array( 'result' => 'state_read_error' );
		}
		return $verified;
	}

	/**
	 * Merge one object/level pair into the locked state map and persist it.
	 *
	 * Accepts only a preclassified allowlisted kind. This method does not
	 * classify a live row. Callers must already own the object advisory lock
	 * and an open transaction. This method never starts, commits, or rolls
	 * back, and it does not invalidate caches.
	 *
	 * No-state becomes a version-1 empty map. The pair is written or
	 * replaced. The kind is appended to blocked_kinds only when missing.
	 * removed_restriction is set true only for unlinked and is never
	 * cleared. Duplicate or noncanonical rows are not overwritten.
	 *
	 * @since 1.0.9
	 *
	 * @param int    $object_id Course or bundle post ID.
	 * @param int    $level_id  PMPro membership level ID.
	 * @param string $state     retired or unlinked.
	 * @param string $kind      one_time or recurring.
	 * @return array{result: string, meta_id?: int, payload?: array|null} Persist result.
	 */
	public static function merge_pair( $object_id, $level_id, $state, $kind ) {
		$object_id = (int) $object_id;
		$level_id  = (int) $level_id;
		$states    = array( self::STATE_RETIRED, self::STATE_UNLINKED );
		$kinds     = array( self::KIND_ONE_TIME, self::KIND_RECURRING );
		if ( $object_id <= 0 || $level_id <= 0 ) {
			return array( 'result' => 'invalid_state' );
		}
		if ( ! in_array( $state, $states, true ) || ! in_array( $kind, $kinds, true ) ) {
			return array( 'result' => 'invalid_state' );
		}

		$locked = self::lock_object_row( $object_id );
		if ( 'ok' !== $locked['result'] ) {
			return $locked;
		}

		$payload = is_array( $locked['payload'] )
			? $locked['payload']
			: array(
				'version'             => self::SCHEMA_VERSION,
				'levels'              => array(),
				'blocked_kinds'       => array(),
				'removed_restriction' => false,
			);

		$payload['levels'][ $level_id ] = array(
			'state' => $state,
			'kind'  => $kind,
		);
		if ( ! in_array( $kind, $payload['blocked_kinds'], true ) ) {
			$payload['blocked_kinds'][] = $kind;
		}
		if ( self::STATE_UNLINKED === $state ) {
			$payload['removed_restriction'] = true;
		}

		return self::persist_object_row(
			$object_id,
			(int) $locked['meta_id'],
			$payload
		);
	}

	/**
	 * Remove one object/level pair from the locked state map.
	 *
	 * Callers must already own the object advisory lock and an open
	 * transaction. This method never starts, commits, or rolls back, and
	 * it does not invalidate caches.
	 *
	 * No-state and an absent level key are no-ops. blocked_kinds and
	 * removed_restriction are retained. The row is deleted only when the
	 * map has no pairs and neither historical field. Duplicate or
	 * noncanonical rows are not overwritten.
	 *
	 * @since 1.0.9
	 *
	 * @param int $object_id Course or bundle post ID.
	 * @param int $level_id  PMPro membership level ID.
	 * @return array{result: string, meta_id?: int, payload?: array|null} Persist or locked no-op result.
	 */
	public static function remove_pair( $object_id, $level_id ) {
		$object_id = (int) $object_id;
		$level_id  = (int) $level_id;
		if ( $object_id <= 0 || $level_id <= 0 ) {
			return array( 'result' => 'invalid_state' );
		}

		$locked = self::lock_object_row( $object_id );
		if ( 'ok' !== $locked['result'] ) {
			return $locked;
		}
		if ( ! is_array( $locked['payload'] ) ) {
			return $locked;
		}

		$payload = $locked['payload'];
		if ( ! isset( $payload['levels'][ $level_id ] ) ) {
			return $locked;
		}
		unset( $payload['levels'][ $level_id ] );

		$empty = array() === $payload['levels']
			&& array() === $payload['blocked_kinds']
			&& false === $payload['removed_restriction'];
		if ( $empty ) {
			return self::persist_object_row(
				$object_id,
				(int) $locked['meta_id'],
				null
			);
		}

		return self::persist_object_row(
			$object_id,
			(int) $locked['meta_id'],
			$payload
		);
	}
}

<?php
/**
 * PMPro Level Removal Coordinator
 *
 * Owns the explicit Gutenberg removal lock order: level advisory lock
 * first, then object advisory lock; reverse-order release. retire_level()
 * owns fail-fast, locks, caller transaction control, in-lock recheck,
 * commit/rollback, and cache invalidation. retire_pair_with_state()
 * records retired pair state and allow_signups = 0. REST DELETE is
 * not routed here.
 *
 * Level locks wrap PMPro_Level_Deletion_Coordinator. Object locks wrap
 * PMPro_Level_Removal_State. Transaction control inherits that
 * coordinator's request-global seams.
 *
 * Test seam (request global): tutorpress_pmpro_lds_flush_group. When
 * present, flush_access_group_after_commit() treats the flush as false
 * and returns committed_with_warning without calling wp_cache_flush_group.
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
 * Class PMPro_Level_Removal_Coordinator
 *
 * Coordinates instructor removal locks for a requesting course or bundle.
 */
class PMPro_Level_Removal_Coordinator {

	/**
	 * Acquire the level lock, then the object lock, both nonblocking.
	 *
	 * Uses (int) so a non-positive ID returns lock_error without querying
	 * and without taking the other lock. If the object lock is not
	 * acquired, the level lock is released before returning.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id  PMPro membership level ID.
	 * @param int $object_id Course or bundle post ID.
	 * @return string acquired, busy, lock_unsupported, or lock_error.
	 */
	public static function acquire_removal_locks( $level_id, $object_id ) {
		$level_id  = (int) $level_id;
		$object_id = (int) $object_id;
		if ( $level_id <= 0 || $object_id <= 0 ) {
			return 'lock_error';
		}

		$level = PMPro_Level_Deletion_Coordinator::acquire_advisory_lock( $level_id );
		if ( 'acquired' !== $level ) {
			return $level;
		}

		$object = PMPro_Level_Removal_State::acquire_object_lock( $object_id );
		if ( 'acquired' !== $object ) {
			PMPro_Level_Deletion_Coordinator::release_advisory_lock( $level_id );
			return $object;
		}

		return 'acquired';
	}

	/**
	 * Release the object lock, then the level lock.
	 *
	 * Both releases are always attempted. The first non-released code is
	 * returned; both released returns released.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id  PMPro membership level ID.
	 * @param int $object_id Course or bundle post ID.
	 * @return string released, lock_release_failed, lock_unsupported, or lock_error.
	 */
	public static function release_removal_locks( $level_id, $object_id ) {
		$level_id  = (int) $level_id;
		$object_id = (int) $object_id;

		$object = PMPro_Level_Removal_State::release_object_lock( $object_id );
		$level  = PMPro_Level_Deletion_Coordinator::release_advisory_lock( $level_id );
		if ( 'released' !== $object ) {
			return $object;
		}
		if ( 'released' !== $level ) {
			return $level;
		}

		return 'released';
	}

	/**
	 * Whether this connection already holds both removal advisory locks.
	 *
	 * Read-only: never GET_LOCK or RELEASE_LOCK. Uses (int) so a
	 * non-positive ID returns false without querying. IS_USED_LOCK must
	 * equal CONNECTION_ID() for both names.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id  PMPro membership level ID.
	 * @param int $object_id Course or bundle post ID.
	 * @return bool True when this connection holds both locks.
	 */
	public static function connection_holds_removal_locks( $level_id, $object_id ) {
		global $wpdb;

		$level_id  = (int) $level_id;
		$object_id = (int) $object_id;
		if ( $level_id <= 0 || $object_id <= 0 ) {
			return false;
		}

		$wpdb->last_error = '';
		$connection_id    = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		$level_holder     = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT IS_USED_LOCK( %s )',
				PMPro_Level_Deletion_Coordinator::advisory_lock_name( $level_id )
			)
		);
		$object_holder    = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT IS_USED_LOCK( %s )',
				PMPro_Level_Removal_State::object_lock_name( $object_id )
			)
		);

		if (
			'' !== (string) $wpdb->last_error
			|| null === $connection_id
			|| false === $connection_id
			|| '' === (string) $connection_id
			|| null === $level_holder
			|| null === $object_holder
			|| (string) $level_holder !== (string) $connection_id
			|| (string) $object_holder !== (string) $connection_id
		) {
			return false;
		}

		return true;
	}

	/**
	 * Classify whether a sole protected level may be retired.
	 *
	 * Incomplete protection returns the shipped protected failure without
	 * consulting relationship topology. Unprotected levels return the
	 * relationship code unchanged. Confirmed protected plus allowed is
	 * the internal retire signal and must not leak from retire_level().
	 *
	 * @since 1.0.9
	 *
	 * @param int    $level_id    PMPro membership level ID.
	 * @param int    $object_id   Course or bundle post ID.
	 * @param string $object_type courses or course-bundle.
	 * @return string retire, protected, allowed, shared_unlink, ownership_conflict, or ineligible.
	 */
	private static function retirement_eligibility( $level_id, $object_id, $object_type ) {
		$prot = PMPro_Level_Deletion_Guard::classify_protection( $level_id );
		if ( 'incomplete' === $prot ) {
			return 'protected';
		}
		if ( 'protected' !== $prot ) {
			return PMPro_Level_Deletion_Guard::classify_relationship( $level_id, $object_id, $object_type );
		}

		$rel = PMPro_Level_Deletion_Guard::classify_relationship( $level_id, $object_id, $object_type );
		if ( 'allowed' === $rel ) {
			return 'retire';
		}

		return $rel;
	}

	/**
	 * Roll back, invalidate caches, and release both removal locks.
	 *
	 * Call only after this connection holds the level and object locks.
	 * A rollback that is not ok still invalidates and releases. A failed
	 * lock release replaces $code with lock_release_failed.
	 *
	 * @since 1.0.9
	 *
	 * @param int    $level_id  PMPro membership level ID.
	 * @param int    $object_id Course or bundle post ID.
	 * @param string $code      Failure code to return when locks release.
	 * @return string $code or lock_release_failed.
	 */
	private static function fail_retire( $level_id, $object_id, $code ) {
		if ( true === PMPro_Level_Deletion_Coordinator::session_in_transaction() ) {
			PMPro_Level_Deletion_Coordinator::control_transaction( 'rollback' );
		}
		PMPro_Level_Removal_State::invalidate_object_storage( $object_id );
		PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) );
		$released = self::release_removal_locks( $level_id, $object_id );
		if ( 'released' !== $released ) {
			return 'lock_release_failed';
		}

		return $code;
	}

	/**
	 * Record retired pair state and disable new signups.
	 *
	 * Requires a caller-owned active transaction plus this connection's
	 * removal locks. Never starts, commits, or rolls back, and never
	 * acquires or releases locks. Never invalidates caches.
	 *
	 * Live kind comes from classify_live_kind(). Missing or unreadable
	 * billing columns fail before mutation. allow_signups is set to 0
	 * for this level only. Associations are not written.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id  PMPro membership level ID.
	 * @param int $object_id Course or bundle post ID.
	 * @return string ok, skip, unavailable, state_read_error, invalid_state, or partial_failure.
	 */
	private static function retire_pair_with_state( $level_id, $object_id ) {
		global $wpdb;

		$level_id  = (int) $level_id;
		$object_id = (int) $object_id;
		if ( $level_id <= 0 || $object_id <= 0 ) {
			return 'skip';
		}
		if ( true !== PMPro_Level_Deletion_Coordinator::session_in_transaction() ) {
			return 'skip';
		}
		if ( true !== self::connection_holds_removal_locks( $level_id, $object_id ) ) {
			return 'skip';
		}

		$kind_read = PMPro_Level_Removal_State::classify_live_kind( $level_id );
		if ( 'ok' !== $kind_read['result'] ) {
			return $kind_read['result'];
		}

		$kind   = $kind_read['kind'];
		$merged = PMPro_Level_Removal_State::merge_pair(
			$object_id,
			$level_id,
			PMPro_Level_Removal_State::STATE_RETIRED,
			$kind
		);
		if ( 'ok' !== $merged['result'] ) {
			return $merged['result'];
		}

		$wpdb->last_error = '';
		$updated          = $wpdb->update(
			$wpdb->pmpro_membership_levels,
			array( 'allow_signups' => 0 ),
			array( 'id' => $level_id ),
			array( '%d' ),
			array( '%d' )
		);
		if ( false === $updated || '' !== (string) $wpdb->last_error ) {
			return 'state_read_error';
		}

		if ( empty( $wpdb->pmpro_membership_levels ) || ! is_string( $wpdb->pmpro_membership_levels ) ) {
			return 'state_read_error';
		}

		$wpdb->last_error = '';
		$signups          = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT allow_signups FROM {$wpdb->pmpro_membership_levels} WHERE id = %d",
				$level_id
			)
		);
		if (
			false === $signups
			|| null === $signups
			|| '' !== (string) $wpdb->last_error
		) {
			return 'state_read_error';
		}

		$verified = PMPro_Level_Removal_State::lock_object_row( $object_id );
		if ( 'ok' !== $verified['result'] ) {
			return $verified['result'];
		}

		$pair = (
			is_array( $verified['payload'] )
			&& isset( $verified['payload']['levels'][ $level_id ] )
		)
			? $verified['payload']['levels'][ $level_id ]
			: null;

		if (
			! is_array( $pair )
			|| PMPro_Level_Removal_State::STATE_RETIRED !== $pair['state']
			|| $kind !== $pair['kind']
			|| '0' !== (string) $signups
		) {
			return 'partial_failure';
		}

		return 'ok';
	}

	/**
	 * Retire a sole protected level for one course or bundle.
	 *
	 * Fail-fast reads and eligibility return before locks. After locks,
	 * begin, row lock, in-lock eligibility, and the writer all fail
	 * through fail_retire(). A successful commit invalidates caches
	 * then releases object then level. REST DELETE is not routed here.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id  PMPro membership level ID.
	 * @param int $object_id Course or bundle post ID.
	 * @return string ok, committed_with_warning, or a failure family.
	 */
	public static function retire_level( $level_id, $object_id ) {
		global $wpdb;

		$level_id  = (int) $level_id;
		$object_id = (int) $object_id;
		if ( $level_id <= 0 || $object_id <= 0 ) {
			return 'ineligible';
		}

		$type = get_post_type( $object_id );
		if ( ! in_array( $type, array( 'courses', 'course-bundle' ), true ) ) {
			return 'ineligible';
		}

		$state = PMPro_Level_Removal_State::get_object_state( $object_id );
		if ( 'ok' !== $state['result'] ) {
			return $state['result'];
		}

		$gate = self::retirement_eligibility( $level_id, $object_id, $type );
		if ( 'retire' !== $gate ) {
			return $gate;
		}

		$probe = PMPro_Level_Deletion_Coordinator::probe_write_tables(
			array(
				$wpdb->pmpro_membership_levels,
				$wpdb->postmeta,
			)
		);
		if ( 'ok' !== $probe ) {
			return 'error' === $probe ? 'infrastructure' : 'nontransactional';
		}

		$in = PMPro_Level_Deletion_Coordinator::session_in_transaction();
		if ( true === $in ) {
			return 'outer_transaction_active';
		}
		if ( null === $in ) {
			return 'transaction_state_unknown';
		}

		$lock = self::acquire_removal_locks( $level_id, $object_id );
		if ( 'acquired' !== $lock ) {
			return $lock;
		}

		$begin = PMPro_Level_Deletion_Coordinator::control_transaction( 'begin' );
		if ( 'ok' !== $begin ) {
			return self::fail_retire( $level_id, $object_id, $begin );
		}

		$locked = PMPro_Level_Removal_State::lock_object_row( $object_id );
		if ( 'ok' !== $locked['result'] ) {
			return self::fail_retire( $level_id, $object_id, $locked['result'] );
		}

		$gate = self::retirement_eligibility( $level_id, $object_id, $type );
		if ( 'retire' !== $gate ) {
			return self::fail_retire( $level_id, $object_id, $gate );
		}

		$written = self::retire_pair_with_state( $level_id, $object_id );
		if ( 'ok' !== $written ) {
			return self::fail_retire( $level_id, $object_id, $written );
		}

		$commit = PMPro_Level_Deletion_Coordinator::control_transaction( 'commit' );
		if ( 'ok' !== $commit ) {
			return self::fail_retire( $level_id, $object_id, $commit );
		}

		PMPro_Level_Removal_State::invalidate_object_storage( $object_id );
		PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) );
		$released = self::release_removal_locks( $level_id, $object_id );
		if ( 'released' !== $released ) {
			return 'committed_with_warning';
		}

		return 'ok';
	}

	/**
	 * Best-effort flush of the tutorpress_pmpro cache group after commit.
	 *
	 * Calls wp_cache_flush_group only when that function and
	 * wp_cache_supports exist and flush_group is supported. Unsupported
	 * capability or a false flush result is committed_with_warning.
	 * Never calls wp_cache_flush(). Test seam:
	 * tutorpress_pmpro_lds_flush_group treats the flush as false.
	 *
	 * @since 1.0.9
	 *
	 * @return string ok or committed_with_warning.
	 */
	private static function flush_access_group_after_commit() {
		if ( array_key_exists( 'tutorpress_pmpro_lds_flush_group', $GLOBALS ) ) {
			return 'committed_with_warning';
		}
		if (
			! function_exists( 'wp_cache_flush_group' )
			|| ! function_exists( 'wp_cache_supports' )
			|| true !== wp_cache_supports( 'flush_group' )
		) {
			return 'committed_with_warning';
		}

		$flushed = wp_cache_flush_group( 'tutorpress_pmpro' );
		if ( false === $flushed ) {
			return 'committed_with_warning';
		}

		return 'ok';
	}

	/**
	 * Explicit instructor removal for one course or bundle.
	 *
	 * Reads the live level row before pair state. An empty levels table or a
	 * failed id read returns infrastructure. A null or empty id returns
	 * missing. An already-unlinked pair skips guard and topology, attempts
	 * the access-group flush, and returns ok or committed_with_warning
	 * without rewriting state or associations. Invalid state is returned
	 * without flushing. Sole unprotected allowed delegates to the shipped
	 * deletion coordinator. Shared unlink and sole protected retirement use
	 * the locked write sequence.
	 *
	 * @since 1.0.9
	 *
	 * @param int $level_id  PMPro membership level ID.
	 * @param int $object_id Course or bundle post ID.
	 * @return string ok, committed_with_warning, missing, infrastructure, skip, ineligible, or a failure family.
	 */
	public static function remove_level( $level_id, $object_id ) {
		global $wpdb;

		$level_id  = (int) $level_id;
		$object_id = (int) $object_id;
		if ( $level_id <= 0 || $object_id <= 0 ) {
			return 'ineligible';
		}
		if ( empty( $wpdb->pmpro_membership_levels ) ) {
			return 'infrastructure';
		}
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$wpdb->pmpro_membership_levels}` WHERE id = %d", $level_id ) );
		if ( false === $exists || '' !== $wpdb->last_error ) {
			return 'infrastructure';
		}
		if ( null === $exists || '' === $exists ) {
			return 'missing';
		}

		$read = PMPro_Level_Removal_State::get_pair( $object_id, $level_id );
		if ( 'ok' !== $read['result'] ) {
			return $read['result'];
		}
		if (
			is_array( $read['pair'] )
			&& PMPro_Level_Removal_State::STATE_UNLINKED === $read['pair']['state']
		) {
			return self::flush_access_group_after_commit();
		}

		$type = get_post_type( $object_id );
		if ( ! in_array( $type, array( 'courses', 'course-bundle' ), true ) ) {
			return 'ineligible';
		}

		$pre = PMPro_Level_Deletion_Guard::evaluate( $level_id, $object_id, $type );
		if ( 'allowed' === $pre ) {
			return PMPro_Level_Deletion_Coordinator::delete_level( $level_id, $object_id, $type );
		}
		if ( 'shared_unlink' === $pre ) {
			return self::commit_explicit_removal( $level_id, $object_id, $type );
		}
		if ( 'protected' !== $pre ) {
			return $pre;
		}

		$prot = PMPro_Level_Deletion_Guard::classify_protection( $level_id );
		if ( 'incomplete' === $prot || 'protected' !== $prot ) {
			return 'protected';
		}

		$rel = PMPro_Level_Deletion_Guard::classify_relationship( $level_id, $object_id, $type );
		if ( 'allowed' === $rel || 'shared_unlink' === $rel ) {
			return self::commit_explicit_removal( $level_id, $object_id, $type );
		}

		return $rel;
	}

	/**
	 * Locked recheck for explicit shared unlink or sole retirement.
	 *
	 * Owns probe, locks, begin, and in-lock state/protection/topology.
	 * Already-unlinked rolls back, releases, then flushes. Unprotected
	 * sole allowed releases then delegates to delete_level(). Shared
	 * unlink writes unlinked then flushes; confirmed-protected sole
	 * retires without an access-group flush. REST DELETE is not routed
	 * here.
	 *
	 * @since 1.0.9
	 *
	 * @param int    $level_id    PMPro membership level ID.
	 * @param int    $object_id   Course or bundle post ID.
	 * @param string $object_type courses or course-bundle.
	 * @return string ok, committed_with_warning, skip, or a failure family.
	 */
	private static function commit_explicit_removal( $level_id, $object_id, $object_type ) {
		global $wpdb;

		$probe = PMPro_Level_Deletion_Coordinator::probe_write_tables(
			array_values(
				array_unique(
					array_merge(
						PMPro_Level_Deletion_Coordinator::participating_write_tables( 'shared' ),
						array( $wpdb->pmpro_membership_levels )
					)
				)
			)
		);
		if ( 'ok' !== $probe ) {
			return 'error' === $probe ? 'infrastructure' : 'nontransactional';
		}

		$in = PMPro_Level_Deletion_Coordinator::session_in_transaction();
		if ( true === $in ) {
			return 'outer_transaction_active';
		}
		if ( null === $in ) {
			return 'transaction_state_unknown';
		}

		$lock = self::acquire_removal_locks( $level_id, $object_id );
		if ( 'acquired' !== $lock ) {
			return $lock;
		}

		$begin = PMPro_Level_Deletion_Coordinator::control_transaction( 'begin' );
		if ( 'ok' !== $begin ) {
			return self::fail_retire( $level_id, $object_id, $begin );
		}

		$locked = PMPro_Level_Removal_State::lock_object_row( $object_id );
		if ( 'ok' !== $locked['result'] ) {
			return self::fail_retire( $level_id, $object_id, $locked['result'] );
		}

		$pair = (
			is_array( $locked['payload'] )
			&& isset( $locked['payload']['levels'][ $level_id ] )
		)
			? $locked['payload']['levels'][ $level_id ]
			: null;
		if (
			is_array( $pair )
			&& PMPro_Level_Removal_State::STATE_UNLINKED === $pair['state']
		) {
			$out = self::fail_retire( $level_id, $object_id, 'ok' );
			if ( 'ok' !== $out ) {
				return $out;
			}

			return self::flush_access_group_after_commit();
		}

		$prot = PMPro_Level_Deletion_Guard::classify_protection( $level_id );
		if ( 'incomplete' === $prot ) {
			return self::fail_retire( $level_id, $object_id, 'protected' );
		}

		$rel = PMPro_Level_Deletion_Guard::classify_relationship( $level_id, $object_id, $object_type );
		if ( 'protected' !== $prot && 'allowed' === $rel ) {
			$out = self::fail_retire( $level_id, $object_id, 'ok' );
			if ( 'ok' !== $out ) {
				return $out;
			}

			return PMPro_Level_Deletion_Coordinator::delete_level( $level_id, $object_id, $object_type );
		}

		if ( 'shared_unlink' === $rel ) {
			$kind_read = PMPro_Level_Removal_State::classify_live_kind( $level_id );
			if ( 'ok' !== $kind_read['result'] ) {
				return self::fail_retire( $level_id, $object_id, $kind_read['result'] );
			}

			$kind    = $kind_read['kind'];
			$written = PMPro_Level_Cleanup::unlink_requester_with_state( $object_id, $level_id, $kind );
			if ( 'ok' !== $written ) {
				return self::fail_retire( $level_id, $object_id, $written );
			}

			$commit = PMPro_Level_Deletion_Coordinator::control_transaction( 'commit' );
			if ( 'ok' !== $commit ) {
				return self::fail_retire( $level_id, $object_id, $commit );
			}

			PMPro_Level_Removal_State::invalidate_object_storage( $object_id );
			PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) );
			$flush    = self::flush_access_group_after_commit();
			$released = self::release_removal_locks( $level_id, $object_id );
			if ( 'ok' !== $flush || 'released' !== $released ) {
				return 'committed_with_warning';
			}

			return 'ok';
		}

		if ( 'protected' === $prot && 'allowed' === $rel ) {
			$kind_read = PMPro_Level_Removal_State::classify_live_kind( $level_id );
			if ( 'ok' !== $kind_read['result'] ) {
				return self::fail_retire( $level_id, $object_id, $kind_read['result'] );
			}

			$written = self::retire_pair_with_state( $level_id, $object_id );
			if ( 'ok' !== $written ) {
				return self::fail_retire( $level_id, $object_id, $written );
			}

			$commit = PMPro_Level_Deletion_Coordinator::control_transaction( 'commit' );
			if ( 'ok' !== $commit ) {
				return self::fail_retire( $level_id, $object_id, $commit );
			}

			PMPro_Level_Removal_State::invalidate_object_storage( $object_id );
			PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) );
			$released = self::release_removal_locks( $level_id, $object_id );
			if ( 'released' !== $released ) {
				return 'committed_with_warning';
			}

			return 'ok';
		}

		return self::fail_retire( $level_id, $object_id, $rel );
	}
}

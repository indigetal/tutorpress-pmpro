<?php
/**
 * Centralized cleanup for PMPro levels and their associations.
 */

namespace TUTORPRESS_PMPRO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMPro_Level_Cleanup {
    /**
     * Fully delete a PMPro level and all related data.
     *
     * @param int  $level_id                 Level ID to delete
     * @param bool $delete_level_if_exists   When true, delete the level row itself
     * @return void
     */
    public static function full_delete_level( $level_id, $delete_level_if_exists = true ) {
        $level_id = absint( $level_id );
        if ( $level_id <= 0 ) {
            return;
        }

        global $wpdb;

        // Remove associations from pmpro_memberships_pages first.
        if ( isset( $wpdb->pmpro_memberships_pages ) ) {
            $wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $level_id ), array( '%d' ) );
        }

        // Remove level meta rows.
        if ( isset( $wpdb->pmpro_membership_levelmeta ) ) {
            $wpdb->delete( $wpdb->pmpro_membership_levelmeta, array( 'pmpro_membership_level_id' => $level_id ), array( '%d' ) );
        }

        // Remove category relations.
        if ( isset( $wpdb->pmpro_memberships_categories ) ) {
            $wpdb->delete( $wpdb->pmpro_memberships_categories, array( 'membership_id' => $level_id ), array( '%d' ) );
        }

        // Phase 5: Remove from level groups.
        if ( isset( $wpdb->pmpro_membership_levels_groups ) ) {
            $wpdb->delete( $wpdb->pmpro_membership_levels_groups, array( 'level' => $level_id ), array( '%d' ) );
        }

        // Optionally delete the level itself.
        if ( $delete_level_if_exists && isset( $wpdb->pmpro_membership_levels ) ) {
            $wpdb->delete( $wpdb->pmpro_membership_levels, array( 'id' => $level_id ), array( '%d' ) );
        }

        // Prune from any course meta `_tutorpress_pmpro_levels` that references this id.
        $query = new \WP_Query( array(
            'post_type'      => array( 'courses', 'course-bundle' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'     => '_tutorpress_pmpro_levels',
                    'compare' => 'EXISTS',
                ),
            ),
        ) );
        if ( $query && ! empty( $query->posts ) ) {
            foreach ( $query->posts as $pid ) {
                $ids = get_post_meta( $pid, '_tutorpress_pmpro_levels', true );
                if ( is_array( $ids ) && in_array( $level_id, $ids, true ) ) {
                    $new = array_values( array_diff( array_map( 'intval', $ids ), array( $level_id ) ) );
                    if ( empty( $new ) ) {
                        delete_post_meta( $pid, '_tutorpress_pmpro_levels' );
                    } else {
                        update_post_meta( $pid, '_tutorpress_pmpro_levels', $new );
                    }
                }
            }
        }
    }

    /**
     * Remove a single course↔level association and prune course meta.
     *
     * @param int $course_id
     * @param int $level_id
     * @return void
     */
    public static function remove_course_level_mapping( $course_id, $level_id ) {
        $course_id = absint( $course_id );
        $level_id  = absint( $level_id );
        if ( $course_id <= 0 || $level_id <= 0 ) {
            return;
        }

        global $wpdb;
        if ( isset( $wpdb->pmpro_memberships_pages ) ) {
            $wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $level_id, 'page_id' => $course_id ), array( '%d', '%d' ) );
        }

        $ids = get_post_meta( $course_id, '_tutorpress_pmpro_levels', true );
        if ( is_array( $ids ) && in_array( $level_id, $ids, true ) ) {
            $new = array_values( array_diff( array_map( 'intval', $ids ), array( $level_id ) ) );
            if ( empty( $new ) ) {
                delete_post_meta( $course_id, '_tutorpress_pmpro_levels' );
            } else {
                update_post_meta( $course_id, '_tutorpress_pmpro_levels', $new );
            }
        }
    }

    private static function relationship_apis_callable() {
        if ( array_key_exists( 'tutorpress_pmpro_lds_rel_api', $GLOBALS ) ) { return $GLOBALS['tutorpress_pmpro_lds_rel_api']; }
        return function_exists( 'pmpro_get_membership_level_relationship_tables' ) && function_exists( 'pmpro_delete_membership_level_relationships' );
    }

    public static function relationship_tables() {
        if ( ! self::relationship_apis_callable() ) { return false; }
        $rows = pmpro_get_membership_level_relationship_tables();
        if ( ! is_array( $rows ) || array() === $rows ) { return false; }
        foreach ( $rows as $r ) {
            if ( ! is_array( $r ) || ! is_string( $r['table'] ?? null ) || '' === $r['table'] || ! is_string( $r['column'] ?? null ) || '' === $r['column'] ) { return false; }
        }
        return $rows;
    }

    public static function delete_level_relationships( $level_id ) {
        if ( false === self::relationship_tables() ) { return false; }
        if ( array_key_exists( 'tutorpress_pmpro_lds_rel_calls', $GLOBALS ) ) { $GLOBALS['tutorpress_pmpro_lds_rel_calls']++; }
        return true === pmpro_delete_membership_level_relationships( (int) $level_id );
    }

    public static function delete_level_row( $level_id ) {
        global $wpdb;
        $level_id = (int) $level_id;
        if ( $level_id <= 0 ) {
            return false;
        }
        if ( ! isset( $wpdb->pmpro_membership_levels ) || ! is_string( $wpdb->pmpro_membership_levels ) || '' === $wpdb->pmpro_membership_levels ) {
            return false;
        }
        $deleted = $wpdb->delete( $wpdb->pmpro_membership_levels, array( 'id' => $level_id ), array( '%d' ) );
        if ( false === $deleted || '' !== $wpdb->last_error ) {
            return false;
        }
        return 1 === $deleted;
    }

    private static function prune_current_object_postmeta( $object_id, $level_id ) {
        global $wpdb;
        $object_id = (int) $object_id; $level_id = (int) $level_id;
        if ( $object_id <= 0 || $level_id <= 0 ) { return false; }
        if ( ! isset( $wpdb->postmeta ) || ! is_string( $wpdb->postmeta ) || '' === $wpdb->postmeta ) { return false; }
        $sql  = "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s";
        $rows = $wpdb->get_col( $wpdb->prepare( $sql, $object_id, '_tutorpress_pmpro_levels' ) );
        if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { return false; }
        $ids = null;
        foreach ( $rows as $raw ) {
            $val = maybe_unserialize( $raw );
            if ( is_array( $val ) && in_array( $level_id, array_map( 'intval', $val ), true ) ) { $ids = array_map( 'intval', $val ); break; }
        }
        if ( null === $ids ) { return true; }
        $new = array_values( array_diff( $ids, array( $level_id ) ) );
        array() === $new ? delete_post_meta( $object_id, '_tutorpress_pmpro_levels' ) : update_post_meta( $object_id, '_tutorpress_pmpro_levels', $new );
        $rows = $wpdb->get_col( $wpdb->prepare( $sql, $object_id, '_tutorpress_pmpro_levels' ) );
        if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { return false; }
        foreach ( $rows as $raw ) {
            $val = maybe_unserialize( $raw );
            if ( is_array( $val ) && in_array( $level_id, array_map( 'intval', $val ), true ) ) { return false; }
        }
        return true;
    }

    public static function prune_verified_target_duplicates( $level_id ) {
        global $wpdb;
        $level_id = (int) $level_id;
        if ( $level_id <= 0 ) { return false; }
        if ( ! isset( $wpdb->postmeta ) || ! is_string( $wpdb->postmeta ) || '' === $wpdb->postmeta ) { return false; }
        if ( ! isset( $wpdb->posts ) || ! is_string( $wpdb->posts ) || '' === $wpdb->posts ) { return false; }
        $sql  = "SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type IN ( %s, %s )";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, '_tutorpress_pmpro_levels', 'courses', 'course-bundle' ) );
        if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { return false; }
        $seen = array();
        $ok   = true;
        foreach ( $rows as $row ) {
            $oid = (int) $row->post_id;
            if ( $oid <= 0 || isset( $seen[ $oid ] ) ) { continue; }
            $val = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $val ) || ! in_array( $level_id, array_map( 'intval', $val ), true ) ) { continue; }
            $seen[ $oid ] = true;
            if ( true !== self::prune_current_object_postmeta( $oid, $level_id ) ) { $ok = false; }
        }
        return $ok;
    }

    private static function owned_group_state( $object_id ) {
        global $wpdb;
        $object_id = (int) $object_id;
        if ( $object_id <= 0 ) { return false; }
        if ( ! isset( $wpdb->postmeta ) || ! is_string( $wpdb->postmeta ) || '' === $wpdb->postmeta ) { return false; }
        if ( ! isset( $wpdb->pmpro_groups ) || ! is_string( $wpdb->pmpro_groups ) || '' === $wpdb->pmpro_groups ) { return false; }
        if ( ! isset( $wpdb->pmpro_membership_levels_groups ) || ! is_string( $wpdb->pmpro_membership_levels_groups ) || '' === $wpdb->pmpro_membership_levels_groups ) { return false; }
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $object_id, '_tutorpress_pmpro_group_id' ) );
        if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { return false; }
        $ids = array();
        foreach ( $rows as $raw ) { $g = (int) $raw; if ( $g > 0 ) { $ids[ $g ] = true; } }
        if ( array() === $ids ) { return array( 'group_id' => 0, 'exists' => false, 'empty' => true ); }
        if ( 1 !== count( $ids ) ) { return false; }
        $gid   = (int) key( $ids );
        $found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_groups} WHERE id = %d", $gid ) );
        if ( false === $found || '' !== $wpdb->last_error ) { return false; }
        $n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE `group` = %d", $gid ) );
        if ( false === $n || null === $n || '' !== $wpdb->last_error ) { return false; }
        return array( 'group_id' => $gid, 'exists' => (int) $found === $gid, 'empty' => 0 === (int) $n );
    }

    public static function delete_owned_empty_group( $object_id ) {
        global $wpdb;
        $object_id = (int) $object_id;
        $state = self::owned_group_state( $object_id );
        if ( false === $state ) { return false; }
        $gid = (int) $state['group_id'];
        if ( 0 === $gid || true !== ( $state['empty'] ?? false ) ) { return true; }
        $deleted = $wpdb->delete( $wpdb->pmpro_groups, array( 'id' => $gid ), array( '%d' ) );
        if ( false === $deleted || '' !== $wpdb->last_error || 1 < $deleted ) { return false; }
        $found = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_groups} WHERE id = %d", $gid ) );
        if ( false === $found || '' !== $wpdb->last_error || (int) $found === $gid ) { return false; }
        delete_post_meta( $object_id, '_tutorpress_pmpro_group_id' );
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $object_id, '_tutorpress_pmpro_group_id' ) );
        if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { return false; }
        foreach ( $rows as $raw ) { if ( $gid === (int) $raw ) { return false; } }
        return true;
    }

    private static function delete_requester_reverse( $object_id, $level_id ) {
        global $wpdb;
        $object_id = (int) $object_id; $level_id = (int) $level_id;
        if ( $object_id <= 0 || $level_id <= 0 ) { return false; }
        if ( ! isset( $wpdb->pmpro_membership_levelmeta ) || ! is_string( $wpdb->pmpro_membership_levelmeta ) || '' === $wpdb->pmpro_membership_levelmeta ) { return false; }
        $sql = "SELECT meta_value FROM {$wpdb->pmpro_membership_levelmeta} WHERE pmpro_membership_level_id = %d AND meta_key = %s";
        $ok  = true;
        foreach ( array( 'tutorpress_course_id', 'tutorpress_bundle_id' ) as $key ) {
            $rows = $wpdb->get_col( $wpdb->prepare( $sql, $level_id, $key ) );
            if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { $ok = false; continue; }
            $hit = false;
            foreach ( $rows as $raw ) {
                if ( $object_id !== (int) $raw ) { continue; }
                $hit = true;
                function_exists( 'delete_pmpro_membership_level_meta' ) ? delete_pmpro_membership_level_meta( $level_id, $key, $raw ) : delete_metadata( 'pmpro_membership_level', $level_id, $key, $raw );
            }
            if ( ! $hit ) { continue; }
            $rows = $wpdb->get_col( $wpdb->prepare( $sql, $level_id, $key ) );
            if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { $ok = false; continue; }
            foreach ( $rows as $raw ) { if ( $object_id === (int) $raw ) { $ok = false; break; } }
        }
        return $ok;
    }

    private static function delete_requester_page_pair( $object_id, $level_id ) {
        global $wpdb;
        $object_id = (int) $object_id; $level_id = (int) $level_id;
        if ( $object_id <= 0 || $level_id <= 0 ) { return false; }
        if ( ! isset( $wpdb->pmpro_memberships_pages ) || ! is_string( $wpdb->pmpro_memberships_pages ) || '' === $wpdb->pmpro_memberships_pages ) { return false; }
        $deleted = $wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $level_id, 'page_id' => $object_id ), array( '%d', '%d' ) );
        return false !== $deleted && '' === $wpdb->last_error;
    }

    private static function delete_requester_group_mapping( $object_id, $level_id ) {
        global $wpdb;
        $object_id = (int) $object_id; $level_id = (int) $level_id;
        if ( $object_id <= 0 || $level_id <= 0 ) { return false; }
        if ( ! isset( $wpdb->postmeta ) || ! is_string( $wpdb->postmeta ) || '' === $wpdb->postmeta ) { return false; }
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $object_id, '_tutorpress_pmpro_group_id' ) );
        if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { return false; }
        $ids = array();
        foreach ( $rows as $raw ) { $g = (int) $raw; if ( $g > 0 ) { $ids[ $g ] = true; } }
        if ( array() === $ids ) { return true; }
        if ( 1 !== count( $ids ) || ! isset( $wpdb->pmpro_membership_levels_groups ) || ! is_string( $wpdb->pmpro_membership_levels_groups ) || '' === $wpdb->pmpro_membership_levels_groups ) { return false; }
        $deleted = $wpdb->delete( $wpdb->pmpro_membership_levels_groups, array( 'level' => $level_id, 'group' => (int) key( $ids ) ), array( '%d', '%d' ) );
        return false !== $deleted && '' === $wpdb->last_error;
    }

    private static function sequence_requester_writes( $object_id, $level_id ) {
        global $wpdb;
        $object_id = (int) $object_id; $level_id = (int) $level_id;
        if ( $object_id <= 0 || $level_id <= 0 ) { return false; }
        $ok = true;
        if ( true !== self::delete_requester_page_pair( $object_id, $level_id ) ) { $ok = false; }
        if ( true !== self::delete_requester_group_mapping( $object_id, $level_id ) ) { $ok = false; }
        if ( true !== self::delete_requester_reverse( $object_id, $level_id ) ) { $ok = false; }
        if ( true === self::prune_current_object_postmeta( $object_id, $level_id ) ) { return $ok; }
        $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $object_id, '_tutorpress_pmpro_levels' ) );
        if ( false === $rows || null === $rows || '' !== $wpdb->last_error ) { return false; }
        foreach ( $rows as $raw ) {
            $val = maybe_unserialize( $raw );
            if ( is_array( $val ) && in_array( $level_id, array_map( 'intval', $val ), true ) ) { return false; }
        }
        return $ok;
    }

    private static function reconfirm_level_still_missing( $level_id ) {
        global $wpdb;
        $level_id = (int) $level_id;
        if ( $level_id <= 0 || ! isset( $wpdb->pmpro_membership_levels ) || ! is_string( $wpdb->pmpro_membership_levels ) || '' === $wpdb->pmpro_membership_levels ) { return 'incomplete'; }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$wpdb->pmpro_membership_levels} WHERE id = %d", $level_id ), ARRAY_A );
        if ( false === $row || '' !== $wpdb->last_error ) { return 'incomplete'; }
        return isset( $row['id'] ) ? 'present' : 'still_missing';
    }

    private static function classify_stale_transaction( $control = null ) {
        if ( null !== $control ) {
            return in_array( $control, array( 'begin_failed', 'rollback_failed', 'commit_failed', 'transaction_outcome_unknown' ), true ) ? $control : 'error';
        }
        if ( ! class_exists( __NAMESPACE__ . '\\PMPro_Level_Deletion_Coordinator' ) ) { return 'error'; }
        $in = PMPro_Level_Deletion_Coordinator::session_in_transaction();
        if ( true === $in ) { return 'outer_transaction_active'; }
        if ( null === $in ) { return 'transaction_state_unknown'; }
        $probe = PMPro_Level_Deletion_Coordinator::probe_write_tables( PMPro_Level_Deletion_Coordinator::participating_write_tables( 'stale' ) );
        return in_array( $probe, array( 'ok', 'nontransactional', 'error' ), true ) ? $probe : 'error';
    }

    public static function cleanup_missing_level( $object_id, $level_id ) {
        $object_id = (int) $object_id; $level_id = (int) $level_id;
        if ( $object_id <= 0 || $level_id <= 0 ) { return 'skip'; }
        if ( 'still_missing' !== self::reconfirm_level_still_missing( $level_id ) ) { return 'skip'; }
        $kind = self::classify_stale_transaction();
        if ( 'nontransactional' === $kind ) {
            $ok = self::sequence_requester_writes( $object_id, $level_id );
            PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) );
            return true === $ok ? 'ok' : 'partial_failure';
        }
        if ( 'ok' !== $kind ) { return 'skip'; }
        $begin = PMPro_Level_Deletion_Coordinator::control_transaction( 'begin' );
        if ( 'ok' !== $begin ) {
            if ( in_array( $begin, array( 'begin_failed', 'rollback_failed', 'commit_failed', 'transaction_outcome_unknown' ), true ) ) { self::classify_stale_transaction( $begin ); }
            return 'skip';
        }
        if ( 'still_missing' !== self::reconfirm_level_still_missing( $level_id ) ) { PMPro_Level_Deletion_Coordinator::control_transaction( 'rollback' ); return 'skip'; }
        $ok = self::sequence_requester_writes( $object_id, $level_id );
        if ( true !== $ok ) {
            $rb = PMPro_Level_Deletion_Coordinator::control_transaction( 'rollback' );
            if ( in_array( $rb, array( 'begin_failed', 'rollback_failed', 'commit_failed', 'transaction_outcome_unknown' ), true ) ) { self::classify_stale_transaction( $rb ); }
            PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) ); return 'partial_failure';
        }
        $cm = PMPro_Level_Deletion_Coordinator::control_transaction( 'commit' );
        if ( 'ok' !== $cm ) {
            if ( in_array( $cm, array( 'begin_failed', 'rollback_failed', 'commit_failed', 'transaction_outcome_unknown' ), true ) ) { self::classify_stale_transaction( $cm ); }
            PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) ); return 'partial_failure';
        }
        PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) ); return 'ok';
    }

    public static function unlink_shared_level( $object_id, $level_id ) {
        $object_id = (int) $object_id; $level_id = (int) $level_id;
        if ( $object_id <= 0 || $level_id <= 0 ) { return 'skip'; }
        $kind = self::classify_stale_transaction();
        if ( 'nontransactional' === $kind ) {
            $ok = self::sequence_requester_writes( $object_id, $level_id );
            PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) );
            return true === $ok ? 'ok' : 'partial_failure';
        }
        if ( 'ok' !== $kind ) { return 'skip'; }
        $begin = PMPro_Level_Deletion_Coordinator::control_transaction( 'begin' );
        if ( 'ok' !== $begin ) {
            if ( in_array( $begin, array( 'begin_failed', 'rollback_failed', 'commit_failed', 'transaction_outcome_unknown' ), true ) ) { self::classify_stale_transaction( $begin ); }
            return 'skip';
        }
        $ok = self::sequence_requester_writes( $object_id, $level_id );
        if ( true !== $ok ) {
            $rb = PMPro_Level_Deletion_Coordinator::control_transaction( 'rollback' );
            if ( in_array( $rb, array( 'begin_failed', 'rollback_failed', 'commit_failed', 'transaction_outcome_unknown' ), true ) ) { self::classify_stale_transaction( $rb ); }
            PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) ); return 'partial_failure';
        }
        $cm = PMPro_Level_Deletion_Coordinator::control_transaction( 'commit' );
        if ( 'ok' !== $cm ) {
            if ( in_array( $cm, array( 'begin_failed', 'rollback_failed', 'commit_failed', 'transaction_outcome_unknown' ), true ) ) { self::classify_stale_transaction( $cm ); }
            PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) ); return 'partial_failure';
        }
        PMPro_Level_Deletion_Coordinator::invalidate_deletion_caches( $level_id, array( $object_id ) ); return 'ok';
    }
}



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
}



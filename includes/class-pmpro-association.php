<?php
/**
 * PMPro Course↔Level association helpers.
 *
 * Maintains rows in pmpro_memberships_pages to reflect which PMPro levels
 * are required for a given Tutor LMS course. This enables pmpro_has_membership_access()
 * to work without relying solely on post meta.
 */

namespace TUTORPRESS_PMPRO;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMPro_Association {
    /**
     * Ensure a single association row exists between a course and a PMPro level.
     *
     * @param int $course_id
     * @param int $level_id
     * @return void
     */
    public static function ensure_course_level_association( $course_id, $level_id ) {
        if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
            return;
        }
        $course_id = absint( $course_id );
        $level_id  = absint( $level_id );
        if ( $course_id <= 0 || $level_id <= 0 ) {
            return;
        }
        global $wpdb;
        // Guard: only for Tutor LMS course post type.
        $post_type = get_post_type( $course_id );
        if ( 'courses' !== $post_type ) {
            return;
        }
        // Check if association exists.
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE membership_id = %d AND page_id = %d LIMIT 1", $level_id, $course_id ) );
        if ( null === $exists ) {
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->pmpro_memberships_pages} (membership_id, page_id) VALUES ( %d, %d )", $level_id, $course_id ) );
        }
    }

    /**
     * Sync associations for a course to exactly match the provided level IDs.
     * Adds missing rows and removes extra rows for that course.
     *
     * @param int   $course_id
     * @param array $level_ids
     * @return void
     */
    public static function sync_course_level_associations( $course_id, $level_ids ) {
        if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
            return;
        }
        $course_id = absint( $course_id );
        if ( $course_id <= 0 ) {
            return;
        }
        $post_type = get_post_type( $course_id );
        if ( 'courses' !== $post_type ) {
            return;
        }
        $level_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $level_ids ) ) ) );

        global $wpdb;
        $existing = $wpdb->get_col( $wpdb->prepare( "SELECT membership_id FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d", $course_id ) );
        $existing = array_map( 'intval', is_array( $existing ) ? $existing : array() );

        $to_add    = array_diff( $level_ids, $existing );
        $to_remove = array_diff( $existing, $level_ids );

        foreach ( $to_add as $lid ) {
            $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->pmpro_memberships_pages} (membership_id, page_id) VALUES ( %d, %d )", $lid, $course_id ) );
        }
        if ( ! empty( $to_remove ) ) {
            // Delete only rows for this course and those level ids.
            $in = implode( ',', array_fill( 0, count( $to_remove ), '%d' ) );
            $sql = $wpdb->prepare( "DELETE FROM {$wpdb->pmpro_memberships_pages} WHERE page_id = %d AND membership_id IN ( {$in} )", array_merge( array( $course_id ), $to_remove ) );
            $wpdb->query( $sql );
        }
    }

    /**
     * Remove all associations for a given PMPro level (any page).
     * Useful when a level is deleted.
     *
     * @param int $level_id
     * @return void
     */
    public static function remove_associations_for_level( $level_id ) {
        if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
            return;
        }
        $level_id = absint( $level_id );
        if ( $level_id <= 0 ) {
            return;
        }
        global $wpdb;
        $wpdb->delete( $wpdb->pmpro_memberships_pages, array( 'membership_id' => $level_id ), array( '%d' ) );
    }
}



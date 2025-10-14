<?php
/**
 * Bundle Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

use TUTOR\Course;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bundle Importer Class.
 */
class BundleImporter {

	/**
	 * Update bundle course ids.
	 *
	 * @since 3.6.0
	 *
	 * @param integer $bundle_id the bundle id.
	 * @param array   $course_ids the array of course ids.
	 *
	 * @return bool
	 */
	public function update_course_bundle_ids( int $bundle_id, array $course_ids ) {
		if ( ! $bundle_id ) {
			return false;
		}

		if ( get_tutor_post_types( 'bundle' ) !== get_post_type( $bundle_id ) ) {
			return false;
		}

		// Update post meta.
		update_post_meta(
			$bundle_id,
			'bundle-course-ids',
			implode( ',', $course_ids )
		);

		return true;
	}

	/**
	 * Prepare course bundle meta.
	 *
	 * @since 3.6.0
	 *
	 * @param array $bundle_meta the bundle meta to prepare.
	 *
	 * @return void
	 */
	public function prepare_bundle_meta( array $bundle_meta ) {

		Helper::reset_post_data( 'course_benefits' );

		if ( isset( $bundle_meta[ Course::COURSE_BENEFITS_META ] ) ) {
			$_POST['course_benefits'] = $bundle_meta[ Course::COURSE_BENEFITS_META ];
		}

		Helper::reset_post_data( 'ribbon_type' );

		if ( isset( $bundle_meta['tutor_bundle_ribbon_type'] ) ) {
			$_POST['ribbon_type'] = $bundle_meta['tutor_bundle_ribbon_type'];
		}

		Helper::reset_post_data( 'sale_price' );
		if ( isset( $bundle_meta[ Course::COURSE_SALE_PRICE_META ] ) ) {
			$_POST['sale_price'] = $bundle_meta[ Course::COURSE_SALE_PRICE_META ];
		}
	}
}

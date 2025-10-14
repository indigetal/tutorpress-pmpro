<?php
/**
 * Course Importer
 *
 * @package TutorPro\Tools
 * @author  Themeum<support@themeum.com>
 * @link    https://themeum.com
 * @since   3.6.0
 */

namespace TutorPro\Tools;

use TUTOR\Course;
use Tutor\Models\CourseModel;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Course Importer class.
 */
class CourseImporter {

	/**
	 * Set taxonomies to courses.
	 *
	 * @since 3.6.0
	 *
	 * @param array $taxonomies the taxonomies to set to courses.
	 *
	 * @return array
	 */
	public function course_importer_set_categories_tags( $taxonomies ) {
		$result = true;

		if ( $taxonomies ) {
			foreach ( $taxonomies as $post_id => $terms ) {
				$categories = array_column( $terms['categories'], 'name' );
				$tags       = array_column( $terms['tags'], 'name' );

				$result = wp_set_object_terms( $post_id, $categories, CourseModel::COURSE_CATEGORY );

				if ( is_wp_error( $result ) ) {
					ErrorHandler::set_error( __( 'Course Term', 'tutor-pro' ), $result->get_error_message() );
				}

				$result = wp_set_object_terms( $post_id, $tags, CourseModel::COURSE_TAG );

				if ( is_wp_error( $result ) ) {
					ErrorHandler::set_error( __( 'Course Term', 'tutor-pro' ), $result->get_error_message() );
				}
			}
		}

		return $result;
	}




	/**
	 * Prepare course meta for insertion.
	 *
	 * @since 3.6.0
	 *
	 * @param array $course_metas the array of course meta to prepare.
	 * @param bool  $keep_media_files whether to download media files or not.
	 *
	 * @return void
	 */
	public function prepare_course_meta( $course_metas, $keep_media_files = false ) {
		$course_metas = array_map( fn( $val ) => $val[0], $course_metas );

		$_POST['_tutor_course_additional_data_edit'] = true;

		Helper::reset_post_data( 'video' );

		if ( isset( $course_metas['_video'] ) ) {
			$video_data = $course_metas['_video'];

			if ( isset( $video_data['source'] ) && 'html5' === $video_data['source'] && $keep_media_files ) {
				$video_url   = $video_data['source_html5'];
				$upload_data = Helper::upload_file_by_url( $video_url );
				if ( ! is_wp_error( $upload_data ) ) {
					$video_data['source_video_id'] = $upload_data['id'];
					$video_data['source_html5']    = $upload_data['url'];
				}
			}

			$_POST['video'] = $video_data;
		}

		Helper::reset_post_data( 'content_drip_settings' );

		if ( isset( $course_metas['_content_drip_settings'] ) ) {
			$_POST['content_drip_settings'] = $course_metas['_content_drip_settings'];
		}

		Helper::reset_post_data( COURSE::COURSE_LEVEL_META );
		// Course level meta.
		if ( isset( $course_metas[ COURSE::COURSE_LEVEL_META ] )
		) {
			$_POST[ COURSE::COURSE_LEVEL_META ] = $course_metas[ COURSE::COURSE_LEVEL_META ];
		}

		Helper::reset_post_data( COURSE::COURSE_SETTINGS_META );
		// Course settings meta.
		if ( isset( $course_metas[ COURSE::COURSE_SETTINGS_META ] ) ) {
			$_POST[ COURSE::COURSE_SETTINGS_META ] = $course_metas[ COURSE::COURSE_SETTINGS_META ];
		}

		Helper::reset_post_data( 'course_duration' );
		// Course duration meta.
		if ( isset( $course_metas[ COURSE::COURSE_DURATION_META ] ) ) {
			$_POST['course_duration'] = $course_metas[ COURSE::COURSE_DURATION_META ];
		}

		// Additional course data meta.
		$additional_course_meta_mappings = array(
			'course_benefits'          => COURSE::COURSE_BENEFITS_META,
			'course_requirements'      => COURSE::COURSE_REQUIREMENTS_META,
			'course_target_audience'   => COURSE::COURSE_TARGET_AUDIENCE_META,
			'course_material_includes' => COURSE::COURSE_MATERIAL_INCLUDE_META,
		);

		foreach ( $additional_course_meta_mappings as $post_key => $meta_key ) {
			Helper::reset_post_data( $post_key );
			if ( isset( $course_metas[ $meta_key ] ) ) {
				$_POST[ $post_key ] = $course_metas[ $meta_key ];
			}
		}

		Helper::reset_post_data( 'tutor_course_price_type' );
		// Course price type meta.
		if ( isset( $course_metas[ COURSE::COURSE_PRICE_TYPE_META ] ) ) {
			$_POST['tutor_course_price_type'] = $course_metas[ COURSE::COURSE_PRICE_TYPE_META ];
		}

		Helper::reset_post_data( COURSE::COURSE_PRODUCT_ID_META );
		// Course product id.
		if ( isset( $course_metas[ COURSE::COURSE_PRODUCT_ID_META ] ) ) {
			$_POST[ COURSE::COURSE_PRODUCT_ID_META ] = $course_metas[ COURSE::COURSE_PRODUCT_ID_META ];
		}

		Helper::reset_post_data( 'course_price' );
		// Course regular price meta.
		if ( isset( $course_metas[ COURSE::COURSE_PRICE_META ] ) ) {
			$_POST['course_price'] = $course_metas[ COURSE::COURSE_PRICE_META ];
		}

		Helper::reset_post_data( 'course_sale_price' );
		// Course sale price meta.
		if ( isset( $course_metas[ COURSE::COURSE_SALE_PRICE_META ] ) ) {
			$_POST['course_sale_price']    = $course_metas[ COURSE::COURSE_SALE_PRICE_META ];
			$_REQUEST['course_sale_price'] = $course_metas[ COURSE::COURSE_SALE_PRICE_META ];
		}

		Helper::reset_post_data( 'course_selling_option' );
		// Course selling option.
		if ( isset( $course_metas[ COURSE::COURSE_SELLING_OPTION_META ] ) ) {
			$_POST['course_selling_option'] = $course_metas[ COURSE::COURSE_SELLING_OPTION_META ];
		}

		Helper::reset_post_data( 'tutor_course_certificate_template' );
		// Tutor certificate meta.
		if ( isset( $course_metas['tutor_course_certificate_template'] ) ) {
			$_POST['tutor_course_certificate_template'] = $course_metas['tutor_course_certificate_template'];
		}

		Helper::reset_post_data( COURSE::COURSE_ENABLE_QA_META );
		// Tutor course enable qa meta.
		if ( isset( $course_metas[ COURSE::COURSE_ENABLE_QA_META ] ) && 'yes' === $course_metas[ COURSE::COURSE_ENABLE_QA_META ] ) {
			$_POST[ COURSE::COURSE_ENABLE_QA_META ] = 'yes';
		}

		Helper::reset_post_data( COURSE::PUBLIC_COURSE_META );
		// Tutor public course meta.
		if ( isset( $course_metas[ COURSE::PUBLIC_COURSE_META ] ) && 'yes' === $course_metas[ COURSE::PUBLIC_COURSE_META ] ) {
			$_POST[ COURSE::PUBLIC_COURSE_META ] = 'yes';
		}

		Helper::reset_post_data( 'enable_coming_soon' );
		Helper::reset_post_data( 'enable_curriculum_preview' );
		// Tutor coming soon meta.
		if ( isset( $course_metas['_tutor_course_enable_coming_soon'] ) ) {
			$_POST['enable_coming_soon']        = $course_metas['_tutor_course_enable_coming_soon'];
			$_POST['enable_curriculum_preview'] = $course_metas['_tutor_course_enable_curriculum_preview'];
		}
	}
}

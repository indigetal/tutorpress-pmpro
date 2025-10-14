<?php
/**
 * Post Type Class for Content Bank
 *
 * @package TutorPro\ContentBank
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.7.0
 */

namespace TutorPro\ContentBank;

use TutorPro\ContentBank\Models\CollectionModel;
use TutorPro\ContentBank\Models\ContentModel;

/**
 * PostType Class.
 *
 * @since 3.7.0
 */
class PostType {
	/**
	 * Register hooks and dependencies
	 *
	 * @since 3.7.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register_post_types' ) );
	}

	/**
	 * Prepare arguments for custom post type registration.
	 *
	 * @since 3.7.0
	 *
	 * @param array $custom_args Custom arguments to merge with default.
	 *
	 * @return array
	 */
	private function prepare_args( $custom_args = array() ) {
		$default_args = array(
			'label'              => __( 'Unnamed', 'tutor-pro' ),
			'description'        => __( 'Description', 'tutor-pro' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'query_var'          => false,
			'has_archive'        => false,
			'hierarchical'       => false,
			'show_in_menu'       => false,
			'menu_position'      => null,
		);

		return array_merge( $default_args, $custom_args );
	}

	/**
	 * Register custom post types for content bank.
	 *
	 * @since 3.7.0
	 */
	public function register_post_types() {
		/**
		 * Register Collection Post Type
		 *
		 * @since 3.7.0
		 */
		$args = $this->prepare_args( array( 'label' => __( 'Collections', 'tutor-pro' ) ) );
		register_post_type( CollectionModel::POST_TYPE, $args );

		/**
		 * Register Content Lesson Post Type
		 *
		 * @since 3.7.0
		 */
		$args = $this->prepare_args(
			array(
				'label'   => __( 'Lessons', 'tutor-pro' ),
				'show_ui' => true,
			)
		);

		register_post_type( ContentModel::LESSON_POST_TYPE, $args );

		/**
		 * Register Content Assignment Post Type
		 *
		 * @since 3.7.0
		 */
		$args = $this->prepare_args( array( 'label' => __( 'Assignments', 'tutor-pro' ) ) );
		register_post_type( ContentModel::ASSIGNMENT_POST_TYPE, $args );

		/**
		 * Register Content Question Post Type
		 *
		 * @since 3.7.0
		 */
		$args = $this->prepare_args( array( 'label' => __( 'Questions', 'tutor-pro' ) ) );
		register_post_type( ContentModel::QUESTION_POST_TYPE, $args );
	}
}

<?php
/**
 * Visibility field control
 *
 * @package TutorPro\VisibilityControl
 * @since 3.5.0
 */

namespace TUTOR_PRO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class to control different types of fields
 */
class VisibilityFieldControl {

	/**
	 * Visibility controls block template slug
	 *
	 * @since 3.5.0
	 */
	const BLOCK_SLUG = 'visibility-control';

	/**
	 * Visibility controls option key
	 *
	 * @since 3.5.0
	 */
	const OPTION_KEY = 'visibility_control';

	/**
	 * Register hooks
	 *
	 * @since 3.5.0
	 */
	public function __construct() {
		add_filter( 'tutor/options/extend/attr', array( $this, 'add_course_builder_visibility_controls' ) );
		add_filter( 'tutor_settings_block_template_path', array( $this, 'alter_the_template' ), 10, 2 );
		add_filter( 'tutor_localize_data', array( $this, 'add_visibility_control_data' ) );
	}

	/**
	 * Add fields to control course builder visibility
	 *
	 * @since 3.5.0
	 *
	 * @param array $settings Settings array.
	 *
	 * @return array
	 */
	public function add_course_builder_visibility_controls( array $settings ): array {
		$roles = array(
			'admin'      => __( 'Admin', 'tutor-pro' ),
			'instructor' => __( 'Instructor', 'tutor-pro' ),
		);

		$visibility_fields = array(
			'label'      => __( 'Course Builder Fields Visibility Control', 'tutor-pro' ),
			'slug'       => 'visibility_control',
			'block_type' => 'visibility-control',
			'sections'   => array(
				array(
					'label'  => __( 'Basics', 'tutor-pro' ),
					'fields' => $this->get_visibility_fields( $this->basic_fields() ),
					'roles'  => $roles,
				),
				array(
					'label'  => __( 'Curriculum', 'tutor-pro' ),
					'fields' => $this->get_visibility_fields( $this->curriculum_fields() ),
					'roles'  => $roles,
				),
				array(
					'label'  => __( 'Additional', 'tutor-pro' ),
					'fields' => $this->get_visibility_fields( $this->additional_fields() ),
					'roles'  => $roles,
				),
			),
		);
		array_unshift( $settings['advanced']['blocks'], $visibility_fields );

		return apply_filters( 'tutor_pro_after_visibility_controls', $settings );
	}

	/**
	 * Basic fields configuration
	 *
	 * @since 3.5.0
	 *
	 * @return array
	 */
	private function basic_fields(): array {
		$fields = array(
			array(
				'key'   => 'basics_options_general',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'General', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_options_content_drip',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Content Drip', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_options_enrollment',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Enrollment', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_featured_image',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Featured Image', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_intro_video',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Intro Video', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_scheduling_options',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Scheduling Option', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_pricing_options',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Pricing Options', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_categories',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Categories', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_tags',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Tags', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_author',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Author', 'tutor-pro' ),
			),
			array(
				'key'   => 'basics_instructors',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Instructor', 'tutor-pro' ),
			),
		);

		return apply_filters( 'tutor_pro_course_builder_basic_fields', $fields );
	}

	/**
	 * Curriculum fields configuration
	 *
	 * @since 3.5.0
	 *
	 * @return array
	 */
	private function curriculum_fields(): array {
		$fields = array(
			array(
				'key'   => 'curriculum_lesson_featured_image',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Lesson- Featured Image', 'tutor-pro' ),
			),
			array(
				'key'   => 'curriculum_lesson_video',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Lesson- Video', 'tutor-pro' ),
			),
			array(
				'key'   => 'curriculum_lesson_video_playback_time',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Lesson- Video Playback Time', 'tutor-pro' ),
			),
			array(
				'key'   => 'curriculum_lesson_exercise_files',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Lesson- Exercise Files', 'tutor-pro' ),
			),
			array(
				'key'   => 'curriculum_lesson_lesson_preview',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Lesson- Lesson Preview', 'tutor-pro' ),
			),
		);

		return apply_filters( 'tutor_pro_course_builder_curriculum_fields', $fields );
	}

	/**
	 * Additional fields configuration
	 *
	 * @since 3.5.0
	 *
	 * @return array
	 */
	private function additional_fields(): array {
		$fields = array(
			array(
				'key'   => 'additional_course_benefits',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Overview- What Will I Learn?', 'tutor-pro' ),
			),
			array(
				'key'   => 'additional_course_target_audience',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Overview- Target Audience', 'tutor-pro' ),
			),
			array(
				'key'   => 'additional_total_course_duration',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Overview- Total Course Duration', 'tutor-pro' ),
			),
			array(
				'key'   => 'additional_course_material_includes',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Overview- Materials Included', 'tutor-pro' ),
			),
			array(
				'key'   => 'additional_course_requirements',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Overview- Requirements/Instructions', 'tutor-pro' ),
			),
			array(
				'key'   => 'additional_certificate',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Certificate', 'tutor-pro' ),
			),
			array(
				'key'   => 'additional_attachments',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Attachments', 'tutor-pro' ),
			),
			array(
				'key'   => 'additional_schedule_live_class',
				'event' => 'visibility_control.course_builder',
				'label' => __( 'Schedule Live Class', 'tutor-pro' ),
			),
		);

		return apply_filters( 'tutor_pro_course_builder_additional_fields', $fields );
	}

	/**
	 * Get basic fields with default values
	 *
	 * @since 3.5.0
	 *
	 * @param array $fields Fields array.
	 *
	 * @return array
	 */
	private function get_visibility_fields( array $fields ): array {
		$default_type  = 'label';
		$default_desc  = '';
		$default_class = 'tutor-fs-6 tutor-fw-medium';
		$default_value = 'on';

		foreach ( $fields as $key => $field ) {
			if ( empty( $field['type'] ) ) {
				$field['type'] = $default_type;
			}
			if ( empty( $field['desc'] ) ) {
				$field['desc'] = $default_desc;
			}
			if ( empty( $field['class'] ) ) {
				$field['class'] = $default_class;
			}
			if ( empty( $field['default'] ) ) {
				$field['default'] = $default_value;
			}

			$fields[ $key ] = $field;
		}

		return $fields;
	}

	/**
	 * Alter template for the visibility controls
	 *
	 * @since 3.5.0
	 *
	 * @param string $template_path Default template path.
	 * @param mixed  $block         Block settings.
	 *
	 * @return string updated template path
	 */
	public function alter_the_template( string $template_path, $block ): string {
		$block_type = is_array( $block ) ? $block['block_type'] : '';
		if ( self::BLOCK_SLUG === $block_type ) {
			$template_path = tutor_pro()->path . 'views/settings/blocks/' . self::BLOCK_SLUG . '.php';
		}
		return $template_path;
	}

	/**
	 * Add visibility control data to localize data
	 *
	 * @since 3.5.0
	 *
	 * @param array $data Localize data.
	 *
	 * @return array
	 */
	public function add_visibility_control_data( $data ) {
		$visibility_fields = tutor_utils()->get_option( self::OPTION_KEY );

		$data['visibility_control'] = $visibility_fields;
		return $data;
	}
}

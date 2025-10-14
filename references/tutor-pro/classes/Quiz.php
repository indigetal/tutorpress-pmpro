<?php
/**
 * Quiz class for PRO user.
 *
 * @package TutorPro\Quiz
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 2.2.0
 */

namespace TUTOR_PRO;

use TUTOR\Input;

/**
 * Class Quiz
 *
 * @since 2.2.0
 */
class Quiz {
	/**
	 * Register hooks
	 *
	 * @since 2.2.0
	 *
	 * @return void
	 */
	public function __construct() {
		add_filter( 'tutor_quiz_question_data', array( $this, 'extend_question_data' ), 10, 2 );
		add_action( 'tutor_quiz_question_form_after_answer_list', array( $this, 'extend_question_form' ) );
		add_action( 'tutor_quiz_attempt_details_loop_after_row', array( $this, 'correct_answer_explanation_content' ), 10, 3 );
		add_action( 'tutor_quiz_attempt_details_after_result', array( $this, 'display_answer_explanation_button' ), 10, 2 );
		add_action( 'tutor_quiz_question_desc_field', array( $this, 'add_wp_editor_to_quiz_question_desc' ) );
		add_action( 'tutor_quiz_question_desc_render', array( $this, 'render_question_description' ), 10, 2 );
		add_action( 'tutor_quiz_question_after_answers', array( $this, 'render_question_answer_explanation' ), 10, 3 );
	}

	/**
	 * Add WP editor support to quiz question description.
	 *
	 * @since 2.2.3
	 *
	 * @param object $question_obj question object.
	 *
	 * @return void
	 */
	public function add_wp_editor_to_quiz_question_desc( $question_obj ) {
		wp_editor(
			$question_obj->question_description,
			'tutor_quiz_desc_text_editor',
			array( 'editor_height' => 150 )
		);
	}

	/**
	 * Render question description data.
	 *
	 * @since 2.2.3
	 *
	 * @param string $description description with HTML markup.
	 * @param object $question question object.
	 *
	 * @return void
	 */
	public function render_question_description( $description, $question ) {
		add_filter( 'wp_kses_allowed_html', Input::class . '::allow_iframe', 10, 2 );
		echo do_shortcode( wp_kses_post( $description ) );
	}

	/**
	 * Add data to question during add/edit quiz question.
	 *
	 * @since 2.2.0
	 * @since 3.0.0 $input param added.
	 *
	 * @param array $data question data.
	 * @param array $input input data.
	 *
	 * @return array question data.
	 */
	public function extend_question_data( $data, $input ) {
		$data['answer_explanation'] = '';

		if ( isset( $input['answer_explanation'] ) ) {
			$explanation = Input::sanitize( wp_slash( $input['answer_explanation'] ), '', Input::TYPE_KSES_POST );
			if ( '<p><br></p>' === $explanation ) {
				$explanation = '';
			}

			$data['answer_explanation'] = $explanation;
		}

		/**
		 * WP editor support to Quiz question
		 *
		 * @since 2.2.3
		 */
		add_filter( 'wp_kses_allowed_html', Input::class . '::allow_iframe', 10, 2 );
		$data['question_description'] = Input::sanitize( wp_slash( $input['question_description'] ) ?? '', '', Input::TYPE_KSES_POST );
		remove_filter( 'wp_kses_allowed_html', Input::class . '::allow_iframe', 10, 2 );

		return $data;
	}

	/**
	 * Extend quiz question form.
	 *
	 * @since 2.2.0
	 *
	 * @param object $question question object.
	 *
	 * @return void
	 */
	public function extend_question_form( $question ) {
		?>
			<div class="tutor-mt-16 tutor-mb-16">
				<label class="tutor-form-label">
					<?php esc_html_e( 'Answer Explanation', 'tutor-pro' ); ?>
				</label>

				<textarea name="answer_explanation" 
					id="tutor_answer_explanation" class="tutor-form-control">
					<?php echo wp_kses_post( wp_unslash( $question->answer_explanation ?? '' ) ); ?>
				</textarea>

			</div>
		<?php
	}

	/**
	 * Correct answer explanation content.
	 *
	 * @since 2.2.0
	 *
	 * @param object $answer answer object.
	 * @param string $answer_status answer status.
	 * @param array  $table_columns table columns.
	 *
	 * @return void
	 */
	public function correct_answer_explanation_content( $answer, $answer_status, $table_columns ) {
		$trimmed_answer  = trim( wp_strip_all_tags( $answer->answer_explanation ) );
		$is_image_answer = preg_match( '/^(<p><img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*><\/p>)/i', $answer->answer_explanation );
		if ( ( strlen( $trimmed_answer ) > 0 || $is_image_answer ) && 'pending' !== $answer_status ) :
			?>
			<tr>
				<td colspan="<?php echo esc_attr( count( $table_columns ) ); ?>" class="column-empty-state data-td-content" id="tutor-question-<?php echo esc_attr( $answer->question_id ); ?>" style="display:none;">
					<div class="tutor-quiz-explanation-wrapper">
						<div class="tutor-d-flex tutor-gap-1 tutor-align-center tutor-mb-12">
							<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M14.8113 10.9114C14.5746 12.8822 13.2838 13.6697 12.7263 14.2414C12.1679 14.8147 12.268 14.9289 12.3055 15.7697C12.3117 15.8738 12.2966 15.978 12.261 16.0759C12.2255 16.1739 12.1703 16.2636 12.0987 16.3394C12.0272 16.4152 11.941 16.4755 11.8452 16.5167C11.7495 16.5579 11.6463 16.5791 11.5421 16.5789H9.32712C9.22297 16.5787 9.11995 16.5573 9.02434 16.516C8.92874 16.4747 8.84255 16.4143 8.77104 16.3386C8.69954 16.2629 8.64421 16.1734 8.60843 16.0756C8.57265 15.9778 8.55718 15.8737 8.56295 15.7697C8.56295 14.9447 8.63962 14.7531 8.14295 14.2414C7.50962 13.6081 6.01962 12.7139 6.01962 10.2547C6.01581 9.64389 6.13859 9.03889 6.38021 8.47787C6.62183 7.91684 6.97705 7.41195 7.42349 6.99502C7.86992 6.57809 8.39789 6.25817 8.9741 6.05541C9.55032 5.85266 10.1623 5.77147 10.7714 5.81697C11.3806 5.86247 11.9737 6.03367 12.5134 6.31977C13.0531 6.60587 13.5277 7.00068 13.9072 7.4793C14.2868 7.95793 14.5631 8.50998 14.7187 9.10068C14.8742 9.69138 14.9058 10.3079 14.8113 10.9114Z" stroke="#4B505C" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
								<path d="M9.15104 16.5335V17.7935C9.15104 18.1377 9.34271 18.4202 9.57854 18.4202H11.2894C11.5269 18.4202 11.7177 18.1369 11.7177 17.7935V16.5335M9.78438 7.75021C9.29232 7.75043 8.8205 7.94605 8.47264 8.29406C8.12479 8.64208 7.92938 9.11399 7.92938 9.60604M16.5444 10.4235H18.0727M15.006 5.41354L16.0902 4.32938M15.8394 14.6702L16.9235 15.7535M10.4344 2.48438V3.72021M4.80104 4.32854L5.87771 5.41354M3.96771 15.7535L5.04437 14.6702M4.32438 10.4235H2.79688" stroke="#4B505C" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
							</svg>
							<div class="tutor-fw-medium"><?php esc_html_e( 'Answer Explanation', 'tutor-pro' ); ?></div>
						</div>
						<div class="tutor-overflow-hidden">							
							<?php echo wp_kses_post( wp_unslash( $answer->answer_explanation ) ); ?>
						</div>
					</div>
				</td>
			</tr>
			<?php
		endif;
	}

	/**
	 * Display answer explanation button
	 *
	 * @since 3.8.0
	 *
	 * @param object $answer answer object.
	 * @param string $answer_status answer status.
	 *
	 * @return void
	 */
	public function display_answer_explanation_button( $answer, $answer_status ) {
		$trimmed_answer  = trim( wp_strip_all_tags( $answer->answer_explanation ) );
		$is_image_answer = preg_match( '/^(<p><img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*><\/p>)/i', $answer->answer_explanation );
		if ( ( strlen( $trimmed_answer ) > 0 || $is_image_answer ) && 'pending' !== $answer_status ) :
			?>
			<div class="tooltip-wrap">
				<span class="tooltip-txt <?php echo esc_attr( is_rtl() ? 'tooltip-right' : 'tooltip-left' ); ?>"><?php esc_html_e( 'Show Explanation', 'tutor-pro', ); ?></span>
				<button class="tutor-quiz-explanation-display-button" data-td-target="tutor-question-<?php echo esc_attr( $answer->question_id ); ?>">
					<svg width="21" height="20" viewBox="0 0 21 20" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M14.8113 10.4114C14.5746 12.3822 13.2838 13.1697 12.7263 13.7414C12.1679 14.3147 12.268 14.4289 12.3055 15.2697C12.3117 15.3738 12.2966 15.478 12.261 15.5759C12.2255 15.6739 12.1703 15.7636 12.0987 15.8394C12.0272 15.9152 11.941 15.9755 11.8452 16.0167C11.7495 16.0579 11.6463 16.0791 11.5421 16.0789H9.32712C9.22297 16.0787 9.11995 16.0573 9.02434 16.016C8.92874 15.9747 8.84255 15.9143 8.77104 15.8386C8.69954 15.7629 8.64421 15.6734 8.60843 15.5756C8.57265 15.4778 8.55718 15.3737 8.56295 15.2697C8.56295 14.4447 8.63962 14.2531 8.14295 13.7414C7.50962 13.1081 6.01962 12.2139 6.01962 9.75473C6.01581 9.14389 6.13859 8.53889 6.38021 7.97787C6.62183 7.41684 6.97705 6.91195 7.42349 6.49502C7.86992 6.07809 8.39789 5.75817 8.9741 5.55541C9.55032 5.35266 10.1623 5.27147 10.7714 5.31697C11.3806 5.36247 11.9737 5.53367 12.5134 5.81977C13.0531 6.10587 13.5277 6.50068 13.9072 6.9793C14.2868 7.45793 14.5631 8.00998 14.7187 8.60068C14.8742 9.19138 14.9058 9.8079 14.8113 10.4114Z" stroke="#0049F8" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M9.15104 16.0335V17.2935C9.15104 17.6377 9.34271 17.9202 9.57854 17.9202H11.2894C11.5269 17.9202 11.7177 17.6369 11.7177 17.2935V16.0335M9.78438 7.25021C9.29232 7.25043 8.8205 7.44605 8.47264 7.79406C8.12479 8.14208 7.92938 8.61399 7.92938 9.10604M16.5444 9.92354H18.0727M15.006 4.91354L16.0902 3.82938M15.8394 14.1702L16.9235 15.2535M10.4344 1.98438V3.22021M4.80104 3.82854L5.87771 4.91354M3.96771 15.2535L5.04437 14.1702M4.32438 9.92354H2.79688" stroke="#0049F8" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>
			</div>
			<?php
		endif;
	}

	/**
	 * Render question answer explanation.
	 *
	 * @since 3.8.0
	 *
	 * @param object $quiz quiz.
	 * @param array  $quiz_settings quiz settings.
	 * @param object $question question object.
	 *
	 * @return void
	 */
	public function render_question_answer_explanation( $quiz, $quiz_settings, $question ) {
		if ( 'reveal' === $quiz_settings['feedback_mode'] && in_array( $question->question_type, array( 'true_false', 'multiple_choice', 'single_choice' ), true ) && ! empty( $question->answer_explanation ) ) :
			?>
			<div class="tutor-quiz-explanation-wrapper tutor-d-none">
				<div class="tutor-d-flex tutor-gap-1 tutor-align-center tutor-mb-12">
					<svg width="21" height="21" viewBox="0 0 21 21" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M14.8113 10.9114C14.5746 12.8822 13.2838 13.6697 12.7263 14.2414C12.1679 14.8147 12.268 14.9289 12.3055 15.7697C12.3117 15.8738 12.2966 15.978 12.261 16.0759C12.2255 16.1739 12.1703 16.2636 12.0987 16.3394C12.0272 16.4152 11.941 16.4755 11.8452 16.5167C11.7495 16.5579 11.6463 16.5791 11.5421 16.5789H9.32712C9.22297 16.5787 9.11995 16.5573 9.02434 16.516C8.92874 16.4747 8.84255 16.4143 8.77104 16.3386C8.69954 16.2629 8.64421 16.1734 8.60843 16.0756C8.57265 15.9778 8.55718 15.8737 8.56295 15.7697C8.56295 14.9447 8.63962 14.7531 8.14295 14.2414C7.50962 13.6081 6.01962 12.7139 6.01962 10.2547C6.01581 9.64389 6.13859 9.03889 6.38021 8.47787C6.62183 7.91684 6.97705 7.41195 7.42349 6.99502C7.86992 6.57809 8.39789 6.25817 8.9741 6.05541C9.55032 5.85266 10.1623 5.77147 10.7714 5.81697C11.3806 5.86247 11.9737 6.03367 12.5134 6.31977C13.0531 6.60587 13.5277 7.00068 13.9072 7.4793C14.2868 7.95793 14.5631 8.50998 14.7187 9.10068C14.8742 9.69138 14.9058 10.3079 14.8113 10.9114Z" stroke="#4B505C" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
						<path d="M9.15104 16.5335V17.7935C9.15104 18.1377 9.34271 18.4202 9.57854 18.4202H11.2894C11.5269 18.4202 11.7177 18.1369 11.7177 17.7935V16.5335M9.78438 7.75021C9.29232 7.75043 8.8205 7.94605 8.47264 8.29406C8.12479 8.64208 7.92938 9.11399 7.92938 9.60604M16.5444 10.4235H18.0727M15.006 5.41354L16.0902 4.32938M15.8394 14.6702L16.9235 15.7535M10.4344 2.48438V3.72021M4.80104 4.32854L5.87771 5.41354M3.96771 15.7535L5.04437 14.6702M4.32438 10.4235H2.79688" stroke="#4B505C" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
					<div class="tutor-fw-medium"><?php esc_html_e( 'Answer Explanation', 'tutor-pro' ); ?></div>
				</div>
				<div class="tutor-overflow-hidden">							
					<?php echo wp_kses_post( wp_unslash( $question->answer_explanation ) ); ?>
				</div>
			</div>
			<?php
		endif;
	}
}

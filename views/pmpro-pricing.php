<?php
/**
 * PM PRO pricing view
 *
 * @package TutorPro\Addons
 * @subpackage PmPro\Views
 * @author Indigetal WebCraft<support@indigetal.com>
 * @link https://indigetal.com
 * @since 1.3.5
 */

?>
<form class="tutorpress-pmpro-single-course-pricing">
    <h3 class="tutor-fs-5 tutor-fw-bold tutor-mb-16"><?php esc_html_e( 'Pick a plan', 'tutorpress-pmpro' ); ?></h3>

	<?php
		// Tutor Setting for PM Pro.
		$no_commitment = tutor_utils()->get_option( 'pmpro_no_commitment_message' );
		$money_back    = tutor_utils()->get_option( 'pmpro_moneyback_day' );
		$money_back    = ( is_numeric( $money_back ) && $money_back > 0 ) ? $money_back : false;

		$level_page_id  = apply_filters( 'TUTORPRESS_PMPRO_checkout_page_id', pmpro_getOption( 'checkout_page_id' ) );
		$level_page_url = get_the_permalink( $level_page_id );

	if ( $no_commitment ) {
		?>
            <small><?php esc_html_e( $no_commitment, 'tutorpress-pmpro' );//phpcs:ignore ?></small>
			<?php
	}

		$level_count = count( $required_levels );
	?>


	<?php foreach ( $required_levels as $level ) : ?>
		<?php
			$level_id  = 'TUTORPRESS_PMPRO_level_radio_' . $level->id;
			$highlight = get_pmpro_membership_level_meta( $level->id, 'TUTORPRESS_PMPRO_level_highlight', true );
		?>
		<input type="radio" name="TUTORPRESS_PMPRO_level_radio" id="<?php echo esc_attr( $level_id ); ?>" <?php echo ( $highlight || 1 === $level_count ) ? 'checked="checked"' : ''; ?>/>
		<label for="<?php echo esc_attr( $level_id ); ?>" class="<?php echo $highlight ? 'tutorpress-pmpro-level-highlight' : ''; ?>">
			<div class="tutorpress-pmpro-level-header tutor-d-flex tutor-align-center tutor-justify-between">
				<div class="tutor-d-flex tutor-align-center">
					<span class="tutor-form-check-input tutor-form-check-input-radio" area-hidden="true"></span>
					<span class="tutor-fs-5 tutor-fw-medium tutor-ml-12"><?php echo esc_html( $level->name ); ?></span>
				</div>

				<div class="tutor-fs-4">
					<?php
						// Step 3.4b: Dynamic pricing display with sale support
						// PMPro Model: initial_payment (first payment) + billing_amount (recurring)
						// Sale applies to initial_payment only (enrollment window discount)
						$billing_amount  = round( $level->billing_amount );
						
						// Use active_price if available (calculated in pmpro_pricing method)
						// Otherwise fall back to initial_payment for backward compatibility
						$display_initial = isset( $level->active_price ) ? round( $level->active_price ) : round( $level->initial_payment );
						
						// Check if sale is active
						$is_on_sale = ! empty( $level->is_on_sale );
						$regular_initial = ! empty( $level->regular_price_display ) ? round( $level->regular_price_display ) : $display_initial;

						// Build price display HTML
						$billing_text = '';
						
						if ( $level->cycle_period ) {
							// RECURRING SUBSCRIPTION
							// PMPro always has initial_payment (first payment) + billing_amount (recurring)
							// Sale applies to initial payment (enrollment window discount)
							
							if ( $is_on_sale && $regular_initial > $display_initial ) {
								// WITH SALE: Show "~~$100~~ $75 (then $50/Mo)"
								// Show regular initial payment with strikethrough
								$billing_text .= '<span class="tutor-fw-normal" style="text-decoration: line-through; opacity: 0.6; margin-right: 8px;">';
									'left' === $currency_position ? $billing_text .= $currency_symbol : 0;
										$billing_text .= $regular_initial;
									'right' === $currency_position ? $billing_text .= $currency_symbol : 0;
								$billing_text .= '</span>';
								
								// Show discounted initial payment
								$billing_text .= '<span class="tutor-fw-bold">';
									'left' === $currency_position ? $billing_text .= $currency_symbol : 0;
										$billing_text .= $display_initial;
									'right' === $currency_position ? $billing_text .= $currency_symbol : 0;
								$billing_text .= '</span>';
							} else {
								// NO SALE: Show initial payment if different from recurring
								// If initial = recurring, just show recurring. Otherwise show "initial (then recurring)"
								if ( $display_initial != $billing_amount ) {
									// Show "$100 (then $50/Mo)"
									$billing_text .= '<span class="tutor-fw-bold">';
										'left' === $currency_position ? $billing_text .= $currency_symbol : 0;
											$billing_text .= $display_initial;
										'right' === $currency_position ? $billing_text .= $currency_symbol : 0;
									$billing_text .= '</span>';
								} else {
									// Initial = recurring, just show recurring like "$50/Mo"
									$billing_text .= '<span class="tutor-fw-bold">';
										'left' === $currency_position ? $billing_text .= $currency_symbol : 0;
											$billing_text .= $billing_amount;
										'right' === $currency_position ? $billing_text .= $currency_symbol : 0;
									$billing_text .= '</span>';
									$billing_text .= '<span class="tutor-fs-7 tutor-color-muted">/' . substr( $level->cycle_period, 0, 2 ) . '</span>';
								}
							}
							
							// Always show recurring amount in parentheses if initial != recurring
							if ( $display_initial != $billing_amount ) {
								$billing_text .= ' <span class="tutor-fs-6 tutor-color-muted">(';
								$billing_text .= esc_html__( 'then', 'tutorpress-pmpro' ) . ' ';
								$billing_text .= '<span class="tutor-fw-medium">';
									'left' === $currency_position ? $billing_text .= $currency_symbol : 0;
										$billing_text .= $billing_amount;
									'right' === $currency_position ? $billing_text .= $currency_symbol : 0;
								$billing_text .= '/' . substr( $level->cycle_period, 0, 2 );
								$billing_text .= '</span>';
								$billing_text .= ')</span>';
							}
						} else {
							// ONE-TIME PURCHASE
							// Show sale price with strikethrough regular price
							if ( $is_on_sale && $regular_initial > $display_initial ) {
								$billing_text .= '<span class="tutor-fw-normal" style="text-decoration: line-through; opacity: 0.6; margin-right: 8px;">';
									'left' === $currency_position ? $billing_text .= $currency_symbol : 0;
										$billing_text .= $regular_initial;
									'right' === $currency_position ? $billing_text .= $currency_symbol : 0;
								$billing_text .= '</span>';
							}
							
							// Show active price
							$billing_text .= '<span class="tutor-fw-bold">';
								'left' === $currency_position ? $billing_text .= $currency_symbol : 0;
									$billing_text .= $display_initial;
								'right' === $currency_position ? $billing_text .= $currency_symbol : 0;
							$billing_text .= '</span>';
						}

						echo $billing_text;//phpcs:ignore
					?>

				</div>
			</div>

			<div class="tutorpress-pmpro-level-desc tutor-mt-20">
				<div class="tutor-fs-6 tutor-color-muted tutor-mb-20"><?php echo wp_kses_post( $level->description ); ?></div>

                <a href="<?php echo esc_url( $level_page_url ) . '?level=' . esc_attr( $level->id ); ?>" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block">
                    <?php esc_html_e( 'Buy Now', 'tutorpress-pmpro' ); ?>
                </a>

				<?php if ( $money_back ) : ?>
                    <div class="tutor-fs-6 tutor-color-muted tutor-mt-16 tutor-text-center"><?php echo sprintf( esc_html__( '%d-day money-back guarantee', 'tutorpress-pmpro' ), $money_back ); //phpcs:ignore?></div>
				<?php endif; ?>
			</div>
		</label>
	<?php endforeach; ?>
</form>

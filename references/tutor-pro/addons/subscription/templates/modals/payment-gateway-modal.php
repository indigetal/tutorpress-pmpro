<?php
/**
 * Payment gateways modal for subscription.
 *
 * @package TutorPro\Subscription
 * @subpackage Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.4.0
 */

$order_id                = (int) $data['order_id'];
$tutor_toc_page_link     = tutor_utils()->get_toc_page_link();
$tutor_privacy_page_link = tutor_utils()->get_privacy_page_link();
?>
<form class="tutor-modal tutor-modal-scrollable" id="payment_gateway_modal" method="post">
	<?php tutor_nonce_field( true ); ?>
	<input type="hidden" name="tutor_action" value="tutor_pay_incomplete_order">
	<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
	<div class="tutor-modal-overlay"></div>
	<div class="tutor-modal-window">
		<div class="tutor-modal-content tutor-modal-content-white">
			<div class="tutor-modal-body" style="text-align: left;">
				<button class="tutor-iconic-btn tutor-modal-close-o" data-tutor-modal-close>
					<span class="tutor-icon-times" area-hidden="true"></span>
				</button>

				<h5 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-12">
					<?php esc_html_e( 'Billing Address', 'tutor-pro' ); ?>
				</h5>
				<div class="tutor-checkout-billing tutor-mb-16">
					<div class="tutor-billing-fields">
						<?php $is_checkout_page = true; ?>
						<?php require tutor()->path . 'templates/ecommerce/billing-form-fields.php'; ?>
					</div>
				</div>

				<h5 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-12">
					<?php esc_html_e( 'Select Payment Method', 'tutor-pro' ); ?>
				</h5>
				<div class="tutor-checkout-billing tutor-mb-32">
					<div class="tutor-checkout-billing-inner">
						<div class="tutor-checkout-payment-options">
							<?php
							$supported_gateways = tutor_get_subscription_supported_payment_gateways();
							if ( empty( $supported_gateways ) ) {
								?>
								<div class="tutor-alert tutor-warning">
									<?php esc_html_e( 'No payment method found. Please contact the site administrator.', 'tutor-pro' ); ?>
								</div>
								<?php
							} else {
								foreach ( $supported_gateways as $gateway ) {
									list('is_manual' => $is_manual, 'name' => $name, 'label' => $label, 'icon' => $icon) = $gateway;
									if ( $is_manual ) {
										?>
										<label class="tutor-checkout-payment-item tutor-d-flex" data-payment-method="<?php echo esc_attr( $name ); ?>" data-payment-type="manual" data-payment-details="<?php echo esc_attr( $gateway['additional_details'] ?? '' ); ?>" data-payment-instruction="<?php echo esc_attr( $gateway['payment_instructions'] ?? '' ); ?>">
											<input type="radio" value="<?php echo esc_attr( $name ); ?>" name="payment_method" class="tutor-form-check-input" required>
											<div class="tutor-payment-item-content">
												<?php if ( ! empty( $icon ) ) : ?>
													<img src="<?php echo esc_url( $icon ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
												<?php endif; ?>
												<?php echo esc_html( $label ); ?>
											</div>
										</label>
										<?php
									} else {
										?>
										<label class="tutor-checkout-payment-item" data-payment-type="automate">
											<input type="radio" name="payment_method" value="<?php echo esc_attr( $name ); ?>" class="tutor-form-check-input" <?php echo count( $supported_gateways ) === 1 ? 'checked' : ''; ?> required>
											<div class="tutor-payment-item-content">
												<?php if ( ! empty( $icon ) ) : ?>
													<img src="<?php echo esc_url( $icon ); ?>" alt="<?php echo esc_attr( $name ); ?>" />
												<?php endif; ?>
												<?php echo esc_html( $label ); ?>
											</div>
										</label>
										<?php
									}
								}
							}
							?>
						</div>
					</div>
				</div>
				<div class="modal-footer">
					<?php if ( null !== $tutor_toc_page_link ) : ?>
						<div class="tutor-mb-16">
							<div class="tutor-form-check tutor-d-flex">
								<input type="checkbox" id="tutor_checkout_agree_to_terms" name="agree_to_terms" class="tutor-form-check-input" required>
								<label for="tutor_checkout_agree_to_terms">
									<span class="tutor-color-subdued tutor-fw-normal">
										<?php esc_html_e( 'I agree with the website\'s', 'tutor-pro' ); ?>
										<a target="_blank" href="<?php echo esc_url( $tutor_toc_page_link ); ?>" class="tutor-color-primary"><?php esc_html_e( 'Terms of Use', 'tutor-pro' ); ?></a>
										<?php if ( null !== $tutor_privacy_page_link ) : ?>
											<?php esc_html_e( 'and', 'tutor-pro' ); ?>
											<a target="_blank" href="<?php echo esc_url( $tutor_privacy_page_link ); ?>" class="tutor-color-primary"><?php esc_html_e( 'Privacy Policy', 'tutor-pro' ); ?></a>
										<?php endif; ?>
									</span>
								</label>
							</div>
						</div>
					<?php endif; ?>
					<button type="submit" data-action="next" class="tutor-btn tutor-d-flex tutor-align-center tutor-justify-center tutor-w-100 tutor-btn-primary">
						<?php esc_html_e( 'Pay Now', 'tutor-pro' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>
</form>

<?php
/**
 * Gift this course modal template
 *
 * @package Tutor\Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.8.0
 */

defined( 'ABSPATH' ) || exit;

$has_video = apply_filters( 'tutor_course_has_video', tutor_utils()->has_video_in_single(), $course_id );

?>

<div id="tutor-gift-this-course-modal" class="tutor-modal tutor-modal-scrollable">
	<span class="tutor-modal-overlay"></span>
	<div class="tutor-modal-window">
		<div class="tutor-modal-content">
			<div class="tutor-modal-body">
				<div class="tutor-gift-modal-title">
					<div class="tutor-gift-modal-title-left">
						<img class="tutor-gift-modal-title-image" src="<?php echo esc_url( tutor_pro()->url . 'assets/images/gift.svg' ); ?>" alt="<?php esc_html_e( 'Gift box image', 'tutor-pro' ); ?>" />
						<?php esc_html_e( 'Gift this Course', 'tutor-pro' ); ?>
					</div>
					<button class="tutor-iconic-btn tutor-modal-close-o" data-tutor-modal-close>
						<span class="tutor-icon-times" area-hidden="true"></span>
					</button>
				</div>
				<div class="tutor-row tutor-g-4">
					<div class="tutor-col-md-7">
						<div class="tutor-gift-modal-course">
							<svg class="tutor-gift-modal-course-gift-wrap" width="72" height="36" viewBox="0 0 72 36" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M36 24.4C36 18.4592 33.9104 12.7616 30.1909 8.56081C26.4715 4.35999 21.4268 2 16.1667 2C12.4094 2 8.80609 3.68571 6.14932 6.68629C3.49256 9.68687 2 13.7565 2 18C2 22.2435 3.49256 26.3131 6.14932 29.3137C8.80609 32.3143 12.4094 34 16.1667 34H36M36 24.4V34M36 24.4C36 18.4592 38.0896 12.7616 41.809 8.56081C45.5285 4.35999 50.5732 2 55.8333 2C59.5906 2 63.1939 3.68571 65.8507 6.68629C68.5074 9.68687 70 13.7565 70 18C70 20.1012 69.6336 22.1817 68.9216 24.1229C68.2097 26.0641 67.1662 27.828 65.8507 29.3137C64.5352 30.7994 62.9735 31.978 61.2547 32.7821C59.5359 33.5861 57.6937 34 55.8333 34H36" stroke="#E03AD3" stroke-width="3" stroke-linejoin="round"/>
							</svg>

							<?php $has_video ? tutor_course_video() : get_tutor_course_thumbnail(); ?>
							<h6 class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mt-16 tutor-pr-64">
								<?php echo esc_html( get_the_title( $course_id ) ); ?>
							</h6> 
						</div>
					</div>
					<div class="tutor-col-md-5">
						<form id="tutor-gift-this-course-form" method="post" class="tutor-bg-white tutor-divider tutor-p-16 tutor-radius-12">
							<input type="hidden" name="course_id" value="<?php the_ID(); ?>">
							<?php tutor_nonce_field( true ); ?>
							<div class="tutor-form-group">
								<label class="tutor-form-label" for="recipient_name"><?php esc_html_e( 'Recipient’s name', 'tutor-pro' ); ?></label>
								<input class="tutor-form-control" id="recipient_name" name="recipient_name" required />
							</div>
							<div class="tutor-form-group">
								<label class="tutor-form-label" for="recipient_email"><?php esc_html_e( 'Recipient’s email', 'tutor-pro' ); ?></label>
								<input class="tutor-form-control" id="recipient_email" type="email" name="recipient_email" required />
							</div>
							<div class="tutor-form-group">
								<label class="tutor-form-label"><?php esc_html_e( 'When do you want to send this gift? ', 'tutor-pro' ); ?></label>
								<div class="tutor-gift-modal-date-time-wrapper">
									<div class="tutor-v2-date-picker" data-prevent_redirect="1" data-input_name="gift_date" data-disable_past_date="1" data-input_value="<?php echo esc_attr( gmdate( 'd-m-Y' ) ); ?>">
										<div class="tutor-form-wrap">
											<span class="tutor-form-icon tutor-form-icon-reverse">
												<span class="tutor-icon-calender-line" aria-hidden="true"></span>
											</span>
											<input class="tutor-form-control" placeholder="<?php esc_attr_e( 'Loading...', 'tutor-pro' ); ?>">
										</div>
									</div>
									<select class="tutor-form-select" name="gift_time">
										<?php
										for ( $hour = 0; $hour < 24; $hour++ ) {
											for ( $minute = 0; $minute < 60; $minute += 30 ) {
												$time_value = sprintf( '%02d:%02d', $hour, $minute );
												$time_label = gmdate( 'h:i A', strtotime( $time_value ) );
												echo '<option value="' . esc_attr( $time_value ) . '">' . esc_html( $time_label ) . '</option>';
											}
										}
										?>
									</select>
								</div>
							</div>
							<div class="tutor-form-group">
								<label class="tutor-form-label" for="message"><?php esc_html_e( 'Your message (optional)', 'tutor-pro' ); ?></label>
								<textarea class="tutor-form-control" id="message" name="message" rows="2"></textarea>
							</div>
							<div class="tutor-form-group">
								<label class="tutor-form-label tutor-d-flex tutor-gap-1" for="notify_me">
									<input type="checkbox" class="tutor-form-check-input" id="notify_me" name="notify_me" />
									<?php esc_html_e( 'Notify me when recipient opens the gift', 'tutor-pro' ); ?>
								</label>
							</div>
							<button type="submit" class="tutor-btn tutor-btn-primary tutor-btn-lg tutor-btn-block tutor-mt-32">
								<?php esc_html_e( 'Proceed to Checkout', 'tutor-pro' ); ?>
							</button>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>


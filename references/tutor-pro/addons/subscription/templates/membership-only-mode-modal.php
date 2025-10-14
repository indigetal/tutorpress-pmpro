<?php
/**
 * Membership only mode consent modal content
 *
 * @package TutorPro\Subscription
 * @subpackage Template
 * @since 3.3.0
 */

use TutorPro\Subscription\Utils;

?>
<div id="tutor-membership-only-mode-consent-modal" class="tutor-modal">
	<div class="tutor-modal-overlay"></div>
	<div class="tutor-modal-window" style="max-width: 490px;">
		<div class="tutor-modal-content tutor-modal-content-white">
			<button class="tutor-iconic-btn tutor-modal-close-o" data-tutor-modal-close>
				<span class="tutor-icon-times" area-hidden="true"></span>
			</button>

			<div class="tutor-modal-body tutor-text-center">
				<div>
					<img class="tutor-d-inline-block" src="<?php echo esc_attr( Utils::asset_url( 'images/membership-only-consent.svg' ) ); ?>" />
				</div>

				<div class="tutor-fs-5 tutor-fw-medium tutor-color-black tutor-mb-8"><?php esc_html_e( 'Enable Membership-Only Mode?', 'tutor-pro' ); ?></div>
				<div class="tutor-fs-6 tutor-mb-16 tutor-color-subdued">
					<?php esc_html_e( 'Selecting Membership Mode lets you create only site-wide and category-specific plans. Here\'s what to expect:', 'tutor-pro' ); ?>
				</div>

				<ul class="tutor-fs-6 tutor-d-flex tutor-flex-column tutor-gap-4px tutor-px-12">
					<li class="tutor-d-flex tutor-gap-12px tutor-text-left">
						<span class="tutor-pt-4">
							<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M7.95801 15.0013L3.20801 10.2513L4.39551 9.0638L7.95801 12.6263L15.6038 4.98047L16.7913 6.16797L7.95801 15.0013Z" fill="#239C46"/>
							</svg>
						</span>
						<?php esc_html_e( 'Students enrolled in a single course or a bundle with a single course subscription will remain unaffected.', 'tutor-pro' ); ?>
					</li>
					<li class="tutor-d-flex tutor-gap-12px tutor-text-left">
						<span class="tutor-pt-4">
							<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M7.95801 15.0013L3.20801 10.2513L4.39551 9.0638L7.95801 12.6263L15.6038 4.98047L16.7913 6.16797L7.95801 15.0013Z" fill="#239C46"/>
							</svg>
						</span>
						<?php esc_html_e( 'The entire site will transition to membership plans.', 'tutor-pro' ); ?>
					</li>
					<li class="tutor-d-flex tutor-gap-12px tutor-text-left">
						<span class="tutor-pt-4">
							<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M11.167 10.0013L15.8337 14.668L14.667 15.8346L10.0003 11.168L5.33366 15.8346L4.16699 14.668L8.83366 10.0013L4.16699 5.33464L5.33366 4.16797L10.0003 8.83464L14.667 4.16797L15.8337 5.33464L11.167 10.0013Z" fill="#F44337"/>
							</svg>
						</span>
						<?php esc_html_e( 'Single course pricing will no longer be offered.', 'tutor-pro' ); ?>
					</li>
					<li class="tutor-d-flex tutor-gap-12px tutor-text-left">
						<span class="tutor-pt-4">
							<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
								<path d="M11.167 10.0013L15.8337 14.668L14.667 15.8346L10.0003 11.168L5.33366 15.8346L4.16699 14.668L8.83366 10.0013L4.16699 5.33464L5.33366 4.16797L10.0003 8.83464L14.667 4.16797L15.8337 5.33464L11.167 10.0013Z" fill="#F44337"/>
							</svg>
						</span>
						<?php esc_html_e( 'Subscriptions for individual courses or bundles will no longer be available.', 'tutor-pro' ); ?>
					</li>
				</ul>

				<div class="tutor-d-flex tutor-justify-end tutor-gap-1 tutor-mt-32">
					<button data-tutor-modal-close class="tutor-btn tutor-color-secondary">
						<?php esc_html_e( 'Cancel', 'tutor-pro' ); ?>
					</button>
					<button type="button" class="tutor-btn tutor-btn-primary tutor-membership-only-mode-consent-confirm">
						<?php esc_html_e( 'Yes, Proceed', 'tutor-pro' ); ?>
					</button>
				</div>
			</div>
		</div>
	</div>
</div>


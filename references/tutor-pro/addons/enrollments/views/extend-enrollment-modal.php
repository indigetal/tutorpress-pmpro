<?php
/**
 * Enrollment extend period modal template
 *
 * @author themeum
 * @link https://themeum.com
 * @package TutorLMS/Templates
 *
 * @since 3.3.0
 */

?>

<div id="tutor-enrollment-extend-modal" class="tutor-modal">
	<span class="tutor-modal-overlay"></span>
	<div class="tutor-modal-window">
		<div class="tutor-modal-content tutor-modal-content-white">
			<div class="tutor-d-flex tutor-align-center tutor-justify-between tutor-divider-bottom tutor-px-16 tutor-py-12">
				<div class="tutor-fs-6 tutor-fw-medium">
					<?php echo esc_html_e( 'Extend Enrollment Period', 'tutor-pro' ); ?>
				</div>
				<button class="tutor-iconic-btn" data-tutor-modal-close>
					<span class="tutor-icon-times" area-hidden="true"></span>
				</button>
			</div>

			<div class="tutor-modal-body">
				<div class="tutor-extend-modal-course"></div>

				<div class="tutor-d-flex tutor-flex-column tutor-flex-sm-row tutor-align-start tutor-justify-between tutor-gap-3 tutor-pl-20 tutor-pr-40">
					<div class="tutor-extend-modal-user"></div>
					<div class="tutor-extend-modal-enroll-expire"></div>
				</div>
			</div>

			<div class="tutor-d-flex tutor-justify-end tutor-px-16 tutor-py-12 tutor-divider-top">
				<button class="tutor-btn tutor-btn-text tutor-btn-sm tutor-color-subdued" data-tutor-modal-close>
					<?php esc_html_e( 'Cancel', 'tutor-pro' ); ?>
				</button>
				<button id="tutor-enrollment-extend-submit-btn" class="tutor-btn tutor-btn-primary tutor-btn-sm">
					<?php esc_html_e( 'Extend', 'tutor-pro' ); ?>
				</button>
			</div>
		</div>
	</div>
</div>

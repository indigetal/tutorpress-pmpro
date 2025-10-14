<?php
/**
 * Membership settings block template.
 *
 * @package TutorPro\Addons
 * @subpackage Subscriptions\Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.2.0
 */

?>

<div class="tutor-option-single-item tutor-mb-32 <?php echo esc_attr( $blocks['slug'] ); ?>">
	<?php if ( isset( $blocks['label'] ) ) : ?>
		<div class="tutor-option-group-title tutor-mb-16">
			<div class="tutor-fs-6 tutor-color-muted"><?php echo esc_attr( $blocks['label'] ); ?></div>
		</div>
	<?php endif; ?>
	<div id="tutor-membership-settings"></div>
</div>

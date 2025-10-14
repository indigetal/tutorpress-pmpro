<?php
/**
 * Membership price for course loop
 *
 * @package TutorPro\Addons
 * @subpackage Subscription\Templates
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.3.0
 */

use TutorPro\Subscription\Settings;

?>
<div class="tutor-d-flex tutor-align-center">
	<a href="<?php echo esc_url( Settings::get_pricing_page_url() ); ?>" class="tutor-btn tutor-btn-outline-primary tutor-btn-md tutor-btn-block"><?php esc_html_e( 'View Pricing', 'tutor-pro' ); ?></a>
</div>
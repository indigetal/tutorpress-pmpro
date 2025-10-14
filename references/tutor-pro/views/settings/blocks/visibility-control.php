<?php
/**
 * Visibility control block
 *
 * @package TutorPro\Views
 * @subpackage TutorPro\Settings
 * @author Themeum <support@themeum.com>
 * @link https://themeum.com
 * @since 3.5.0
 */

$slug     = $blocks['slug'] ?? '';
$sections = $blocks['sections'] ?? array();
?>
<div class="tutor-option-single-item tutor-mb-32">
<?php if ( isset( $blocks['label'] ) ) : ?>
	<div class="tutor-option-group-title tutor-mb-16">
		<div class="tutor-fs-6 tutor-color-muted"><?php echo esc_attr( $blocks['label'] ); ?></div>
	</div>
<?php endif; ?>
<?php if ( ! empty( $sections ) ) : ?>
	<div class="item-wrapper">
		<div class="tutor-d-flex tutor-flex-column tutor-gap-2">
			<?php
			foreach ( $sections as $section ) :
				$fields = $section['fields'] ?? array();
				$roles  = $section['roles'] ?? array();
				?>
				<div>
					<div class="tutor-d-flex tutor-align-center tutor-justify-between tutor-mb-12">
						<div class="tutor-fs-6 tutor-color-muted">
						<?php echo esc_html( $section['label'] ); ?>
						</div>
						<div class="tutor-visibility-role-head tutor-d-flex tutor-justify-center">
						<?php
						foreach ( $roles as $key => $user_role ) {
							?>
							<div class="tutor-fs-6 tutor-color-muted tutor-text-center" style="width: 120px">
								<?php echo esc_html( $user_role ); ?>
							</div>
							<?php
						}
						?>
						</div>
					</div>
					<div class="tutor-border tutor-radius-6 tutor-p-0 tutor-pl-16 tutor-overflow-hidden">
						<div class="tutor-d-flex tutor-flex-column">
							<?php foreach ( $fields as $key => $field_item ) : ?>
								<div class="tutor-visibility-control-row tutor-d-flex tutor-justify-between tutor-align-center">
									<div class="tutor-fs-6 tutor-fw-medium tutor-flex-grow-1">
										<?php echo esc_html( $field_item['label'] ); ?>
									</div>
										<?php foreach ( $roles as $role_key => $user_role ) : ?>
											<div class="tutor-visibility-control-role tutor-d-flex tutor-justify-center tutor-align-center tutor-h-100" style="background-color: <?php echo ( 'admin' === $role_key ) ? '#f7f7f7' : '#ffffff'; ?>;">
												<?php 
												$field = $field_item;
												$field['key'] = $field_item['key'] . '_' . $role_key;
												include tutor()->path . 'views/options/field-types/checkbox-without-label.php';
												?>
											</div>
										<?php endforeach; ?>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>
<?php do_action( 'tutor_after_block_single_item', $slug ); ?>
</div>

<?php
/**
 * Enrollment List Template.
 *
 * @package Enrollment List
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use TUTOR\Input;
use Tutor\Models\CourseModel;
use TUTOR_ENROLLMENTS\Enrollment_Expiry;
use TUTOR_ENROLLMENTS\Enrollments_List;

$enrollments = new Enrollments_List();

/**
 * Short able params
 */
$course_id = Input::get( 'course-id', '' );
$order     = Input::get( 'order', 'DESC' );
$date      = Input::get( 'date', '' );
$search    = Input::get( 'search', '' );

/**
 * Determine active tab
 */
$active_tab = Input::get( 'data', 'all' );

/**
 * Pagination data
 */
$paged    = Input::get( 'paged', 1, Input::TYPE_INT );
$per_page = tutor_utils()->get_option( 'pagination_per_page' );
$offset   = ( $per_page * abs( $paged ) ) - $per_page;

$enrollments_list = tutor_utils()->get_enrolments( $active_tab, $offset, $per_page, $search, $course_id, $date, $order );
$total            = tutor_utils()->get_total_enrolments( $active_tab, $search, $course_id, $date );

/**
 * Navbar data to make nav menu
 */
$navbar_data = array(
	'page_title'   => $enrollments->page_title,
	'add_button'   => true,
	'button_title' => __( 'Enroll Students', 'tutor-pro' ),
	'button_url'   => esc_url( admin_url( 'admin.php?page=enrollments&action=add_new' ) ),
);

$filters = array(
	'bulk_action'  => $enrollments->bulk_action,
	'bulk_actions' => $enrollments->prpare_bulk_actions(),
	'ajax_action'  => 'tutor_enrollment_bulk_action',
	'filters'      => array(
		array(
			'label'      => __( 'Courses', 'tutor-pro' ),
			'field_type' => 'select',
			'field_name' => 'course-id',
			'options'    => CourseModel::get_course_dropdown_options(),
			'searchable' => true,
			'value'      => Input::get( 'course-id', '' ),
		),
		array(
			'label'      => __( 'Status', 'tutor-pro' ),
			'field_type' => 'select',
			'field_name' => 'data',
			'options'    => $enrollments->tabs_key_value( $course_id, $date, $search ),
			'searchable' => false,
			'value'      => Input::get( 'data', '' ),
		),
		array(
			'label'      => __( 'Date', 'tutor-pro' ),
			'field_type' => 'date',
			'field_name' => 'date',
			'show_label' => true,
			'value'      => Input::get( 'date', '' ),
		),
	),
);

if ( 'cancelled' === $active_tab ) {
	$filters['bulk_actions'][] = array(
		'value'  => 'delete',
		'option' => __( 'Delete Permanently', 'tutor-pro' ),
	);
}
?>
<div class="tutor-admin-wrap">
	<?php
		/**
		 * Load Templates with data.
		 */
		$navbar_template  = tutor()->path . 'views/elements/list-navbar.php';
		$filters_template = tutor()->path . 'views/elements/list-filters.php';
		tutor_load_template_from_custom_path( $navbar_template, $navbar_data );
		tutor_load_template_from_custom_path( $filters_template, $filters );
	?>
	<div class="tutor-admin-container tutor-admin-container-lg tutor-mt-16">
		<?php if ( is_array( $enrollments_list ) && count( $enrollments_list ) ) : ?>
			<div class="tutor-table-responsive tutor-dashboard-list-table">
				<table class="tutor-table tutor-table-with-checkbox tutor-table-middle">
					<thead>
						<tr>
							<th style="width: 5%;">
								<div class="tutor-d-flex">
									<input type="checkbox" id="tutor-bulk-checkbox-all" class="tutor-form-check-input">
								</div>
							</th>
							<th width="15%">
								<?php esc_html_e( 'Date', 'tutor-pro' ); ?>
							</th>
							<th>
								<?php esc_html_e( 'Course', 'tutor-pro' ); ?>
							</th>
							<th class="tutor-table-rows-sorting" width="10%">
								<?php esc_html_e( 'Name', 'tutor-pro' ); ?>
								<span class="a-to-z-sort-icon tutor-icon-ordering-a-z"></span>
							</th>
							<th>
								<?php esc_html_e( 'Status', 'tutor-pro' ); ?>
							</th>
						</tr>
					</thead>

					<tbody>
						<?php
						foreach ( $enrollments_list as $list ) :
							$alert = strtolower( apply_filters( 'tutor_enrollment_list_status', $list->status, $list ) );
							if ( in_array( $alert, array( 'cancel', 'cancelled', 'canceled', 'expired' ), true ) ) {
								$alert = 'danger';
							} elseif ( 'completed' === $alert ) {
								$alert = 'success';
							} else {
								$alert = 'default';
							}

							$enrollment_status         = apply_filters( 'tutor_enrollment_list_status', tutor_utils()->translate_dynamic_text( $list->status ), $list );
							$enrollment_expiry_enabled = (bool) get_tutor_option( 'enrollment_expiry_enabled' );
							$enrollment_expiry         = get_tutor_course_settings( $list->course_id, 'enrollment_expiry' );
							$expiry_date               = gmdate( 'Y-m-d H:i:s', strtotime( $list->enrol_date . " +{$enrollment_expiry} days" ) );

							$custom_expiry_date = Enrollment_Expiry::get_custom_expiry_date( $list->course_id, $list->student_id );
							if ( $custom_expiry_date ) {
								$expiry_date = gmdate( 'Y-m-d H:i:s', $custom_expiry_date );
							}

							$can_extend_enrollment_expiry = false;
							if ( $enrollment_expiry_enabled && $enrollment_expiry ) {
								$can_extend_enrollment_expiry = apply_filters( 'tutor_can_extend_enrollment_expiry', true, $list );
							}

							if ( $can_extend_enrollment_expiry ) {
								$modal_data = array(
									'enrol_id'             => $list->enrol_id,
									'course_title'         => $list->course_title,
									'course_thumb'         => get_tutor_course_thumbnail_src( 'post-thumbnail', $list->course_id ),
									'course_url'           => get_permalink( $list->course_id ),
									'user_name'            => $list->display_name,
									'user_email'           => $list->user_email,
									'user_avatar'          => get_avatar_url( $list->student_id, 32 ),
									'is_expired'           => strtotime( $expiry_date ) < strtotime( gmdate( 'Y-m-d H:i:s' ) ),
									'expiry_date'          => tutor_get_formated_date( 'd-m-Y', $expiry_date ),
									'expiry_date_readable' => tutor_i18n_get_formated_date( $expiry_date, get_option( 'date_format' ) ),

								);
							}
							?>
							<tr>
								<td>
									<div class="tutor-d-flex">
										<input type="checkbox" class="tutor-form-check-input tutor-bulk-checkbox" name="tutor-bulk-checkbox-all" value="<?php echo esc_attr( $list->enrol_id ); ?>">
									</div>
								</td>
								<td>
									<div class="tutor-d-flex tutor-align-center tutor-gap-2">
										<div class="tutor-fw-normal">
											<div class="tutor-fs-7 tutor-mb-4 tutor-text-nowrap">
												<?php echo esc_html( tutor_get_formated_date( get_option( 'date_format' ), $list->enrol_date ) ); ?>
											</div>
											<div class="tutor-d-flex tutor-align-center tutor-gap-1">
												<span class="tutor-fs-8 tutor-color-muted"><?php echo esc_html( tutor_get_formated_date( get_option( 'time_format' ), $list->enrol_date ) ); ?></span>
												<?php if ( $can_extend_enrollment_expiry ) : ?>
												<button class="tutor-btn tutor-btn-secondary tutor-btn-sm tutor-extend-enrollment" data-modal-data="<?php echo esc_attr( wp_json_encode( $modal_data ) ); ?>">
													<?php esc_html_e( 'Extend', 'tutor-pro' ); ?>
												</button>
												<?php endif; ?>
											</div>
										</div>
									</div>
								</td>
								<td>
									<div class="tutor-d-flex tutor-align-center tutor-gap-2 tutor-text-nowrap">
									<?php echo esc_html( $list->course_title ); ?>
										<a href="<?php echo esc_url( get_permalink( $list->course_id ) ); ?>" class="tutor-iconic-btn" target="_blank">
											<span class="tutor-icon-external-link" area-hidden="true"></span>
										</a>
									</div>
									<?php do_action( 'tutor_enrollment_row_course_info_meta', $list ); ?>
								</td>
								<td>
								<?php echo esc_html( $list->display_name ); ?>
									<div class="tutor-fs-8 tutor-color-muted tutor-mt-4 tutor-text-nowrap">
									<?php echo esc_html( $list->user_email ); ?>
									</div>
								</td>
								<td>
									<span class="tutor-badge-label label-<?php echo esc_attr( $alert ); ?> tutor-m-4">
									<?php echo esc_html( 'completed' === strtolower( $enrollment_status ) ? __( 'Approved', 'tutor-pro' ) : $enrollment_status ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php else : ?>
			<?php tutils()->render_list_empty_state(); ?>
		<?php endif; ?>

		<div class="tutor-admin-page-pagination-wrapper tutor-mt-32">
			<?php
				/**
				 * Prepare pagination data & load template
				 */
			if ( $total > $per_page ) {
				$pagination_data     = array(
					'total_items' => $total,
					'per_page'    => $per_page,
					'paged'       => $paged,
				);
				$pagination_template = tutor()->path . 'views/elements/pagination.php';
				tutor_load_template_from_custom_path( $pagination_template, $pagination_data );
			}
			?>
		</div>
	</div>
</div>

<?php require TUTOR_ENROLLMENTS()->path . '/views/enrollment-success-modal.php'; ?>
<?php require TUTOR_ENROLLMENTS()->path . '/views/extend-enrollment-modal.php'; ?>

<?php
/**
 * View: tela de detalhes de uma inscrição.
 *
 * Variáveis disponíveis (definidas em Admin::render_detail_page):
 *
 * @var array $registration Dados completos da inscrição.
 * @var array $history      Histórico de alterações.
 * @var array $attendance_history Histórico de presença desta inscrição (Attendance::get_history_for_registration()).
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses      = mcr_get_statuses();
$interests     = mcr_interests_to_array( $registration['interests'] );
$list_url      = admin_url( 'admin.php?page=' . \Music_Club_Registrations\Admin::LIST_SLUG );
$export_nonce  = wp_create_nonce( 'mcr_export' );
$export_base   = admin_url( 'admin-post.php' );
$export_url    = add_query_arg(
	array(
		'action'           => 'mcr_export_csv',
		'scope'            => 'selected',
		'registration_ids' => array( absint( $registration['id'] ) ),
		'_wpnonce'         => $export_nonce,
	),
	$export_base
);
$delete_url    = wp_nonce_url(
	add_query_arg(
		array(
			'page'   => \Music_Club_Registrations\Admin::LIST_SLUG,
			'action' => 'delete',
			'id'     => absint( $registration['id'] ),
		),
		admin_url( 'admin.php' )
	),
	'mcr_delete_registration_' . absint( $registration['id'] )
);
?>
<div class="wrap mcr-wrap mcr-detail-wrap">
	<h1>
		<?php
		printf(
			/* translators: %s: registration number, e.g. MC-000001 */
			esc_html__( 'Registration %s', 'music-club-registrations' ),
			esc_html( $registration['registration_number'] )
		);
		?>
	</h1>

	<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to all registrations', 'music-club-registrations' ); ?></a></p>

	<?php if ( ! empty( $_GET['updated'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Registration updated successfully.', 'music-club-registrations' ); ?></p></div>
	<?php endif; ?>

	<div class="mcr-detail-columns">
		<div class="mcr-detail-main">
			<form method="post">
				<?php wp_nonce_field( 'mcr_save_detail', 'mcr_detail_nonce' ); ?>
				<input type="hidden" name="id" value="<?php echo esc_attr( $registration['id'] ); ?>" />

				<table class="form-table mcr-detail-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row" colspan="2"><h2 class="mcr-detail-section-title"><?php esc_html_e( "Child's Information", 'music-club-registrations' ); ?></h2></th>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-child-name"><?php esc_html_e( "Child's Name", 'music-club-registrations' ); ?></label></th>
							<td><input type="text" name="child_name" id="mcr-child-name" value="<?php echo esc_attr( $registration['child_name'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-child-age"><?php esc_html_e( "Child's Age", 'music-club-registrations' ); ?></label></th>
							<td>
								<input type="number" name="child_age" id="mcr-child-age" min="3" max="13" value="<?php echo esc_attr( $registration['child_age'] ?? '' ); ?>" class="small-text" />
								<p class="description"><?php esc_html_e( 'Leave blank if not applicable. Only values between 3 and 13 are saved.', 'music-club-registrations' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-child-class"><?php esc_html_e( 'Class', 'music-club-registrations' ); ?></label></th>
							<td><input type="text" name="child_class" id="mcr-child-class" value="<?php echo esc_attr( $registration['child_class'] ); ?>" class="regular-text" /></td>
						</tr>

						<tr>
							<th scope="row" colspan="2"><h2 class="mcr-detail-section-title"><?php esc_html_e( 'Contact Information', 'music-club-registrations' ); ?></h2></th>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-parent-name"><?php esc_html_e( 'Parent/Guardian Name', 'music-club-registrations' ); ?></label></th>
							<td><input type="text" name="parent_name" id="mcr-parent-name" value="<?php echo esc_attr( $registration['parent_name'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-second-parent-name"><?php esc_html_e( 'Parent/Guardian Name (Additional)', 'music-club-registrations' ); ?></label></th>
							<td><input type="text" name="second_parent_name" id="mcr-second-parent-name" value="<?php echo esc_attr( $registration['second_parent_name'] ?? '' ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-parent-email"><?php esc_html_e( 'Primary Email', 'music-club-registrations' ); ?></label></th>
							<td><input type="email" name="parent_email" id="mcr-parent-email" value="<?php echo esc_attr( $registration['parent_email'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-phone"><?php esc_html_e( 'Primary Phone', 'music-club-registrations' ); ?></label></th>
							<td><input type="text" name="phone" id="mcr-phone" value="<?php echo esc_attr( $registration['phone'] ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-second-parent-email"><?php esc_html_e( 'Additional Email', 'music-club-registrations' ); ?></label></th>
							<td><input type="email" name="second_parent_email" id="mcr-second-parent-email" value="<?php echo esc_attr( $registration['second_parent_email'] ?? '' ); ?>" class="regular-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-second-parent-phone"><?php esc_html_e( 'Additional Phone', 'music-club-registrations' ); ?></label></th>
							<td><input type="text" name="second_parent_phone" id="mcr-second-parent-phone" value="<?php echo esc_attr( $registration['second_parent_phone'] ?? '' ); ?>" class="regular-text" /></td>
						</tr>

						<tr>
							<th scope="row" colspan="2"><h2 class="mcr-detail-section-title"><?php esc_html_e( 'Programs / Interests', 'music-club-registrations' ); ?></h2></th>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-interests"><?php esc_html_e( 'Selected Programs', 'music-club-registrations' ); ?></label></th>
							<td>
								<input type="text" name="interests" id="mcr-interests" value="<?php echo esc_attr( implode( ', ', $interests ) ); ?>" class="large-text" />
								<p class="description"><?php esc_html_e( 'Separate multiple programs with a comma.', 'music-club-registrations' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-additional-message"><?php esc_html_e( 'Additional Message', 'music-club-registrations' ); ?></label></th>
							<td><textarea name="additional_message" id="mcr-additional-message" rows="4" class="large-text"><?php echo esc_textarea( $registration['additional_message'] ); ?></textarea></td>
						</tr>

						<tr>
							<th scope="row" colspan="2"><h2 class="mcr-detail-section-title"><?php esc_html_e( 'Payment Information', 'music-club-registrations' ); ?></h2></th>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-total-amount"><?php esc_html_e( 'Total Amount', 'music-club-registrations' ); ?></label></th>
							<td><input type="text" name="total_amount" id="mcr-total-amount" value="<?php echo esc_attr( $registration['total_amount'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. €280', 'music-club-registrations' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-payment-status"><?php esc_html_e( 'Payment Status', 'music-club-registrations' ); ?></label></th>
							<td>
								<select name="payment_status" id="mcr-payment-status">
									<option value="unpaid" <?php selected( $registration['payment_status'] ?? 'unpaid', 'unpaid' ); ?>><?php esc_html_e( 'Unpaid', 'music-club-registrations' ); ?></option>
									<option value="paid" <?php selected( $registration['payment_status'] ?? 'unpaid', 'paid' ); ?>><?php esc_html_e( 'Paid', 'music-club-registrations' ); ?></option>
								</select>
								<?php echo mcr_render_payment_status_badge( $registration['payment_status'] ?? 'unpaid' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside the helper. ?>
								<?php if ( 'paid' === ( $registration['payment_status'] ?? '' ) && ! empty( $registration['payment_confirmed_at'] ) ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: date/time payment was confirmed */
											esc_html__( 'Confirmed on %s', 'music-club-registrations' ),
											esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $registration['payment_confirmed_at'] ) )
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>

						<tr>
							<th scope="row" colspan="2"><h2 class="mcr-detail-section-title"><?php esc_html_e( 'Permissions', 'music-club-registrations' ); ?></h2></th>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-photo-permission"><?php esc_html_e( 'Photography Permission', 'music-club-registrations' ); ?></label></th>
							<td>
								<select name="photo_permission" id="mcr-photo-permission">
									<option value=""><?php esc_html_e( '— Not set —', 'music-club-registrations' ); ?></option>
									<option value="Yes" <?php selected( $registration['photo_permission'] ?? '', 'Yes' ); ?>><?php esc_html_e( 'Yes', 'music-club-registrations' ); ?></option>
									<option value="No" <?php selected( $registration['photo_permission'] ?? '', 'No' ); ?>><?php esc_html_e( 'No', 'music-club-registrations' ); ?></option>
								</select>
							</td>
						</tr>

						<tr>
							<th scope="row" colspan="2"><h2 class="mcr-detail-section-title"><?php esc_html_e( 'Registration Details', 'music-club-registrations' ); ?></h2></th>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Registration ID', 'music-club-registrations' ); ?></th>
							<td><?php echo esc_html( $registration['registration_number'] ); ?></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Created At', 'music-club-registrations' ); ?></th>
							<td>
								<?php
								echo esc_html(
									mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $registration['created_at'] )
								);
								?>
							</td>
						</tr>
						<?php if ( ! empty( $registration['updated_at'] ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Updated At', 'music-club-registrations' ); ?></th>
								<td>
									<?php
									echo esc_html(
										mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $registration['updated_at'] )
									);
									?>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><label for="mcr-status"><?php esc_html_e( 'Status', 'music-club-registrations' ); ?></label></th>
							<td>
								<select name="status" id="mcr-status">
									<?php foreach ( $statuses as $slug => $label ) : ?>
										<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $registration['status'], $slug ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mcr-internal-notes"><?php esc_html_e( 'Internal Notes', 'music-club-registrations' ); ?></label></th>
							<td>
								<textarea name="internal_notes" id="mcr-internal-notes" rows="5" class="large-text"><?php echo esc_textarea( $registration['internal_notes'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Visible only to administrators. Never displayed on the front end.', 'music-club-registrations' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit mcr-detail-actions">
					<button type="submit" name="mcr_save_detail" value="1" class="button button-primary">
						<?php esc_html_e( 'Save', 'music-club-registrations' ); ?>
					</button>
					<a href="<?php echo esc_url( $export_url ); ?>" class="button">
						<?php esc_html_e( 'Export', 'music-club-registrations' ); ?>
					</a>
					<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete mcr-delete-link" data-confirm="<?php esc_attr_e( 'Are you sure you want to delete this registration?', 'music-club-registrations' ); ?>">
						<?php esc_html_e( 'Delete', 'music-club-registrations' ); ?>
					</a>
				</p>
			</form>
		</div>

		<?php foreach ( \Music_Club_Registrations\Excel_OAuth::get_target_keys() as $ms_target_key ) : ?>
			<?php
			$is_payment_target   = 'payments' === $ms_target_key;
			$status_field        = $is_payment_target ? 'payment_excel_sync_status' : 'excel_sync_status';
			$attempts_field      = $is_payment_target ? 'payment_excel_sync_attempts' : 'excel_sync_attempts';
			$last_sync_at_field  = $is_payment_target ? 'payment_excel_last_sync_at' : 'excel_last_sync_at';
			$last_error_field    = $is_payment_target ? 'payment_excel_last_sync_error' : 'excel_last_sync_error';
			$sync_status_value   = $registration[ $status_field ] ?? 'not_configured';
			?>
			<div class="mcr-detail-sidebar">
				<h2>
					<?php
					echo esc_html(
						$is_payment_target
							? __( 'Payment Sync Status', 'music-club-registrations' )
							: __( 'Excel Sync Status', 'music-club-registrations' )
					);
					?>
				</h2>
				<p><?php echo mcr_render_excel_sync_badge( $sync_status_value ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside the helper. ?></p>

				<?php if ( 'not_configured' !== $sync_status_value ) : ?>
					<?php if ( ! empty( $registration[ $last_sync_at_field ] ) ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: date/time of the last sync attempt */
								esc_html__( 'Last attempt: %s', 'music-club-registrations' ),
								esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $registration[ $last_sync_at_field ] ) )
							);
							?>
						</p>
					<?php endif; ?>
					<p class="description">
						<?php
						printf(
							/* translators: %d: number of sync attempts */
							esc_html__( 'Attempts: %d', 'music-club-registrations' ),
							(int) ( $registration[ $attempts_field ] ?? 0 )
						);
						?>
					</p>
					<?php if ( ! empty( $registration[ $last_error_field ] ) ) : ?>
						<p class="description mcr-inline-warning"><?php echo esc_html( $registration[ $last_error_field ] ); ?></p>
					<?php endif; ?>
					<?php
					$ms_connection_summary = \Music_Club_Registrations\Excel_OAuth::get_target_connection( $ms_target_key );
					if ( ! empty( $ms_connection_summary['workbook_name'] ) ) :
						?>
						<p class="description">
							<?php echo esc_html( $ms_connection_summary['workbook_name'] ); ?>
							<?php if ( ! empty( $ms_connection_summary['table_name'] ) ) : ?>
								&rarr; <?php echo esc_html( $ms_connection_summary['table_name'] ); ?>
							<?php endif; ?>
						</p>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'mcr_ms_sync_single_' . $ms_target_key . '_' . absint( $registration['id'] ) ); ?>
						<input type="hidden" name="action" value="mcr_ms_sync_single" />
						<input type="hidden" name="target" value="<?php echo esc_attr( $ms_target_key ); ?>" />
						<input type="hidden" name="id" value="<?php echo esc_attr( $registration['id'] ); ?>" />
						<button type="submit" class="button mcr-sync-now-btn"><?php esc_html_e( 'Sync Again', 'music-club-registrations' ); ?></button>
					</form>
				<?php else : ?>
					<p class="description">
						<?php
						echo esc_html(
							$is_payment_target
								? __( 'Excel Online integration for Payments is not configured yet.', 'music-club-registrations' )
								: __( 'Excel Online integration is not configured yet.', 'music-club-registrations' )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<div class="mcr-detail-sidebar">
			<h2><?php esc_html_e( 'Attendance History', 'music-club-registrations' ); ?></h2>
			<?php if ( empty( $attendance_history ) ) : ?>
				<p class="description"><?php esc_html_e( 'No attendance has been recorded for this registration yet.', 'music-club-registrations' ); ?></p>
			<?php else : ?>
				<table class="widefat mcr-attendance-mini-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'music-club-registrations' ); ?></th>
							<th><?php esc_html_e( 'Program', 'music-club-registrations' ); ?></th>
							<th><?php esc_html_e( 'Status', 'music-club-registrations' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $attendance_history as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $entry['attendance_date'] ) ); ?></td>
								<td><?php echo esc_html( $entry['program'] ); ?></td>
								<td><?php echo esc_html( \Music_Club_Registrations\Attendance::get_statuses_with_not_marked()[ $entry['status'] ] ?? $entry['status'] ); ?></td>
							</tr>
							<?php if ( ! empty( $entry['notes'] ) ) : ?>
								<tr class="mcr-attendance-mini-note-row">
									<td colspan="3"><em><?php echo esc_html( $entry['notes'] ); ?></em></td>
								</tr>
							<?php endif; ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<div class="mcr-detail-sidebar">
			<h2><?php esc_html_e( 'Change History', 'music-club-registrations' ); ?></h2>
			<?php if ( empty( $history ) ) : ?>
				<p><?php esc_html_e( 'No changes have been recorded yet.', 'music-club-registrations' ); ?></p>
			<?php else : ?>
				<ul class="mcr-history-list">
					<?php foreach ( $history as $entry ) : ?>
						<li>
							<strong><?php echo esc_html( ucfirst( str_replace( '_', ' ', $entry['field'] ) ) ); ?></strong>
							<span class="mcr-history-date">
								<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['changed_at'] ) ); ?>
							</span>
							<br />
							<?php
							$user      = $entry['changed_by'] ? get_userdata( $entry['changed_by'] ) : null;
							$user_name = $user ? $user->display_name : __( 'System', 'music-club-registrations' );
							?>
							<span class="mcr-history-user">
								<?php
								printf(
									/* translators: %s: user display name */
									esc_html__( 'by %s', 'music-club-registrations' ),
									esc_html( $user_name )
								);
								?>
							</span>
							<?php if ( 'status' === $entry['field'] ) : ?>
								<div class="mcr-history-change">
									<?php echo esc_html( $entry['old_value'] ? mcr_get_status_label( $entry['old_value'] ) : '—' ); ?>
									&rarr;
									<?php echo esc_html( mcr_get_status_label( $entry['new_value'] ) ); ?>
								</div>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
	</div>
</div>

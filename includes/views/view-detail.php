<?php
/**
 * View: tela de detalhes de uma inscrição.
 *
 * Variáveis disponíveis (definidas em Admin::render_detail_page):
 *
 * @var array $registration Dados completos da inscrição.
 * @var array $history      Histórico de alterações.
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
							<th scope="row"><?php esc_html_e( "Child's Name", 'music-club-registrations' ); ?></th>
							<td><?php echo esc_html( $registration['child_name'] ); ?></td>
						</tr>
						<?php if ( null !== ( $registration['child_age'] ?? null ) && '' !== (string) $registration['child_age'] ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( "Child's Age", 'music-club-registrations' ); ?></th>
								<td><?php echo esc_html( $registration['child_age'] ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( '' !== $registration['child_class'] ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Class', 'music-club-registrations' ); ?></th>
								<td><?php echo esc_html( $registration['child_class'] ); ?></td>
							</tr>
						<?php endif; ?>

						<tr>
							<th scope="row" colspan="2"><h2 class="mcr-detail-section-title"><?php esc_html_e( 'Contact Information', 'music-club-registrations' ); ?></h2></th>
						</tr>
						<?php if ( '' !== $registration['parent_name'] ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Parent/Guardian Name', 'music-club-registrations' ); ?></th>
								<td><?php echo esc_html( $registration['parent_name'] ); ?></td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Primary Email', 'music-club-registrations' ); ?></th>
							<td><a href="mailto:<?php echo esc_attr( $registration['parent_email'] ); ?>"><?php echo esc_html( $registration['parent_email'] ); ?></a></td>
						</tr>
						<?php if ( '' !== $registration['phone'] ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Primary Phone', 'music-club-registrations' ); ?></th>
								<td><?php echo esc_html( $registration['phone'] ); ?></td>
							</tr>
						<?php endif; ?>
						<?php if ( ! empty( $registration['second_parent_email'] ?? '' ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Additional Email', 'music-club-registrations' ); ?></th>
								<td><a href="mailto:<?php echo esc_attr( $registration['second_parent_email'] ); ?>"><?php echo esc_html( $registration['second_parent_email'] ); ?></a></td>
							</tr>
						<?php endif; ?>
						<?php if ( ! empty( $registration['second_parent_phone'] ?? '' ) ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Additional Phone', 'music-club-registrations' ); ?></th>
								<td><?php echo esc_html( $registration['second_parent_phone'] ); ?></td>
							</tr>
						<?php endif; ?>

						<tr>
							<th scope="row" colspan="2"><h2 class="mcr-detail-section-title"><?php esc_html_e( 'Programs / Interests', 'music-club-registrations' ); ?></h2></th>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Selected Programs', 'music-club-registrations' ); ?></th>
							<td>
								<?php if ( $interests ) : ?>
									<ul class="mcr-interests-checklist">
										<?php foreach ( $interests as $interest ) : ?>
											<li>✓ <?php echo esc_html( $interest ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>

						<?php if ( '' !== $registration['additional_message'] ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Additional Message', 'music-club-registrations' ); ?></th>
								<td><?php echo nl2br( esc_html( $registration['additional_message'] ) ); ?></td>
							</tr>
						<?php endif; ?>

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

		<div class="mcr-detail-sidebar">
			<h2><?php esc_html_e( 'Excel Sync Status', 'music-club-registrations' ); ?></h2>
			<p><?php echo mcr_render_excel_sync_badge( $registration['excel_sync_status'] ?? 'not_configured' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped inside the helper. ?></p>

			<?php if ( 'not_configured' !== ( $registration['excel_sync_status'] ?? 'not_configured' ) ) : ?>
				<?php if ( ! empty( $registration['excel_last_sync_at'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: date/time of the last sync attempt */
							esc_html__( 'Last attempt: %s', 'music-club-registrations' ),
							esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $registration['excel_last_sync_at'] ) )
						);
						?>
					</p>
				<?php endif; ?>
				<p class="description">
					<?php
					printf(
						/* translators: %d: number of sync attempts */
						esc_html__( 'Attempts: %d', 'music-club-registrations' ),
						(int) ( $registration['excel_sync_attempts'] ?? 0 )
					);
					?>
				</p>
				<?php if ( ! empty( $registration['excel_last_sync_error'] ) ) : ?>
					<p class="description mcr-inline-warning"><?php echo esc_html( $registration['excel_last_sync_error'] ); ?></p>
				<?php endif; ?>
				<?php
				$ms_connection_summary = \Music_Club_Registrations\Excel_OAuth::get_connection();
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
					<?php wp_nonce_field( 'mcr_ms_sync_single_' . absint( $registration['id'] ) ); ?>
					<input type="hidden" name="action" value="mcr_ms_sync_single" />
					<input type="hidden" name="id" value="<?php echo esc_attr( $registration['id'] ); ?>" />
					<button type="submit" class="button mcr-sync-now-btn"><?php esc_html_e( 'Sync Again', 'music-club-registrations' ); ?></button>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Excel Online integration is not configured yet.', 'music-club-registrations' ); ?></p>
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

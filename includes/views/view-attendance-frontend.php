<?php
/**
 * View: versão pública (shortcode `[mcr_attendance]`) da tela de
 * Presença, exibida fora do admin numa página comum do WordPress.
 *
 * Mesma estrutura e classes CSS/JS da versão de admin
 * (includes/views/view-attendance.php), mudando apenas: onde os links e
 * formulários apontam (a própria página, em vez de admin.php), o nome
 * dos parâmetros de URL (prefixados "mcr_" para não colidir com outros
 * parâmetros do tema/outros plugins na mesma página), e a ação de
 * salvar (própria, com seu próprio nonce e sem exigir capacidade de
 * administrador - a proteção aqui é a senha da própria página).
 *
 * Variáveis disponíveis (definidas em Attendance_Shortcode::render_shortcode):
 *
 * @var int                 $post_id              ID da página que contém o shortcode.
 * @var string              $active_tab           Aba ativa ('take' ou 'history').
 * @var array<int,string>   $programs             Lista de programas/interesses distintos já usados.
 * @var string              $selected_program     Programa selecionado atualmente.
 * @var string              $selected_date        Data selecionada atualmente (Y-m-d).
 * @var array               $roster               Lista de presença, quando aplicável.
 * @var array               $consecutive_absences Mapa registration_id => faltas consecutivas.
 * @var string|null         $last_session_date    Data da sessão anterior mais recente, ou null.
 * @var array               $last_session_map     Mapa registration_id => {status,notes} da sessão anterior.
 * @var array|null          $saved_summary        Resumo do último salvamento (array{saved:int,attempted:int}), ou null.
 * @var array               $history_rows         Sessões de chamada já feitas (aba History).
 * @var string              $history_date_from    Filtro de data inicial da aba History.
 * @var string              $history_date_to      Filtro de data final da aba History.
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="mcr-frontend-attendance mcr-attendance-wrap">
	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( \Music_Club_Registrations\Attendance_Shortcode::tab_url( 'take', $post_id ) ); ?>" class="nav-tab <?php echo 'take' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Take Attendance', 'music-club-registrations' ); ?>
		</a>
		<a href="<?php echo esc_url( \Music_Club_Registrations\Attendance_Shortcode::tab_url( 'history', $post_id ) ); ?>" class="nav-tab <?php echo 'history' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'History', 'music-club-registrations' ); ?>
		</a>
	</h2>

	<?php if ( empty( $programs ) ) : ?>

		<p class="description">
			<?php esc_html_e( 'No programs have been recorded yet. Programs come from the "Interests" field of your registrations, so at least one registration with a program selected is needed before attendance can be taken.', 'music-club-registrations' ); ?>
		</p>

	<?php elseif ( 'take' === $active_tab ) : ?>

		<?php if ( null !== $saved_summary ) : ?>
			<?php if ( $saved_summary['saved'] === $saved_summary['attempted'] && $saved_summary['attempted'] > 0 ) : ?>
				<div class="notice notice-success inline">
					<p>
						<?php
						printf(
							/* translators: %d: number of students marked */
							esc_html__( 'Attendance saved — %d student(s) marked.', 'music-club-registrations' ),
							(int) $saved_summary['saved']
						);
						?>
					</p>
				</div>
			<?php elseif ( 0 === $saved_summary['attempted'] ) : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Nothing was saved — no student had a status selected (they were all left as "Not Marked").', 'music-club-registrations' ); ?></p>
				</div>
			<?php else : ?>
				<div class="notice notice-error inline">
					<p>
						<?php
						printf(
							/* translators: 1: number saved, 2: number attempted */
							esc_html__( 'Only %1$d of %2$d student(s) were actually saved — something went wrong. Please tell the site administrator.', 'music-club-registrations' ),
							(int) $saved_summary['saved'],
							(int) $saved_summary['attempted']
						);
						?>
					</p>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<form method="get" action="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="mcr-attendance-load-form">
			<label for="mcr-attendance-program"><strong><?php esc_html_e( 'Program', 'music-club-registrations' ); ?></strong></label>
			<select name="mcr_program" id="mcr-attendance-program">
				<option value=""><?php esc_html_e( '— Select program —', 'music-club-registrations' ); ?></option>
				<?php foreach ( $programs as $program ) : ?>
					<option value="<?php echo esc_attr( $program ); ?>" <?php selected( $selected_program, $program ); ?>>
						<?php echo esc_html( $program ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mcr-attendance-date"><strong><?php esc_html_e( 'Date', 'music-club-registrations' ); ?></strong></label>
			<input type="date" name="mcr_date" id="mcr-attendance-date" value="<?php echo esc_attr( $selected_date ); ?>" />

			<button type="submit" class="button button-primary"><?php esc_html_e( 'Load Students', 'music-club-registrations' ); ?></button>
		</form>

		<?php if ( '' === $selected_program ) : ?>

			<p class="description"><?php esc_html_e( 'Choose a program and a date, then click "Load Students" to take attendance.', 'music-club-registrations' ); ?></p>

		<?php elseif ( empty( $roster ) ) : ?>

			<p class="description">
				<?php
				printf(
					/* translators: %s: program name */
					esc_html__( 'No registrations currently have "%s" selected as a program.', 'music-club-registrations' ),
					esc_html( $selected_program )
				);
				?>
			</p>

		<?php else : ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mcr-attendance-form" data-confirm-leave="<?php esc_attr_e( 'You have unsaved attendance changes. Leave this page without saving?', 'music-club-registrations' ); ?>">
				<?php wp_nonce_field( \Music_Club_Registrations\Attendance_Shortcode::SAVE_ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( \Music_Club_Registrations\Attendance_Shortcode::SAVE_ACTION ); ?>" />
				<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>" />
				<input type="hidden" name="program" value="<?php echo esc_attr( $selected_program ); ?>" />
				<input type="hidden" name="attendance_date" value="<?php echo esc_attr( $selected_date ); ?>" />

				<h3>
					<?php
					printf(
						/* translators: 1: program name, 2: formatted date */
						esc_html__( 'Students — %1$s (%2$s)', 'music-club-registrations' ),
						esc_html( $selected_program ),
						esc_html( mysql2date( get_option( 'date_format' ), $selected_date ) )
					);
					?>
				</h3>

				<div class="mcr-attendance-toolbar">
					<button type="button" class="button" id="mcr-mark-all-present">
						<?php esc_html_e( 'Mark all as Present', 'music-club-registrations' ); ?>
					</button>

					<?php if ( $last_session_date ) : ?>
						<button type="button" class="button" id="mcr-copy-last-session" data-last-session-map="<?php echo esc_attr( wp_json_encode( $last_session_map ) ); ?>">
							<?php
							printf(
								/* translators: %s: formatted date of the previous session */
								esc_html__( 'Copy from Last Session (%s)', 'music-club-registrations' ),
								esc_html( mysql2date( get_option( 'date_format' ), $last_session_date ) )
							);
							?>
						</button>
					<?php endif; ?>

					<span class="mcr-attendance-counter" id="mcr-attendance-counter" aria-live="polite"></span>
				</div>

				<div class="mcr-table-scroll">
				<table class="widefat mcr-attendance-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Child', 'music-club-registrations' ); ?></th>
							<th><?php esc_html_e( 'Age', 'music-club-registrations' ); ?></th>
							<th><?php esc_html_e( 'Class', 'music-club-registrations' ); ?></th>
							<th><?php esc_html_e( 'Registration', 'music-club-registrations' ); ?></th>
							<th><?php esc_html_e( 'Status', 'music-club-registrations' ); ?></th>
							<th><?php esc_html_e( 'Notes', 'music-club-registrations' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $roster as $entry ) : ?>
							<?php $streak = $consecutive_absences[ $entry['registration_id'] ] ?? 0; ?>
							<tr class="mcr-attendance-row mcr-attendance-row-<?php echo esc_attr( $entry['status'] ); ?>" data-registration-id="<?php echo esc_attr( $entry['registration_id'] ); ?>">
								<td>
									<?php echo esc_html( $entry['child_name'] ); ?>
									<?php if ( $streak >= 3 ) : ?>
										<span class="mcr-absence-warning" title="<?php echo esc_attr( sprintf( __( '%d consecutive absences in this program', 'music-club-registrations' ), $streak ) ); ?>">
											⚠️ <?php echo esc_html( sprintf( __( '%d in a row', 'music-club-registrations' ), $streak ) ); ?>
										</span>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( null !== $entry['child_age'] ? $entry['child_age'] : '—' ); ?></td>
								<td><?php echo esc_html( $entry['child_class'] ? $entry['child_class'] : '—' ); ?></td>
								<td><?php echo esc_html( $entry['registration_number'] ); ?></td>
								<td class="mcr-status-cell">
									<select name="attendance[<?php echo esc_attr( $entry['registration_id'] ); ?>][status]" class="mcr-attendance-status-select mcr-hidden-select">
										<?php foreach ( \Music_Club_Registrations\Attendance::get_statuses_with_not_marked() as $slug => $label ) : ?>
											<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $entry['status'], $slug ); ?>>
												<?php echo esc_html( $label ); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<div class="mcr-status-buttons">
										<button type="button" class="mcr-status-btn mcr-status-btn-present" data-status="present" title="<?php esc_attr_e( 'Present', 'music-club-registrations' ); ?>">P</button>
										<button type="button" class="mcr-status-btn mcr-status-btn-absent" data-status="absent" title="<?php esc_attr_e( 'Absent', 'music-club-registrations' ); ?>">A</button>
										<button type="button" class="mcr-status-btn mcr-status-btn-late" data-status="late" title="<?php esc_attr_e( 'Late', 'music-club-registrations' ); ?>">L</button>
										<button type="button" class="mcr-status-btn mcr-status-btn-excused" data-status="excused" title="<?php esc_attr_e( 'Excused', 'music-club-registrations' ); ?>">E</button>
									</div>
								</td>
								<td>
									<input type="text" name="attendance[<?php echo esc_attr( $entry['registration_id'] ); ?>][notes]" value="<?php echo esc_attr( $entry['notes'] ); ?>" class="regular-text mcr-attendance-notes" placeholder="<?php esc_attr_e( 'Optional', 'music-club-registrations' ); ?>" />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary" id="mcr-save-attendance-btn"><?php esc_html_e( 'Save Attendance', 'music-club-registrations' ); ?></button>
				</p>
			</form>

		<?php endif; ?>

	<?php else : ?>

		<form method="get" action="<?php echo esc_url( get_permalink( $post_id ) ); ?>" class="mcr-attendance-load-form">
			<input type="hidden" name="mcr_view" value="history" />

			<label for="mcr-attendance-history-program"><strong><?php esc_html_e( 'Program', 'music-club-registrations' ); ?></strong></label>
			<select name="mcr_program" id="mcr-attendance-history-program">
				<option value=""><?php esc_html_e( 'All programs', 'music-club-registrations' ); ?></option>
				<?php foreach ( $programs as $program ) : ?>
					<option value="<?php echo esc_attr( $program ); ?>" <?php selected( $selected_program, $program ); ?>>
						<?php echo esc_html( $program ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<label for="mcr-history-date-from"><strong><?php esc_html_e( 'From', 'music-club-registrations' ); ?></strong></label>
			<input type="date" name="mcr_date_from" id="mcr-history-date-from" value="<?php echo esc_attr( $history_date_from ); ?>" />

			<label for="mcr-history-date-to"><strong><?php esc_html_e( 'To', 'music-club-registrations' ); ?></strong></label>
			<input type="date" name="mcr_date_to" id="mcr-history-date-to" value="<?php echo esc_attr( $history_date_to ); ?>" />

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'music-club-registrations' ); ?></button>
		</form>

		<?php if ( empty( $history_rows ) ) : ?>

			<p class="description"><?php esc_html_e( 'No attendance has been recorded yet.', 'music-club-registrations' ); ?></p>

		<?php else : ?>

			<div class="mcr-table-scroll">
			<table class="widefat mcr-attendance-history-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'music-club-registrations' ); ?></th>
						<th><?php esc_html_e( 'Program', 'music-club-registrations' ); ?></th>
						<th>🟢 <?php esc_html_e( 'Present', 'music-club-registrations' ); ?></th>
						<th>🔴 <?php esc_html_e( 'Absent', 'music-club-registrations' ); ?></th>
						<th>🟡 <?php esc_html_e( 'Late', 'music-club-registrations' ); ?></th>
						<th>🔵 <?php esc_html_e( 'Excused', 'music-club-registrations' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'music-club-registrations' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $history_rows as $session ) : ?>
						<tr>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $session['attendance_date'] ) ); ?></td>
							<td><?php echo esc_html( $session['program'] ); ?></td>
							<td><?php echo (int) $session['present']; ?></td>
							<td><?php echo (int) $session['absent']; ?></td>
							<td><?php echo (int) $session['late']; ?></td>
							<td><?php echo (int) $session['excused']; ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'mcr_view' => 'take', 'mcr_program' => $session['program'], 'mcr_date' => $session['attendance_date'] ), get_permalink( $post_id ) ) ); ?>">
									<?php esc_html_e( 'View / Edit', 'music-club-registrations' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<?php endif; ?>

	<?php endif; ?>
</div>

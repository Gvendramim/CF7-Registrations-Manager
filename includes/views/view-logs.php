<?php
/**
 * View: tela de visualização de logs internos do plugin.
 *
 * Variáveis disponíveis (definidas em Admin::render_logs_page):
 *
 * @var array  $logs                Entradas de log da página atual.
 * @var int    $total_logs          Total de entradas (todos os filtros aplicados).
 * @var int    $per_page            Itens por página.
 * @var int    $total_pages         Total de páginas.
 * @var int    $current_page        Página atual.
 * @var string $current_level       Filtro de nível atual.
 * @var string $current_context     Filtro de contexto atual.
 * @var string $current_search      Termo de busca atual.
 * @var array  $available_contexts  Lista de contextos já registrados.
 * @var string $download_nonce      Nonce para o link de download.
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$logs_slug = \Music_Club_Registrations\Admin::LOGS_SLUG;

$level_badge_class = array(
	'info'     => 'mcr-log-info',
	'warning'  => 'mcr-log-warning',
	'error'    => 'mcr-log-error',
	'critical' => 'mcr-log-critical',
);

$download_url = add_query_arg(
	array(
		'action'   => 'mcr_download_logs',
		'level'    => $current_level,
		'context'  => $current_context,
		's'        => $current_search,
		'_wpnonce' => $download_nonce,
	),
	admin_url( 'admin-post.php' )
);
?>
<div class="wrap mcr-wrap mcr-logs-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Logs', 'music-club-registrations' ); ?></h1>
	<a href="<?php echo esc_url( $download_url ); ?>" class="page-title-action"><?php esc_html_e( 'Download CSV', 'music-club-registrations' ); ?></a>

	<hr class="wp-header-end" />

	<form method="get" class="mcr-logs-filter">
		<input type="hidden" name="page" value="<?php echo esc_attr( $logs_slug ); ?>" />

		<label for="mcr-log-search" class="screen-reader-text"><?php esc_html_e( 'Search logs', 'music-club-registrations' ); ?></label>
		<input type="search" name="s" id="mcr-log-search" value="<?php echo esc_attr( $current_search ); ?>" placeholder="<?php esc_attr_e( 'Search message…', 'music-club-registrations' ); ?>" />

		<label for="mcr-log-level" class="screen-reader-text"><?php esc_html_e( 'Filter by level', 'music-club-registrations' ); ?></label>
		<select name="level" id="mcr-log-level">
			<option value=""><?php esc_html_e( 'All levels', 'music-club-registrations' ); ?></option>
			<option value="info" <?php selected( $current_level, 'info' ); ?>><?php esc_html_e( 'Info', 'music-club-registrations' ); ?></option>
			<option value="warning" <?php selected( $current_level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'music-club-registrations' ); ?></option>
			<option value="error" <?php selected( $current_level, 'error' ); ?>><?php esc_html_e( 'Error', 'music-club-registrations' ); ?></option>
			<option value="critical" <?php selected( $current_level, 'critical' ); ?>><?php esc_html_e( 'Critical', 'music-club-registrations' ); ?></option>
		</select>

		<label for="mcr-log-context" class="screen-reader-text"><?php esc_html_e( 'Filter by context', 'music-club-registrations' ); ?></label>
		<select name="context" id="mcr-log-context">
			<option value=""><?php esc_html_e( 'All contexts', 'music-club-registrations' ); ?></option>
			<?php foreach ( $available_contexts as $context ) : ?>
				<option value="<?php echo esc_attr( $context ); ?>" <?php selected( $current_context, $context ); ?>>
					<?php echo esc_html( ucfirst( $context ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<?php submit_button( __( 'Filter', 'music-club-registrations' ), '', '', false ); ?>
	</form>

	<table class="wp-list-table widefat fixed striped mcr-logs-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Level', 'music-club-registrations' ); ?></th>
				<th><?php esc_html_e( 'Context', 'music-club-registrations' ); ?></th>
				<th><?php esc_html_e( 'Message', 'music-club-registrations' ); ?></th>
				<th><?php esc_html_e( 'User', 'music-club-registrations' ); ?></th>
				<th><?php esc_html_e( 'Date', 'music-club-registrations' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr>
					<td colspan="5"><?php esc_html_e( 'No log entries found.', 'music-club-registrations' ); ?></td>
				</tr>
			<?php else : ?>
				<?php foreach ( $logs as $log ) : ?>
					<?php
					$user      = $log['user_id'] ? get_userdata( $log['user_id'] ) : null;
					$user_name = $user ? $user->display_name : __( 'System', 'music-club-registrations' );
					$badge     = $level_badge_class[ $log['level'] ] ?? 'mcr-log-info';
					?>
					<tr>
						<td><span class="mcr-log-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( ucfirst( $log['level'] ) ); ?></span></td>
						<td><?php echo esc_html( ucfirst( $log['context'] ) ); ?></td>
						<td><?php echo esc_html( $log['message'] ); ?></td>
						<td><?php echo esc_html( $user_name ); ?></td>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log['created_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'prev_text' => __( '&laquo;', 'music-club-registrations' ),
							'next_text' => __( '&raquo;', 'music-club-registrations' ),
							'total'     => $total_pages,
							'current'   => $current_page,
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>

	<form method="post" class="mcr-clear-logs-form">
		<?php wp_nonce_field( 'mcr_clear_logs' ); ?>
		<button type="submit" name="mcr_clear_logs" value="1" class="button mcr-clear-logs-btn">
			<?php esc_html_e( 'Clear All Logs', 'music-club-registrations' ); ?>
		</button>
	</form>
</div>

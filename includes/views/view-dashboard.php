<?php
/**
 * View: tela de Dashboard, com indicadores e gráficos.
 *
 * Variáveis disponíveis (definidas em Admin::render_dashboard_page):
 *
 * @var array  $stats          Indicadores retornados por Database::get_dashboard_stats().
 * @var string $monitored_form Rótulo do formulário atualmente monitorado.
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$statuses = mcr_get_statuses();
?>
<div class="wrap mcr-wrap mcr-dashboard-wrap">
	<h1><?php esc_html_e( 'CF7 Registrations Manager — Dashboard', 'music-club-registrations' ); ?></h1>

	<p class="description">
		<?php
		printf(
			/* translators: %s: monitored form label */
			esc_html__( 'Currently monitoring: %s', 'music-club-registrations' ),
			'<strong>' . esc_html( $monitored_form ) . '</strong>'
		);
		?>
		&mdash;
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \Music_Club_Registrations\Admin::SETTINGS_SLUG ) ); ?>">
			<?php esc_html_e( 'Change in Settings', 'music-club-registrations' ); ?>
		</a>
	</p>

	<div class="mcr-stats-grid">
		<div class="mcr-stat-card">
			<span class="mcr-stat-value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
			<span class="mcr-stat-label"><?php esc_html_e( 'Total Registrations', 'music-club-registrations' ); ?></span>
		</div>
		<div class="mcr-stat-card">
			<span class="mcr-stat-value"><?php echo esc_html( number_format_i18n( $stats['today'] ) ); ?></span>
			<span class="mcr-stat-label"><?php esc_html_e( 'Today', 'music-club-registrations' ); ?></span>
		</div>
		<div class="mcr-stat-card">
			<span class="mcr-stat-value"><?php echo esc_html( number_format_i18n( $stats['this_week'] ) ); ?></span>
			<span class="mcr-stat-label"><?php esc_html_e( 'This Week', 'music-club-registrations' ); ?></span>
		</div>
		<div class="mcr-stat-card">
			<span class="mcr-stat-value"><?php echo esc_html( number_format_i18n( $stats['this_month'] ) ); ?></span>
			<span class="mcr-stat-label"><?php esc_html_e( 'This Month', 'music-club-registrations' ); ?></span>
		</div>
	</div>

	<div class="mcr-stats-grid mcr-stats-grid-status">
		<?php foreach ( $statuses as $slug => $label ) : ?>
			<div class="mcr-stat-card mcr-stat-card-status <?php echo esc_attr( mcr_get_status_css_class( $slug ) ); ?>">
				<span class="mcr-stat-value"><?php echo esc_html( number_format_i18n( $stats['by_status'][ $slug ] ?? 0 ) ); ?></span>
				<span class="mcr-stat-label"><?php echo esc_html( $label ); ?></span>
			</div>
		<?php endforeach; ?>
	</div>

	<div class="mcr-charts-grid">
		<div class="mcr-chart-card">
			<h2><?php esc_html_e( 'Registrations (Last 30 Days)', 'music-club-registrations' ); ?></h2>
			<canvas id="mcr-timeline-chart" height="110"></canvas>
		</div>
		<div class="mcr-chart-card">
			<h2><?php esc_html_e( 'Status Distribution', 'music-club-registrations' ); ?></h2>
			<canvas id="mcr-status-chart" height="110"></canvas>
		</div>
		<div class="mcr-chart-card">
			<h2><?php esc_html_e( 'Registrations by Month', 'music-club-registrations' ); ?></h2>
			<canvas id="mcr-monthly-chart" height="110"></canvas>
		</div>
		<div class="mcr-chart-card">
			<h2><?php esc_html_e( 'Registrations by Class', 'music-club-registrations' ); ?></h2>
			<canvas id="mcr-class-chart" height="110"></canvas>
		</div>
		<div class="mcr-chart-card">
			<h2><?php esc_html_e( 'Registrations by Program', 'music-club-registrations' ); ?></h2>
			<canvas id="mcr-interest-chart" height="110"></canvas>
		</div>
	</div>

	<div class="mcr-ms-dashboard-card">
		<h2><?php esc_html_e( 'Excel Online', 'music-club-registrations' ); ?></h2>
		<p class="mcr-ms-status">
			<span class="mcr-status-dot <?php echo $excel_connected ? 'mcr-status-dot-on' : 'mcr-status-dot-off'; ?>"></span>
			<?php echo $excel_connected ? esc_html__( 'Connected', 'music-club-registrations' ) : esc_html__( 'Not Connected', 'music-club-registrations' ); ?>
		</p>
		<div class="mcr-ms-stats-row">
			<div><strong><?php echo (int) $excel_sync_stats['synced']; ?></strong><span><?php esc_html_e( 'Synced', 'music-club-registrations' ); ?></span></div>
			<div><strong><?php echo (int) $excel_sync_stats['pending']; ?></strong><span><?php esc_html_e( 'Pending', 'music-club-registrations' ); ?></span></div>
			<div><strong><?php echo (int) $excel_sync_stats['failed']; ?></strong><span><?php esc_html_e( 'Failed', 'music-club-registrations' ); ?></span></div>
		</div>
		<?php if ( ! empty( $excel_sync_stats['last_sync_at'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: date/time of the last successful sync */
					esc_html__( 'Last Sync: %s', 'music-club-registrations' ),
					esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $excel_sync_stats['last_sync_at'] ) )
				);
				?>
			</p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \Music_Club_Registrations\Admin::SETTINGS_SLUG . '&tab=excel' ) ); ?>">
				<?php echo $excel_connected ? esc_html__( 'Manage Excel Online', 'music-club-registrations' ) : esc_html__( 'Connect Microsoft 365', 'music-club-registrations' ); ?>
			</a>
			&nbsp;·&nbsp;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \Music_Club_Registrations\Admin::LOGS_SLUG . '&context=excel_online' ) ); ?>">
				<?php esc_html_e( 'View Sync Logs', 'music-club-registrations' ); ?>
			</a>
		</p>
	</div>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \Music_Club_Registrations\Admin::LIST_SLUG ) ); ?>" class="button button-primary">
			<?php esc_html_e( 'View All Registrations', 'music-club-registrations' ); ?>
		</a>
	</p>
</div>

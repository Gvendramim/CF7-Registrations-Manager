<?php
/**
 * Rotina de desinstalação do plugin.
 *
 * Este arquivo é executado automaticamente pelo WordPress quando o plugin
 * é excluído pela tela de plugins (não na simples desativação).
 *
 * Por padrão, os dados NÃO são removidos, para evitar perda acidental de
 * inscrições. A remoção só ocorre se o administrador explicitamente
 * habilitar a opção "Remove data on uninstall" na tela de Settings do
 * plugin, ou definir a constante MCR_REMOVE_DATA_ON_UNINSTALL como true
 * em wp-config.php. Nenhum desses valores fica fixo no código.
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * @return bool
 */
function mcr_should_remove_data_on_uninstall() {
	if ( defined( 'MCR_REMOVE_DATA_ON_UNINSTALL' ) && true === MCR_REMOVE_DATA_ON_UNINSTALL ) {
		return true;
	}

	$settings = get_option( 'mcr_settings', array() );

	if ( is_array( $settings ) && ! empty( $settings['remove_data_on_uninstall'] ) ) {
		return true;
	}

	// Compatibilidade com a opção legada usada em versões anteriores.
	return '1' === get_option( 'mcr_remove_data_on_uninstall', '0' );
}

if ( ! mcr_should_remove_data_on_uninstall() ) {
	return;
}

global $wpdb;

$table       = $wpdb->prefix . 'music_club_registrations';
$history     = $wpdb->prefix . 'music_club_registration_history';
$logs_table  = $wpdb->prefix . 'music_club_logs';

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$history}" );
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );

delete_option( 'mcr_db_version' );
delete_option( 'mcr_settings' );
delete_option( 'mcr_remove_data_on_uninstall' );
delete_option( 'mcr_excel_connection' );

$next_sync_cron = wp_next_scheduled( 'mcr_process_excel_sync_queue' );
if ( $next_sync_cron ) {
	wp_unschedule_event( $next_sync_cron, 'mcr_process_excel_sync_queue' );
}
wp_clear_scheduled_hook( 'mcr_process_single_excel_sync' );

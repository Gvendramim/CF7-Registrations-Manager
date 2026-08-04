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
 * Determina se os dados do plugin devem ser removidos.
 *
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

	return '1' === get_option( 'mcr_remove_data_on_uninstall', '0' );
}

if ( ! mcr_should_remove_data_on_uninstall() ) {
	return;
}

global $wpdb;

$table       = $wpdb->prefix . 'music_club_registrations';
$history     = $wpdb->prefix . 'music_club_registration_history';
$logs_table  = $wpdb->prefix . 'music_club_logs';

$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
$wpdb->query( "DROP TABLE IF EXISTS {$history}" );
$wpdb->query( "DROP TABLE IF EXISTS {$logs_table}" );

delete_option( 'mcr_db_version' );
delete_option( 'mcr_settings' );
delete_option( 'mcr_remove_data_on_uninstall' );

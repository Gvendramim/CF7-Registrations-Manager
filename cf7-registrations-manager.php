<?php
/**
 * Plugin Name:       CF7 Registrations Manager
 * Description:       Captura e gerencia automaticamente as inscrições enviadas por qualquer formulário do Contact Form 7 escolhido pelo administrador, armazenando os dados em uma tabela própria, com dashboard, mapeamento de campos configurável, painel administrativo completo, sistema de logs, exportação e API REST.
 * Version:           2.3.0
 * Author:            Gabriel Vendramim Ferreira
 * 
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCR_VERSION', '2.3.0' );

// Caminhos e URLs úteis.
define( 'MCR_PLUGIN_FILE', __FILE__ );
define( 'MCR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MCR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Nome das tabelas (sem prefixo, o prefixo é resolvido em runtime via $wpdb->prefix).
define( 'MCR_TABLE_NAME', 'music_club_registrations' );
define( 'MCR_HISTORY_TABLE_NAME', 'music_club_registration_history' );

// Versão do schema de banco de dados, usada para acionar dbDelta() em updates futuros.
define( 'MCR_DB_VERSION', '2.3.0' );


if ( ! defined( 'MCR_REMOVE_DATA_ON_UNINSTALL' ) ) {
	define( 'MCR_REMOVE_DATA_ON_UNINSTALL', false );
}

/**
 * -----------------------------------------------------------------------
 * Carregamento das classes internas do plugin
 * -----------------------------------------------------------------------
 */
require_once MCR_PLUGIN_DIR . 'includes/helpers.php';
require_once MCR_PLUGIN_DIR . 'includes/class-settings.php';
require_once MCR_PLUGIN_DIR . 'includes/class-logger.php';
require_once MCR_PLUGIN_DIR . 'includes/class-database.php';
require_once MCR_PLUGIN_DIR . 'includes/class-migrations.php';
require_once MCR_PLUGIN_DIR . 'includes/class-form-handler.php';
require_once MCR_PLUGIN_DIR . 'includes/class-list-table.php';
require_once MCR_PLUGIN_DIR . 'includes/class-admin.php';
require_once MCR_PLUGIN_DIR . 'includes/class-xlsx-writer.php';
require_once MCR_PLUGIN_DIR . 'includes/class-export.php';
require_once MCR_PLUGIN_DIR . 'includes/class-excel-oauth.php';
require_once MCR_PLUGIN_DIR . 'includes/class-excel-graph.php';
require_once MCR_PLUGIN_DIR . 'includes/class-excel-sync-queue.php';
require_once MCR_PLUGIN_DIR . 'includes/class-excel-online.php';
require_once MCR_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once MCR_PLUGIN_DIR . 'includes/class-setup-wizard.php';
require_once MCR_PLUGIN_DIR . 'includes/class-backup.php';
require_once MCR_PLUGIN_DIR . 'includes/class-loader.php';

/**
 * -----------------------------------------------------------------------
 * Ativação / Desativação
 * -----------------------------------------------------------------------
 */

 * @return void
 */
function mcr_activate_plugin() {
	Music_Club_Registrations\Database::install();
	Music_Club_Registrations\Settings::ensure_api_key();
	Music_Club_Registrations\Setup_Wizard::schedule_redirect();

	// Garante que as regras de rewrite da REST API sejam atualizadas.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mcr_activate_plugin' );

/**
 * Executado na desativação do plugin.
 *
 * @return void
 */
function mcr_deactivate_plugin() {
	Music_Club_Registrations\Excel_Sync_Queue::unschedule();

	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mcr_deactivate_plugin' );

/**
 * -----------------------------------------------------------------------
 * Inicialização do plugin
 * -----------------------------------------------------------------------
 */

/**
 *
 * @return void
 */
function mcr_init_plugin() {
	// Carrega o textdomain para traduções.
	load_plugin_textdomain( 'music-club-registrations', false, dirname( MCR_PLUGIN_BASENAME ) . '/languages' );

	if ( ! defined( 'WPCF7_VERSION' ) ) {
		add_action( 'admin_notices', 'mcr_missing_cf7_notice' );
	}

	Music_Club_Registrations\Loader::instance()->run();
}
add_action( 'plugins_loaded', 'mcr_init_plugin' );

/**
 * Exibe um aviso administrativo caso o Contact Form 7 não esteja ativo.
 *
 * @return void
 */
function mcr_missing_cf7_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php
			esc_html_e(
				'CF7 Registrations Manager requer o plugin Contact Form 7 ativo para funcionar corretamente.',
				'music-club-registrations'
			);
			?>
		</p>
	</div>
	<?php
}

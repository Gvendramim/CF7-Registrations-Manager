<?php
/**
 * Plugin Name:       Music Club Registrations
 * Description:       Captura e gerencia automaticamente as inscrições enviadas por qualquer formulário do Contact Form 7 escolhido pelo administrador, armazenando os dados em uma tabela própria, com dashboard, mapeamento de campos configurável, painel administrativo completo, sistema de logs, exportação e API REST — sem nenhum ID de formulário ou nome de campo fixo no código.
 * Version:           1.0.0
 * Requires PHP:      8.1
 * Author:            Gabriel Vendramim

 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MCR_VERSION', '1.0.0' );

define( 'MCR_PLUGIN_FILE', __FILE__ );
define( 'MCR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MCR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MCR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Nome das tabelas (sem prefixo, o prefixo é resolvido em runtime via $wpdb->prefix).
define( 'MCR_TABLE_NAME', 'music_club_registrations' );
define( 'MCR_HISTORY_TABLE_NAME', 'music_club_registration_history' );

// Versão do schema de banco de dados, usada para acionar dbDelta() em updates futuros.
define( 'MCR_DB_VERSION', '1.0.0' );

// Se definido como true, o uninstall.php removerá as tabelas e dados do
// plugin, independentemente da opção configurada na tela de Settings.
// Esta constante é opcional e serve apenas como uma forma avançada de
// forçar a remoção (ex: em ambientes de CI/staging) a partir de
// wp-config.php. Por padrão, a decisão fica inteiramente a cargo da opção
// "Remove data on uninstall" salva na tela de Settings do plugin - nenhum
// valor fica fixo no código.
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
require_once MCR_PLUGIN_DIR . 'includes/class-form-handler.php';
require_once MCR_PLUGIN_DIR . 'includes/class-list-table.php';
require_once MCR_PLUGIN_DIR . 'includes/class-admin.php';
require_once MCR_PLUGIN_DIR . 'includes/class-export.php';
require_once MCR_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once MCR_PLUGIN_DIR . 'includes/class-loader.php';

/**
 * -----------------------------------------------------------------------
 * Ativação / Desativação
 * -----------------------------------------------------------------------
 */

/**
 * Executado na ativação do plugin.
 *
 * Cria (ou atualiza) a estrutura de banco de dados necessária.
 * Nunca recria a tabela caso ela já exista - dbDelta() cuida disso
 * de forma segura, aplicando apenas alterações incrementais.
 *
 * @return void
 */
function mcr_activate_plugin() {
	Music_Club_Registrations\Database::install();

	// Garante que as regras de rewrite da REST API sejam atualizadas.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'mcr_activate_plugin' );

/**
 * Executado na desativação do plugin.
 *
 * Não remove dados nem tabelas - isso só acontece em uninstall.php
 * e apenas se explicitamente configurado.
 *
 * @return void
 */
function mcr_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'mcr_deactivate_plugin' );

/**
 * -----------------------------------------------------------------------
 * Inicialização do plugin
 * -----------------------------------------------------------------------
 */

/**
 * Inicializa o plugin depois que todos os plugins foram carregados,
 * garantindo que o Contact Form 7 (dependência externa) já esteja disponível.
 *
 * @return void
 */
function mcr_init_plugin() {
	// Carrega o textdomain para traduções.
	load_plugin_textdomain( 'music-club-registrations', false, dirname( MCR_PLUGIN_BASENAME ) . '/languages' );

	// Verifica dependência do Contact Form 7 antes de inicializar os hooks de captura.
	if ( ! defined( 'WPCF7_VERSION' ) ) {
		add_action( 'admin_notices', 'mcr_missing_cf7_notice' );
		return;
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
				'Music Club Registrations requer o plugin Contact Form 7 ativo para funcionar corretamente.',
				'music-club-registrations'
			);
			?>
		</p>
	</div>
	<?php
}

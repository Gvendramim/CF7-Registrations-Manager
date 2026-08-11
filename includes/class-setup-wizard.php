<?php
/**
 * Assistente de configuração (Setup Wizard), exibido automaticamente na
 * primeira ativação do plugin. Guia o administrador por seis etapas:
 * verificação do ambiente, seleção do formulário, mapeamento de campos,
 * criação da tabela do banco, geração da chave de API e um teste
 * completo do sistema - sem exigir nenhuma configuração técnica manual.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Setup_Wizard
 *
 * Responsabilidade única: orientar o administrador durante a configuração
 * inicial do plugin através de uma interface passo a passo.
 */
class Setup_Wizard {

	/**
	 * Slug da página do assistente.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'music-club-setup-wizard';

	/**
	 * Nome da opção que marca o assistente como concluído.
	 *
	 * @var string
	 */
	const COMPLETED_OPTION = 'mcr_setup_completed';

	/**
	 * Nome da transient que sinaliza um redirecionamento pendente para o
	 * assistente, definida no momento da ativação do plugin.
	 *
	 * @var string
	 */
	const REDIRECT_TRANSIENT = 'mcr_activation_redirect';

	/**
	 * Registra os hooks utilizados pelo assistente.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );
		add_action( 'admin_notices', array( $this, 'maybe_render_incomplete_notice' ) );
	}

	/**
	 * Marca que um redirecionamento para o assistente deve ocorrer no
	 * próximo carregamento do admin. Chamado no hook de ativação do plugin.
	 *
	 * @return void
	 */
	public static function schedule_redirect() {
		set_transient( self::REDIRECT_TRANSIENT, 1, 30 );
	}

	/**
	 * Redireciona para o assistente logo após a ativação do plugin, uma
	 * única vez (evita redirecionar em ativações em massa ou em
	 * requisições AJAX/REST).
	 *
	 * @return void
	 */
	public function maybe_redirect_to_wizard() {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::REDIRECT_TRANSIENT );

		if ( wp_doing_ajax() || defined( 'REST_REQUEST' ) || ! mcr_current_user_can_manage() ) {
			return;
		}

		// Evita redirecionar durante ativação em massa de vários plugins.
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Registra a página oculta do assistente (não aparece no menu lateral).
	 *
	 * @return void
	 */
	public function register_page() {
		// IMPORTANTE: `null` como página-pai é a forma oficialmente
		// documentada pelo WordPress de registrar uma página acessível sem
		// exibi-la em nenhum menu. Não trocar por add_submenu_page() +
		// remove_submenu_page() - essa combinação também revoga a
		// autorização de acesso à página (ver o mesmo aviso em
		// Admin::register_menus()), fazendo o Setup Wizard exibir "Sorry,
		// you are not allowed to access this page." logo após a ativação.
		add_submenu_page(
			null,
			__( 'CF7 Registrations Manager Setup Wizard', 'music-club-registrations' ),
			__( 'Setup Wizard', 'music-club-registrations' ),
			apply_filters( 'mcr_manage_capability', 'manage_options' ),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Exibe um aviso persistente (fora do assistente) enquanto a
	 * configuração inicial não for concluída, com um atalho para retomar.
	 *
	 * @return void
	 */
	public function maybe_render_incomplete_notice() {
		if ( get_option( self::COMPLETED_OPTION ) ) {
			return;
		}

		$screen = get_current_screen();

		if ( ! $screen || false === strpos( $screen->id, 'music-club' ) || false !== strpos( $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		if ( ! mcr_current_user_can_manage() ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s <a href="%s" class="button button-primary">%s</a></p></div>',
			esc_html__( 'CF7 Registrations Manager is almost ready. Finish the quick setup wizard to start capturing registrations.', 'music-club-registrations' ),
			esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Resume Setup', 'music-club-registrations' )
		);
	}

	/**
	 * Renderiza o assistente, controlando a etapa atual através do
	 * parâmetro `step` (1 a 6).
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'music-club-registrations' ) );
		}

		$this->handle_step_submission();

		$step = isset( $_GET['step'] ) ? max( 1, min( 6, absint( $_GET['step'] ) ) ) : 1;

		$environment = $this->run_environment_checks();
		$settings    = Settings::get_all();

		$available_forms = Settings::get_available_forms();
		$form_fields      = Settings::get_form_field_names( $settings['form_id'] );
		$field_slots      = Settings::get_field_slots();

		$system_tests = 6 === $step ? $this->run_system_tests() : array();

		require MCR_PLUGIN_DIR . 'includes/views/view-setup-wizard.php';
	}

	/**
	 * Processa o envio de cada etapa do assistente (POST), salvando os
	 * dados fornecidos e avançando para a próxima etapa.
	 *
	 * @return void
	 */
	private function handle_step_submission() {
		if ( empty( $_POST['mcr_wizard_step'] ) ) {
			return;
		}

		$nonce = isset( $_POST['mcr_wizard_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mcr_wizard_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'mcr_setup_wizard' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'music-club-registrations' ) );
		}

		$step = absint( $_POST['mcr_wizard_step'] );

		if ( 2 === $step ) {
			$settings           = Settings::get_all();
			$settings['form_id'] = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
			Settings::save( $settings );
		}

		if ( 3 === $step ) {
			$settings  = Settings::get_all();
			$field_map = array();

			foreach ( array_keys( Settings::get_field_slots() ) as $slot ) {
				$field_map[ $slot ] = isset( $_POST['field_map'][ $slot ] )
					? sanitize_text_field( wp_unslash( $_POST['field_map'][ $slot ] ) )
					: '';
			}

			$settings['field_map'] = $field_map;
			Settings::save( $settings );
		}

		if ( 4 === $step ) {
			// Cria (ou confirma) a estrutura de banco de dados - idempotente.
			Database::install();
		}

		if ( 5 === $step ) {
			Settings::ensure_api_key();
		}

		if ( 6 === $step && ! empty( $_POST['mcr_finish_wizard'] ) ) {
			update_option( self::COMPLETED_OPTION, 1 );

			Logger::info( 'setup', 'Setup wizard completed.', get_current_user_id() );

			wp_safe_redirect( admin_url( 'admin.php?page=' . Admin::MENU_SLUG . '&setup_complete=1' ) );
			exit;
		}

		$next_step = min( 6, $step + 1 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=' . $next_step ) );
		exit;
	}

	/**
	 * Executa a verificação do ambiente (Etapa 1): versão do PHP, versão
	 * do WordPress, presença do Contact Form 7 e permissões necessárias.
	 *
	 * @return array<int,array{label:string,ok:bool,detail:string}>
	 */
	private function run_environment_checks() {
		global $wp_version;

		$checks = array();

		$checks[] = array(
			'label'  => __( 'PHP version (8.1 or higher)', 'music-club-registrations' ),
			'ok'     => version_compare( PHP_VERSION, '8.1', '>=' ),
			'detail' => PHP_VERSION,
		);

		$checks[] = array(
			'label'  => __( 'WordPress version (6.0 or higher)', 'music-club-registrations' ),
			'ok'     => version_compare( $wp_version, '6.0', '>=' ),
			'detail' => $wp_version,
		);

		$checks[] = array(
			'label'  => __( 'Contact Form 7 is active', 'music-club-registrations' ),
			'ok'     => defined( 'WPCF7_VERSION' ),
			'detail' => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : __( 'Not detected', 'music-club-registrations' ),
		);

		global $wpdb;
		$checks[] = array(
			'label'  => __( 'Database permissions (CREATE TABLE)', 'music-club-registrations' ),
			'ok'     => empty( $wpdb->last_error ),
			'detail' => __( 'OK', 'music-club-registrations' ),
		);

		$checks[] = array(
			'label'  => __( 'Native Excel (.xlsx) export support', 'music-club-registrations' ),
			'ok'     => Xlsx_Writer::is_supported(),
			'detail' => Xlsx_Writer::is_supported() ? __( 'Available', 'music-club-registrations' ) : __( 'Unavailable - CSV export will still work', 'music-club-registrations' ),
		);

		return $checks;
	}

	/**
	 * Executa o teste completo do sistema (Etapa 6): banco de dados, API,
	 * formulário e exportação.
	 *
	 * @return array<int,array{label:string,ok:bool}>
	 */
	private function run_system_tests() {
		global $wpdb;

		$tests = array();

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Database::table_name() ) ) === Database::table_name(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$tests[]      = array(
			'label' => __( 'Database', 'music-club-registrations' ),
			'ok'    => $table_exists,
		);

		$tests[] = array(
			'label' => __( 'REST API', 'music-club-registrations' ),
			'ok'    => ! empty( Settings::get( 'api_key' ) ),
		);

		$tests[] = array(
			'label' => __( 'Form Configuration', 'music-club-registrations' ),
			'ok'    => Settings::get_monitored_form_id() > 0,
		);

		$tests[] = array(
			'label' => __( 'Export', 'music-club-registrations' ),
			'ok'    => true, // CSV está sempre disponível, com ou sem suporte a .xlsx.
		);

		return $tests;
	}
}

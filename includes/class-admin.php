<?php
/**
 * Classe responsável pela área administrativa do plugin: menus, telas de
 * dashboard, listagem, detalhes, configurações e logs, além do
 * processamento de ações do admin.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 *
 * Responsabilidade única: interface administrativa (UI) do plugin.
 * A lógica de negócio permanece nas classes Database, Settings e Logger.
 */
class Admin {

	/**
	 * Slug do menu principal (Dashboard).
	 *
	 * @var string
	 */
	const MENU_SLUG = 'music-club-registrations';

	/**
	 * Slug da tela de listagem de inscrições.
	 *
	 * @var string
	 */
	const LIST_SLUG = 'music-club-registration-list';

	/**
	 * Slug da tela de detalhes.
	 *
	 * @var string
	 */
	const DETAIL_SLUG = 'music-club-registration-detail';

	/**
	 * Slug da tela de configurações.
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = 'music-club-registration-settings';

	/**
	 * Slug da tela de logs.
	 *
	 * @var string
	 */
	const LOGS_SLUG = 'music-club-registration-logs';

	/**
	 * Registra os hooks de admin utilizados por esta classe.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_notices', array( $this, 'render_admin_notices' ) );
	}

	/**
	 * Registra o menu "Music Club" e seus submenus no painel: Dashboard,
	 * Registrations, Settings e Logs.
	 *
	 * @return void
	 */
	public function register_menus() {
		$capability = apply_filters( 'mcr_manage_capability', 'manage_options' );

		add_menu_page(
			__( 'Music Club', 'music-club-registrations' ),
			__( 'Music Club', 'music-club-registrations' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-format-audio',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'music-club-registrations' ),
			__( 'Dashboard', 'music-club-registrations' ),
			$capability,
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Registrations', 'music-club-registrations' ),
			__( 'Registrations', 'music-club-registrations' ),
			$capability,
			self::LIST_SLUG,
			array( $this, 'render_list_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'music-club-registrations' ),
			__( 'Settings', 'music-club-registrations' ),
			$capability,
			self::SETTINGS_SLUG,
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Logs', 'music-club-registrations' ),
			__( 'Logs', 'music-club-registrations' ),
			$capability,
			self::LOGS_SLUG,
			array( $this, 'render_logs_page' )
		);

		// Página oculta (não aparece no menu) usada pela tela de detalhes.
		add_submenu_page(
			null, // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks -- página intencionalmente oculta do menu.
			__( 'Registration Details', 'music-club-registrations' ),
			__( 'Registration Details', 'music-club-registrations' ),
			$capability,
			self::DETAIL_SLUG,
			array( $this, 'render_detail_page' )
		);
	}

	/**
	 * Carrega CSS/JS apenas nas telas do plugin, evitando peso desnecessário
	 * em outras páginas do admin.
	 *
	 * @param string $hook Hook da tela atual.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( ! $this->is_plugin_screen() ) {
			return;
		}

		wp_enqueue_style(
			'mcr-admin',
			MCR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MCR_VERSION
		);

		wp_enqueue_script(
			'mcr-admin',
			MCR_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			MCR_VERSION,
			true
		);

		wp_localize_script(
			'mcr-admin',
			'MCRAdmin',
			array(
				'confirmDelete' => __( 'Are you sure you want to delete this registration? This action cannot be undone.', 'music-club-registrations' ),
				'confirmBulk'   => __( 'Are you sure you want to apply this action to the selected registrations?', 'music-club-registrations' ),
				'confirmClearLogs' => __( 'Are you sure you want to clear all logs? This action cannot be undone.', 'music-club-registrations' ),
			)
		);

		// Chart.js e o script do dashboard são carregados apenas na tela de Dashboard.
		if ( $this->is_dashboard_screen() ) {
			wp_enqueue_script(
				'mcr-chartjs',
				MCR_PLUGIN_URL . 'assets/js/chart.umd.min.js',
				array(),
				MCR_VERSION,
				true
			);

			wp_enqueue_script(
				'mcr-dashboard',
				MCR_PLUGIN_URL . 'assets/js/dashboard.js',
				array( 'mcr-chartjs' ),
				MCR_VERSION,
				true
			);

			wp_localize_script( 'mcr-dashboard', 'MCRDashboardData', $this->get_dashboard_chart_data() );
		}
	}

	/**
	 * Monta os dados utilizados pelos gráficos Chart.js do dashboard.
	 *
	 * @return array
	 */
	private function get_dashboard_chart_data() {
		$series  = Database::get_registrations_per_day( 30 );
		$stats   = Database::get_dashboard_stats();
		$statuses = mcr_get_statuses();

		return array(
			'timeline' => array(
				'labels' => wp_list_pluck( $series, 'date' ),
				'values' => wp_list_pluck( $series, 'count' ),
			),
			'statusChart' => array(
				'labels' => array_values( $statuses ),
				'values' => array_values( $stats['by_status'] ),
			),
		);
	}

	/**
	 * Verifica se a tela atual pertence ao plugin (usado para carregar assets
	 * apenas quando necessário).
	 *
	 * @return bool
	 */
	private function is_plugin_screen() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return false !== strpos( $screen->id, 'music-club' );
	}

	/**
	 * Verifica se a tela atual é o Dashboard do plugin.
	 *
	 * @return bool
	 */
	private function is_dashboard_screen() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return false;
		}

		return false !== strpos( $screen->id, self::MENU_SLUG ) && false === strpos( $screen->id, self::LIST_SLUG );
	}

	/**
	 * Renderiza a tela de Dashboard com indicadores e gráficos.
	 *
	 * @return void
	 */
	public function render_dashboard_page() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'music-club-registrations' ) );
		}

		$stats           = Database::get_dashboard_stats();
		$monitored_form  = $this->get_monitored_form_label();

		require MCR_PLUGIN_DIR . 'includes/views/view-dashboard.php';
	}

	/**
	 * Retorna um rótulo amigável do formulário atualmente monitorado, para
	 * exibição no dashboard.
	 *
	 * @return string
	 */
	private function get_monitored_form_label() {
		$form_id = Settings::get_monitored_form_id();

		if ( ! $form_id ) {
			return __( 'No form configured yet', 'music-club-registrations' );
		}

		$title = get_the_title( $form_id );

		return $title ? sprintf( '%s (#%d)', $title, $form_id ) : sprintf( '#%d', $form_id );
	}

	/**
	 * Renderiza a tela de listagem de inscrições.
	 *
	 * @return void
	 */
	public function render_list_page() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'music-club-registrations' ) );
		}

		$list_table = new List_Table();
		$list_table->prepare_items();

		$current_search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$current_status  = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
		$export_columns  = Export::get_all_columns();

		require MCR_PLUGIN_DIR . 'includes/views/view-list.php';
	}

	/**
	 * Renderiza a tela de detalhes de uma inscrição.
	 *
	 * @return void
	 */
	public function render_detail_page() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'music-club-registrations' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- leitura simples de ID para exibição.

		$registration = $id ? Database::get_registration( $id ) : null;

		if ( ! $registration ) {
			wp_die( esc_html__( 'Registration not found.', 'music-club-registrations' ) );
		}

		$history = Database::get_history( $id );

		require MCR_PLUGIN_DIR . 'includes/views/view-detail.php';
	}

	/**
	 * Renderiza a tela de configurações do plugin.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'music-club-registrations' ) );
		}

		$settings        = Settings::get_all();

		// Permite pré-visualizar os campos de um formulário diferente do
		// atualmente salvo, antes de confirmar o mapeamento e salvar. O botão
		// "Load Fields" reenvia o formulário via GET, então tanto o alias
		// "form_id" (nome do campo <select>) quanto "preview_form_id" são
		// aceitos.
		$preview_form_id = $settings['form_id'];
		if ( isset( $_GET['form_id'] ) ) {
			$preview_form_id = absint( $_GET['form_id'] );
		} elseif ( isset( $_GET['preview_form_id'] ) ) {
			$preview_form_id = absint( $_GET['preview_form_id'] );
		}

		$available_forms = Settings::get_available_forms();
		$form_fields      = Settings::get_form_field_names( $preview_form_id );
		$field_slots      = Settings::get_field_slots();
		$statuses         = mcr_get_statuses();
		$rest_namespace   = REST_API::NAMESPACE_NAME;

		require MCR_PLUGIN_DIR . 'includes/views/view-settings.php';
	}

	/**
	 * Renderiza a tela de visualização de logs.
	 *
	 * @return void
	 */
	public function render_logs_page() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'music-club-registrations' ) );
		}

		$current_page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$current_level = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';

		$result = Logger::query_logs(
			array(
				'level'    => $current_level,
				'per_page' => 30,
				'page'     => $current_page,
			)
		);

		$logs       = $result['items'];
		$total_logs = $result['total'];
		$per_page   = 30;
		$total_pages = (int) ceil( $total_logs / $per_page );

		require MCR_PLUGIN_DIR . 'includes/views/view-logs.php';
	}

	/**
	 * Processa as ações administrativas: salvar status/observações, excluir,
	 * ações em massa, salvar configurações e limpar logs.
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! is_admin() || ! mcr_current_user_can_manage() ) {
			return;
		}

		$this->handle_save_detail();
		$this->handle_delete_single();
		$this->handle_bulk_actions();
		$this->handle_save_settings();
		$this->handle_regenerate_api_key();
		$this->handle_clear_logs();
	}

	/**
	 * Processa o salvamento completo das configurações do plugin: formulário
	 * monitorado, mapeamento de campos, status padrão, prevenção de
	 * duplicidade, remoção de dados e ativação da exportação em Excel.
	 *
	 * @return void
	 */
	private function handle_save_settings() {
		if ( empty( $_POST['mcr_save_settings'] ) ) {
			return;
		}

		$nonce = isset( $_POST['mcr_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mcr_settings_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'mcr_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'music-club-registrations' ) );
		}

		$raw_field_map = isset( $_POST['field_map'] ) && is_array( $_POST['field_map'] )
			? wp_unslash( $_POST['field_map'] )
			: array();

		$input = array(
			'form_id'                  => isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0,
			'field_map'                => $raw_field_map,
			'default_status'           => isset( $_POST['default_status'] ) ? sanitize_text_field( wp_unslash( $_POST['default_status'] ) ) : 'new',
			'duplicate_check_enabled'  => ! empty( $_POST['duplicate_check_enabled'] ),
			'duplicate_window_minutes' => isset( $_POST['duplicate_window_minutes'] ) ? absint( $_POST['duplicate_window_minutes'] ) : 5,
			'api_key'                  => Settings::get( 'api_key', '' ),
			'remove_data_on_uninstall' => ! empty( $_POST['remove_data_on_uninstall'] ),
			'enable_excel_export'      => ! empty( $_POST['enable_excel_export'] ),
		);

		Settings::save( $input );

		Logger::info( 'settings', 'Plugin settings updated.', get_current_user_id() );

		$redirect = add_query_arg(
			array(
				'page'    => self::SETTINGS_SLUG,
				'updated' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Processa a regeneração manual da chave de API.
	 *
	 * @return void
	 */
	private function handle_regenerate_api_key() {
		if ( empty( $_POST['mcr_regenerate_api_key'] ) ) {
			return;
		}

		$nonce = isset( $_POST['mcr_settings_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mcr_settings_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'mcr_save_settings' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'music-club-registrations' ) );
		}

		$settings            = Settings::get_all();
		$settings['api_key'] = Settings::generate_api_key();
		Settings::save( $settings );

		Logger::info( 'settings', 'REST API key regenerated.', get_current_user_id() );

		$redirect = add_query_arg(
			array(
				'page'          => self::SETTINGS_SLUG,
				'key_regenerated' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Processa a limpeza de todos os logs registrados.
	 *
	 * @return void
	 */
	private function handle_clear_logs() {
		if ( empty( $_POST['mcr_clear_logs'] ) ) {
			return;
		}

		check_admin_referer( 'mcr_clear_logs' );

		Logger::clear();

		$redirect = add_query_arg(
			array(
				'page'         => self::LOGS_SLUG,
				'logs_cleared' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Processa o salvamento da tela de detalhes (status + observações internas).
	 *
	 * @return void
	 */
	private function handle_save_detail() {
		if ( empty( $_POST['mcr_save_detail'] ) ) {
			return;
		}

		$nonce = isset( $_POST['mcr_detail_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['mcr_detail_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'mcr_save_detail' ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'music-club-registrations' ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		if ( ! $id ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( isset( $_POST['status'] ) ) {
			$status = sanitize_text_field( wp_unslash( $_POST['status'] ) );
			Database::update_status( $id, $status, $user_id );
		}

		if ( isset( $_POST['internal_notes'] ) ) {
			$notes = sanitize_textarea_field( wp_unslash( $_POST['internal_notes'] ) );
			Database::update_internal_notes( $id, $notes, $user_id );
		}

		$redirect = add_query_arg(
			array(
				'page'    => self::DETAIL_SLUG,
				'id'      => $id,
				'updated' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Processa a exclusão de um único registro (ação de linha na listagem
	 * ou botão "Excluir" na tela de detalhes).
	 *
	 * @return void
	 */
	private function handle_delete_single() {
		$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';

		if ( 'delete' !== $action || empty( $_REQUEST['id'] ) ) {
			return;
		}

		$id    = absint( $_REQUEST['id'] );
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'mcr_delete_registration_' . $id ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'music-club-registrations' ) );
		}

		Database::delete_registration( $id );

		$redirect = add_query_arg(
			array(
				'page'    => self::LIST_SLUG,
				'deleted' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Processa as ações em massa disparadas a partir do WP_List_Table
	 * (alterar status ou excluir múltiplos registros de uma vez).
	 *
	 * @return void
	 */
	private function handle_bulk_actions() {
		if ( empty( $_REQUEST['registration_ids'] ) ) {
			return;
		}

		$action = $this->get_current_bulk_action();

		if ( ! $action ) {
			return;
		}

		check_admin_referer( 'bulk-registrations' );

		$ids     = array_map( 'absint', (array) wp_unslash( $_REQUEST['registration_ids'] ) );
		$user_id = get_current_user_id();

		$status_map = array(
			'mark_contacted' => 'contacted',
			'mark_confirmed' => 'confirmed',
			'mark_cancelled' => 'cancelled',
		);

		if ( isset( $status_map[ $action ] ) ) {
			foreach ( $ids as $id ) {
				Database::update_status( $id, $status_map[ $action ], $user_id );
			}
		} elseif ( 'delete' === $action ) {
			foreach ( $ids as $id ) {
				Database::delete_registration( $id );
			}
		} else {
			return;
		}

		$redirect = add_query_arg(
			array(
				'page'         => self::LIST_SLUG,
				'bulk_updated' => 1,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Resolve a ação em massa selecionada, considerando os dois seletores
	 * do WP_List_Table (topo e rodapé).
	 *
	 * @return string
	 */
	private function get_current_bulk_action() {
		$action  = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action2'] ) ) : '';

		if ( $action && '-1' !== $action ) {
			return $action;
		}

		if ( $action2 && '-1' !== $action2 ) {
			return $action2;
		}

		return '';
	}

	/**
	 * Exibe avisos administrativos de sucesso após ações do usuário.
	 *
	 * @return void
	 */
	public function render_admin_notices() {
		$screen = get_current_screen();

		if ( ! $screen || ! $this->is_plugin_screen() ) {
			return;
		}

		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved successfully.', 'music-club-registrations' ) . '</p></div>';
		}

		if ( ! empty( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Registration deleted successfully.', 'music-club-registrations' ) . '</p></div>';
		}

		if ( ! empty( $_GET['bulk_updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Bulk action applied successfully.', 'music-club-registrations' ) . '</p></div>';
		}

		if ( ! empty( $_GET['key_regenerated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'A new API key has been generated.', 'music-club-registrations' ) . '</p></div>';
		}

		if ( ! empty( $_GET['logs_cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Logs cleared successfully.', 'music-club-registrations' ) . '</p></div>';
		}

		if ( self::SETTINGS_SLUG === ( $_GET['page'] ?? '' ) && ! Settings::get_monitored_form_id() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No Contact Form 7 form is configured yet. Select a form below and map its fields to start capturing registrations.', 'music-club-registrations' ) . '</p></div>';
		}
	}
}

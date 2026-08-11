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
		add_action( 'admin_post_mcr_download_logs', array( $this, 'handle_download_logs' ) );
		add_action( 'admin_post_mcr_test_rest_api', array( $this, 'handle_test_rest_api' ) );
		add_action( 'current_screen', array( $this, 'register_contextual_help' ) );
	}

	/**
	 * Adiciona ajuda contextual (aba "Help" no canto superior direito) em
	 * cada tela do plugin, orientando o administrador sem sair da página.
	 *
	 * @param \WP_Screen $screen Tela atual do admin.
	 * @return void
	 */
	public function register_contextual_help( $screen ) {
		if ( ! $screen || false === strpos( $screen->id, 'music-club' ) ) {
			return;
		}

		$help_content = array(
			self::MENU_SLUG      => __( 'The Dashboard gives you a quick overview of your registrations: totals, recent activity and status breakdown. Use the charts to spot trends at a glance.', 'music-club-registrations' ),
			self::LIST_SLUG       => __( 'Search, filter, sort and manage registrations here. Select rows with the checkboxes to apply bulk actions or export just those rows.', 'music-club-registrations' ),
			self::SETTINGS_SLUG    => __( 'Choose which Contact Form 7 form to monitor, map its fields, configure duplicate prevention, connect Excel Online, and manage backups — all without touching any code.', 'music-club-registrations' ),
			self::LOGS_SLUG        => __( 'Every important event (errors, exports, status changes) is recorded here. Use the filters to narrow down results, or download them as a CSV file.', 'music-club-registrations' ),
			Setup_Wizard::PAGE_SLUG => __( 'This wizard walks you through the one-time setup: checking your environment, selecting a form, mapping its fields and confirming everything works.', 'music-club-registrations' ),
		);

		// Escolhe a correspondência mais específica (slug mais longo) em
		// vez da primeira encontrada - necessário porque o nome da tela de
		// páginas como Detalhes ou o Setup Wizard também contém o slug do
		// menu principal (por serem registradas como seus submenus), então
		// uma verificação simples de "contém" poderia casar com o item
		// errado.
		$matched_slug = '';
		foreach ( array_keys( $help_content ) as $slug ) {
			if ( false !== strpos( $screen->id, $slug ) && strlen( $slug ) > strlen( $matched_slug ) ) {
				$matched_slug = $slug;
			}
		}

		if ( '' !== $matched_slug ) {
			$screen->add_help_tab(
				array(
					'id'      => 'mcr-help-' . $matched_slug,
					'title'   => __( 'About This Screen', 'music-club-registrations' ),
					'content' => '<p>' . esc_html( $help_content[ $matched_slug ] ) . '</p>',
				)
			);
		}
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
			__( 'CF7 Registrations', 'music-club-registrations' ),
			__( 'CF7 Registrations', 'music-club-registrations' ),
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
		//
		// IMPORTANTE: usamos `null` como página-pai de propósito. É a forma
		// oficialmente documentada pelo WordPress de registrar uma página
		// de admin acessível sem exibi-la em nenhum menu.
		//
		// Uma versão anterior deste arquivo tentava "esconder" esta página
		// registrando-a como submenu normal e chamando
		// remove_submenu_page() em seguida - isso parecia funcionar, mas
		// remove_submenu_page() também apaga a entrada que o WordPress usa
		// internamente (user_can_access_admin_page()) para AUTORIZAR o
		// acesso à página, então a página passava a exibir "Sorry, you are
		// not allowed to access this page." para todo mundo, inclusive
		// administradores. Não repita esse erro.
		add_submenu_page(
			null,
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
				'confirmDelete'      => __( 'Are you sure you want to delete this registration? This action cannot be undone.', 'music-club-registrations' ),
				'confirmBulk'        => __( 'Are you sure you want to apply this action to the selected registrations?', 'music-club-registrations' ),
				'confirmClearLogs'   => __( 'Are you sure you want to clear all logs? This action cannot be undone.', 'music-club-registrations' ),
				'toastSaved'         => __( 'Saved successfully.', 'music-club-registrations' ),
				'toastDeleted'       => __( 'Deleted successfully.', 'music-club-registrations' ),
				'toastSetupComplete' => __( 'Setup complete! The plugin is ready.', 'music-club-registrations' ),
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
		$series        = Database::get_registrations_per_day( 30 );
		$monthly       = Database::get_registrations_per_month( 12 );
		$by_class      = Database::get_registrations_by_class( 10 );
		$by_interest   = Database::get_registrations_by_interest( 10 );
		$stats         = Database::get_dashboard_stats();
		$statuses      = mcr_get_statuses();

		return array(
			'timeline'    => array(
				'labels' => wp_list_pluck( $series, 'date' ),
				'values' => wp_list_pluck( $series, 'count' ),
			),
			'monthly'     => array(
				'labels' => wp_list_pluck( $monthly, 'month' ),
				'values' => wp_list_pluck( $monthly, 'count' ),
			),
			'byClass'     => array(
				'labels' => wp_list_pluck( $by_class, 'class' ),
				'values' => wp_list_pluck( $by_class, 'count' ),
			),
			'byInterest'  => array(
				'labels' => wp_list_pluck( $by_interest, 'interest' ),
				'values' => wp_list_pluck( $by_interest, 'count' ),
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
		$excel_sync_stats = Database::get_sync_stats();
		$excel_connected  = Excel_OAuth::is_fully_configured();

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

		$settings     = Settings::get_all();
		$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		$allowed_tabs = array( 'general', 'excel', 'backup', 'advanced' );
		if ( ! in_array( $active_tab, $allowed_tabs, true ) ) {
			$active_tab = 'general';
		}

		// A aba "Advanced > Microsoft Integration" contém credenciais
		// sensíveis (Client ID/Secret do app Microsoft) e deve ficar
		// disponível apenas para administradores com a capability mais
		// alta do WordPress - independentemente do filtro
		// `mcr_manage_capability`, que pode ter sido reduzido para outros
		// papéis terem acesso ao restante do plugin.
		if ( 'advanced' === $active_tab && ! current_user_can( 'manage_options' ) ) {
			$active_tab = 'general';
		}

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

		$rest_test_result = get_transient( 'mcr_rest_test_result_' . get_current_user_id() );
		delete_transient( 'mcr_rest_test_result_' . get_current_user_id() );

		$backup_notice = get_transient( 'mcr_backup_notice_' . get_current_user_id() );
		delete_transient( 'mcr_backup_notice_' . get_current_user_id() );

		// Dados exclusivos da aba "Excel Online" (só precisam ser
		// calculados quando ela está ativa, para evitar chamadas
		// desnecessárias à Microsoft Graph API nas demais abas).
		$ms_connection    = array();
		$ms_workbooks     = array();
		$ms_worksheets    = array();
		$ms_tables        = array();
		$ms_test_result   = null;
		$ms_sync_summary  = null;
		$ms_redirect_uri  = '';
		$ms_app_configured = Settings::is_excel_app_configured();

		if ( 'excel' === $active_tab ) {
			$ms_redirect_uri = Excel_OAuth::get_redirect_uri();
			$ms_connection   = Excel_OAuth::get_connection();

			if ( Excel_OAuth::is_connected() ) {
				$workbooks = Excel_Graph::list_workbooks();
				$ms_workbooks = is_wp_error( $workbooks ) ? array() : $workbooks;

				if ( ! empty( $ms_connection['drive_id'] ) && ! empty( $ms_connection['item_id'] ) ) {
					$worksheets    = Excel_Graph::list_worksheets( $ms_connection['drive_id'], $ms_connection['item_id'] );
					$ms_worksheets = is_wp_error( $worksheets ) ? array() : $worksheets;
				}

				if ( ! empty( $ms_connection['worksheet_name'] ) ) {
					$tables    = Excel_Graph::list_tables( $ms_connection['drive_id'], $ms_connection['item_id'], $ms_connection['worksheet_name'] );
					$ms_tables = is_wp_error( $tables ) ? array() : $tables;
				}
			}

			$ms_test_result  = get_transient( 'mcr_ms_test_result_' . get_current_user_id() );
			delete_transient( 'mcr_ms_test_result_' . get_current_user_id() );

			$ms_sync_summary = get_transient( 'mcr_ms_sync_summary_' . get_current_user_id() );
			delete_transient( 'mcr_ms_sync_summary_' . get_current_user_id() );
		}

		$ms_sync_stats = Database::get_sync_stats();

		require_once MCR_PLUGIN_DIR . 'includes/views/view-settings.php';
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

		$current_page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$current_level   = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
		$current_context = isset( $_GET['context'] ) ? sanitize_text_field( wp_unslash( $_GET['context'] ) ) : '';
		$current_search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		$query_args = array(
			'level'    => $current_level,
			'context'  => $current_context,
			'search'   => $current_search,
			'per_page' => 30,
			'page'     => $current_page,
		);

		$result = Logger::query_logs( $query_args );

		$logs             = $result['items'];
		$total_logs       = $result['total'];
		$per_page         = 30;
		$total_pages      = (int) ceil( $total_logs / $per_page );
		$available_contexts = Logger::get_distinct_contexts();
		$download_nonce   = wp_create_nonce( 'mcr_download_logs' );

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

		// Cada aba da tela de Settings envia apenas os campos que ela
		// exibe. Sem essa distinção, salvar a aba "Excel Integration", por
		// exemplo, apagaria o formulário monitorado e o mapeamento de
		// campos configurados na aba "General" (que não são reenviados
		// nesse formulário). Partimos sempre das configurações já salvas e
		// sobrescrevemos apenas a seção correspondente.
		$section = isset( $_POST['settings_section'] ) ? sanitize_key( wp_unslash( $_POST['settings_section'] ) ) : 'general';
		$input   = Settings::get_all();

		if ( 'general' === $section ) {
			$raw_field_map = isset( $_POST['field_map'] ) && is_array( $_POST['field_map'] )
				? wp_unslash( $_POST['field_map'] )
				: array();

			$input['form_id']                  = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;
			$input['field_map']                = $raw_field_map;
			$input['default_status']           = isset( $_POST['default_status'] ) ? sanitize_text_field( wp_unslash( $_POST['default_status'] ) ) : 'new';
			$input['duplicate_check_enabled']  = ! empty( $_POST['duplicate_check_enabled'] );
			$input['duplicate_window_minutes'] = isset( $_POST['duplicate_window_minutes'] ) ? absint( $_POST['duplicate_window_minutes'] ) : 5;
			$input['remove_data_on_uninstall'] = ! empty( $_POST['remove_data_on_uninstall'] );
			$input['enable_excel_export']      = ! empty( $_POST['enable_excel_export'] );
		} elseif ( 'advanced' === $section ) {
			// Credenciais do app Microsoft (Client ID/Secret), visíveis
			// apenas em Settings > Advanced > Microsoft Integration - o
			// cliente final nunca vê nem precisa preencher esta seção.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
			}

			$input['excel_app'] = isset( $_POST['excel_app'] ) && is_array( $_POST['excel_app'] )
				? wp_unslash( $_POST['excel_app'] )
				: array();
		}

		Settings::save( $input );

		Logger::info( 'settings', sprintf( 'Plugin settings updated (%s).', $section ), get_current_user_id() );

		$redirect = add_query_arg(
			array(
				'page'    => self::SETTINGS_SLUG,
				'tab'     => 'advanced' === $section ? 'advanced' : 'general',
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
	 * Processa o download dos logs filtrados em formato CSV.
	 *
	 * @return void
	 */
	public function handle_download_logs() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		check_admin_referer( 'mcr_download_logs' );

		$args = array(
			'level'   => isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '',
			'context' => isset( $_GET['context'] ) ? sanitize_text_field( wp_unslash( $_GET['context'] ) ) : '',
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);

		$logs = Logger::get_all_matching( $args );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=music-club-logs-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		$handle = fopen( 'php://output', 'w' );
		fwrite( $handle, "\xEF\xBB\xBF" );
		fputcsv( $handle, array( 'Level', 'Context', 'Message', 'User ID', 'Date' ), Export::detect_csv_delimiter() );

		foreach ( $logs as $log ) {
			fputcsv(
				$handle,
				array( $log['level'], $log['context'], $log['message'], $log['user_id'], $log['created_at'] ),
				Export::detect_csv_delimiter()
			);
		}

		fclose( $handle );
		exit;
	}

	/**
	 * Processa o botão "Test Connection" da API REST: realiza uma chamada
	 * real ao endpoint /registrations utilizando a chave de API atual, e
	 * reporta se a autenticação e a rota estão funcionando corretamente.
	 *
	 * @return void
	 */
	public function handle_test_rest_api() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		check_admin_referer( 'mcr_test_rest_api' );

		$url = add_query_arg(
			array( 'api_key' => Settings::get( 'api_key', '' ) ),
			trailingslashit( get_rest_url() ) . REST_API::NAMESPACE_NAME . '/registrations'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			$result = array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		} else {
			$code = wp_remote_retrieve_response_code( $response );
			$result = array(
				'success' => 200 === $code,
				'message' => 200 === $code
					? __( 'REST API is working correctly.', 'music-club-registrations' )
					: sprintf(
						/* translators: %d: HTTP status code */
						__( 'REST API test failed (HTTP %d). Check your permalink settings and API key.', 'music-club-registrations' ),
						$code
					),
			);
		}

		set_transient( 'mcr_rest_test_result_' . get_current_user_id(), $result, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG . '&tab=general' ) );
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

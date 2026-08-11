<?php
/**
 * View: tela de configurações do plugin (com abas: General, Excel
 * Online, Backup e Advanced).
 *
 * Variáveis disponíveis (definidas em Admin::render_settings_page):
 *
 * @var array                 $settings           Configurações atualmente salvas.
 * @var string                $active_tab         Aba ativa ('general', 'excel', 'backup' ou 'advanced').
 * @var int                   $preview_form_id    ID do formulário sendo pré-visualizado no mapeamento.
 * @var array                 $available_forms    Formulários do Contact Form 7 disponíveis no site.
 * @var array<int,string>     $form_fields        Nomes de campo do formulário pré-visualizado.
 * @var array<string,string>  $field_slots        Slots internos de campo (rótulos amigáveis).
 * @var array<string,string>  $statuses           Status disponíveis.
 * @var string                $rest_namespace     Namespace da API REST.
 * @var array|false           $rest_test_result   Resultado do último teste da API REST.
 * @var array|false           $backup_notice      Mensagem pendente sobre importação de backup.
 * @var array                 $ms_connection      Estado da conexão com a Microsoft (Excel_OAuth::get_connection()).
 * @var array                 $ms_workbooks       Workbooks (.xlsx) descobertos via Microsoft Graph.
 * @var array                 $ms_worksheets      Worksheets do workbook selecionado.
 * @var array                 $ms_tables          Tabelas da worksheet selecionada.
 * @var array|false           $ms_test_result     Resultado do último "Test Connection" do Excel Online.
 * @var array|false           $ms_sync_summary    Resumo da última sincronização manual.
 * @var string                $ms_redirect_uri    Redirect URI a cadastrar no Microsoft Entra.
 * @var bool                  $ms_app_configured  Se o app Microsoft (Client ID/Secret) já foi configurado.
 * @var array                 $ms_sync_stats      Indicadores gerais de sincronização (Database::get_sync_stats()).
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings_slug = \Music_Club_Registrations\Admin::SETTINGS_SLUG;
$field_map     = $settings['field_map'];
$rest_base_url = trailingslashit( get_rest_url() ) . $rest_namespace;
$excel_app     = $settings['excel_app'];

/**
 * Monta a URL de uma aba da tela de Settings.
 *
 * @param string $tab Slug da aba.
 * @return string
 */
function mcr_settings_tab_url( $tab ) {
	// Não usar `global $settings_slug` aqui: como esta view é carregada via
	// `require` de dentro de um MÉTODO de classe (Admin::render_settings_page()),
	// as variáveis locais desse método nunca entram no escopo global do
	// PHP, então `global $settings_slug` sempre retornava vazio - fazendo
	// o link gerado perder o parâmetro `page`, e o WordPress não conseguir
	// identificar qual página exibir (resultando em uma tela em branco).
	// Usamos a constante da classe diretamente, que está sempre disponível.
	return add_query_arg(
		array(
			'page' => \Music_Club_Registrations\Admin::SETTINGS_SLUG,
			'tab'  => $tab,
		),
		admin_url( 'admin.php' )
	);
}
?>
<div class="wrap mcr-wrap mcr-settings-wrap">
	<h1><?php esc_html_e( 'CF7 Registrations Manager — Settings', 'music-club-registrations' ); ?></h1>

	<h2 class="nav-tab-wrapper">
		<a href="<?php echo esc_url( mcr_settings_tab_url( 'general' ) ); ?>" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'General', 'music-club-registrations' ); ?>
		</a>
		<a href="<?php echo esc_url( mcr_settings_tab_url( 'excel' ) ); ?>" class="nav-tab <?php echo 'excel' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Excel Online', 'music-club-registrations' ); ?>
		</a>
		<a href="<?php echo esc_url( mcr_settings_tab_url( 'backup' ) ); ?>" class="nav-tab <?php echo 'backup' === $active_tab ? 'nav-tab-active' : ''; ?>">
			<?php esc_html_e( 'Backup', 'music-club-registrations' ); ?>
		</a>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a href="<?php echo esc_url( mcr_settings_tab_url( 'advanced' ) ); ?>" class="nav-tab <?php echo 'advanced' === $active_tab ? 'nav-tab-active' : ''; ?>">
				<?php esc_html_e( 'Advanced', 'music-club-registrations' ); ?>
			</a>
		<?php endif; ?>
	</h2>

	<?php if ( 'general' === $active_tab ) : ?>

		<?php if ( empty( $available_forms ) ) : ?>
			<div class="notice notice-error">
				<p><?php esc_html_e( 'No Contact Form 7 forms were found on this site. Create a form first, then come back here to configure it.', 'music-club-registrations' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'mcr_save_settings', 'mcr_settings_nonce' ); ?>
			<input type="hidden" name="settings_section" value="general" />

			<h2><?php esc_html_e( 'Monitored Form', 'music-club-registrations' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Choose which Contact Form 7 form this plugin should capture. No form ID is hard-coded — the plugin stays inactive until a form is selected here.', 'music-club-registrations' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mcr-form-id"><?php esc_html_e( 'Contact Form', 'music-club-registrations' ); ?></label></th>
					<td>
						<select name="form_id" id="mcr-form-id">
							<option value="0"><?php esc_html_e( '— Select a form —', 'music-club-registrations' ); ?></option>
							<?php foreach ( $available_forms as $form ) : ?>
								<option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $preview_form_id, $form['id'] ); ?>>
									<?php echo esc_html( $form['title'] ); ?> (<?php echo esc_html( $form['id'] ); ?>)
								</option>
							<?php endforeach; ?>
						</select>
						<button type="submit" name="mcr_preview_form" value="1" class="button" id="mcr-load-fields-btn" formmethod="get" formaction="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
							<?php esc_html_e( 'Load Fields', 'music-club-registrations' ); ?>
						</button>
						<input type="hidden" name="page" value="<?php echo esc_attr( $settings_slug ); ?>" />
						<p class="description"><?php esc_html_e( 'After choosing a form, click "Load Fields" to refresh the mapping options below, then map the fields and save.', 'music-club-registrations' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Field Mapping', 'music-club-registrations' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Map each internal field to the matching field name in your Contact Form 7 form. No field name is hard-coded in the plugin.', 'music-club-registrations' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<?php foreach ( $field_slots as $slot => $slot_label ) : ?>
					<tr>
						<th scope="row"><label for="mcr-field-<?php echo esc_attr( $slot ); ?>"><?php echo esc_html( $slot_label ); ?></label></th>
						<td>
							<?php if ( ! empty( $form_fields ) ) : ?>
								<select name="field_map[<?php echo esc_attr( $slot ); ?>]" id="mcr-field-<?php echo esc_attr( $slot ); ?>">
									<option value=""><?php esc_html_e( '— Not mapped —', 'music-club-registrations' ); ?></option>
									<?php foreach ( $form_fields as $field_name ) : ?>
										<option value="<?php echo esc_attr( $field_name ); ?>" <?php selected( $field_map[ $slot ] ?? '', $field_name ); ?>>
											<?php echo esc_html( $field_name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="text" name="field_map[<?php echo esc_attr( $slot ); ?>]" id="mcr-field-<?php echo esc_attr( $slot ); ?>" value="<?php echo esc_attr( $field_map[ $slot ] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. child-name', 'music-club-registrations' ); ?>" />
								<p class="description"><?php esc_html_e( 'Select a form and click "Load Fields" to choose from a list instead of typing manually.', 'music-club-registrations' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Registration Rules', 'music-club-registrations' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mcr-default-status"><?php esc_html_e( 'Default Status', 'music-club-registrations' ); ?></label></th>
					<td>
						<select name="default_status" id="mcr-default-status">
							<?php foreach ( $statuses as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $settings['default_status'], $slug ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Status automatically assigned to new registrations.', 'music-club-registrations' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Duplicate Prevention', 'music-club-registrations' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="duplicate_check_enabled" value="1" <?php checked( $settings['duplicate_check_enabled'] ); ?> />
							<?php esc_html_e( 'Prevent duplicate registrations (same email + same student name within a time window).', 'music-club-registrations' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mcr-duplicate-window"><?php esc_html_e( 'Duplicate Window (minutes)', 'music-club-registrations' ); ?></label></th>
					<td>
						<input type="number" min="0" step="1" name="duplicate_window_minutes" id="mcr-duplicate-window" value="<?php echo esc_attr( $settings['duplicate_window_minutes'] ); ?>" class="small-text" />
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Export', 'music-club-registrations' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Excel Export', 'music-club-registrations' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="enable_excel_export" value="1" <?php checked( $settings['enable_excel_export'] ); ?> />
							<?php esc_html_e( 'Enable the "Export Excel (.xlsx)" option.', 'music-club-registrations' ); ?>
						</label>
						<p class="description">
							<?php if ( \Music_Club_Registrations\Xlsx_Writer::is_supported() ) : ?>
								<?php esc_html_e( 'Works out of the box — no library installation required.', 'music-club-registrations' ); ?>
							<?php else : ?>
								<span class="mcr-inline-warning"><?php esc_html_e( 'The ZipArchive PHP extension is not available on this server, so Excel export will fall back to a friendly notice. CSV export is always available.', 'music-club-registrations' ); ?></span>
							<?php endif; ?>
						</p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Uninstall', 'music-club-registrations' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Remove Data', 'music-club-registrations' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="remove_data_on_uninstall" value="1" <?php checked( $settings['remove_data_on_uninstall'] ); ?> />
							<?php esc_html_e( 'Delete all registration data and logs when this plugin is uninstalled.', 'music-club-registrations' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'If unchecked (default), data is preserved even after the plugin is deleted.', 'music-club-registrations' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="mcr_save_settings" value="1" class="button button-primary">
					<?php esc_html_e( 'Save Settings', 'music-club-registrations' ); ?>
				</button>
			</p>
		</form>

		<hr />

		<h2><?php esc_html_e( 'REST API', 'music-club-registrations' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'The REST API is registered automatically — no manual setup is required after installation. Use the key below to authenticate external requests.', 'music-club-registrations' ); ?>
		</p>

		<?php if ( $rest_test_result ) : ?>
			<div class="notice <?php echo ! empty( $rest_test_result['success'] ) ? 'notice-success' : 'notice-error'; ?> inline">
				<p><?php echo esc_html( $rest_test_result['message'] ); ?></p>
			</div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'API Key', 'music-club-registrations' ); ?></th>
				<td>
					<input type="text" readonly="readonly" value="<?php echo esc_attr( $settings['api_key'] ); ?>" class="regular-text" onclick="this.select();" />
					<form method="post" style="display:inline-block;margin-left:8px;">
						<?php wp_nonce_field( 'mcr_save_settings', 'mcr_settings_nonce' ); ?>
						<button type="submit" name="mcr_regenerate_api_key" value="1" class="button" onclick="return confirm('<?php echo esc_js( __( 'Generate a new API key? The current key will stop working immediately.', 'music-club-registrations' ) ); ?>');">
							<?php esc_html_e( 'Regenerate Key', 'music-club-registrations' ); ?>
						</button>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoints', 'music-club-registrations' ); ?></th>
				<td>
					<code>GET <?php echo esc_html( $rest_base_url ); ?>/registrations</code><br />
					<code>GET <?php echo esc_html( $rest_base_url ); ?>/registration/{id}</code><br />
					<code>POST <?php echo esc_html( $rest_base_url ); ?>/registration/status</code><br />
					<code>DELETE <?php echo esc_html( $rest_base_url ); ?>/registration/{id}</code><br />
					<code>GET <?php echo esc_html( $rest_base_url ); ?>/export.csv</code><br />
					<code>GET <?php echo esc_html( $rest_base_url ); ?>/export.xlsx</code>
					<p class="description">
						<?php esc_html_e( 'Authenticate with the API key above — either as the "X-MCR-API-Key" header, or as a simple "api_key" query parameter for tools like Excel/Power BI that cannot set custom headers. A logged-in WordPress session also works.', 'music-club-registrations' ); ?>
					</p>
					<p class="description">
						<?php
						printf(
							/* translators: %s: example URL */
							esc_html__( 'Example: %s', 'music-club-registrations' ),
							'<code>' . esc_html( $rest_base_url . '/export.csv?api_key=' . $settings['api_key'] ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mcr_test_rest_api' ); ?>
			<input type="hidden" name="action" value="mcr_test_rest_api" />
			<button type="submit" class="button mcr-test-connection-btn"><?php esc_html_e( 'Test Connection', 'music-club-registrations' ); ?></button>
		</form>

	<?php elseif ( 'excel' === $active_tab ) : ?>

		<?php if ( ! empty( $_GET['ms_error'] ) ) : ?>
			<?php
			$ms_error_messages = array(
				'authorization_denied' => __( 'Microsoft sign-in was cancelled. You can try connecting again whenever you\'re ready.', 'music-club-registrations' ),
				'invalid_state'        => __( 'Your session expired before the Microsoft sign-in finished. Please try connecting again.', 'music-club-registrations' ),
				'token_exchange_failed' => __( 'Microsoft could not confirm the connection. Please try again, or check the Advanced settings if this keeps happening.', 'music-club-registrations' ),
				'missing_workbook'      => __( 'Please choose a workbook from the list.', 'music-club-registrations' ),
				'missing_worksheet'     => __( 'Please choose a worksheet from the list.', 'music-club-registrations' ),
				'missing_table'         => __( 'Please choose a table from the list.', 'music-club-registrations' ),
				'columns_read_failed'   => __( 'We could not read the columns of that table. Please try again.', 'music-club-registrations' ),
				'no_columns'            => __( 'This table does not have any columns yet.', 'music-club-registrations' ),
			);
			$ms_error_key = sanitize_key( wp_unslash( $_GET['ms_error'] ) );
			?>
			<div class="notice notice-error inline">
				<p><?php echo esc_html( $ms_error_messages[ $ms_error_key ] ?? __( 'Something went wrong. Please try again.', 'music-club-registrations' ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $_GET['ms_connected'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Microsoft account connected successfully. Now choose your workbook below.', 'music-club-registrations' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $_GET['ms_disconnected'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Microsoft account disconnected. Your registrations were not affected.', 'music-club-registrations' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $_GET['ms_saved'] ) ) : ?>
			<div class="notice notice-success inline"><p><?php esc_html_e( 'Field mapping saved. New registrations will now sync automatically.', 'music-club-registrations' ); ?></p></div>
		<?php endif; ?>

		<?php if ( $ms_test_result ) : ?>
			<div class="notice <?php echo ! empty( $ms_test_result['success'] ) ? 'notice-success' : 'notice-error'; ?> inline">
				<p><strong><?php echo $ms_test_result['success'] ? esc_html__( 'Connection successful.', 'music-club-registrations' ) : esc_html__( 'Connection test found a problem.', 'music-club-registrations' ); ?></strong></p>
				<ul class="mcr-test-checklist">
					<?php foreach ( $ms_test_result['checks'] as $check ) : ?>
						<li class="<?php echo $check['ok'] ? 'mcr-check-ok' : 'mcr-check-fail'; ?>">
							<span class="dashicons <?php echo $check['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
							<?php echo esc_html( $check['label'] ); ?>
							<?php if ( ! $check['ok'] ) : ?> — <?php echo esc_html( $check['detail'] ); ?><?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( $ms_sync_summary ) : ?>
			<div class="notice notice-success inline">
				<p>
					<?php
					printf(
						/* translators: 1: number synced, 2: number failed */
						esc_html__( '✓ %1\$d synchronized  ✓ %2\$d failed', 'music-club-registrations' ),
						(int) $ms_sync_summary['synced'],
						(int) $ms_sync_summary['failed']
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( ! $ms_app_configured && ! current_user_can( 'manage_options' ) ) : ?>

			<div class="mcr-ms-card">
				<h2><?php esc_html_e( 'Microsoft Excel Online', 'music-club-registrations' ); ?></h2>
				<p><?php esc_html_e( 'Microsoft integration has not been set up yet for this site. Please contact your site administrator.', 'music-club-registrations' ); ?></p>
			</div>

		<?php elseif ( ! $ms_app_configured ) : ?>

			<div class="mcr-ms-card">
				<h2><?php esc_html_e( 'Microsoft Excel Online', 'music-club-registrations' ); ?></h2>
				<p><?php esc_html_e( 'Before clients can connect their Microsoft account, a one-time setup is required.', 'music-club-registrations' ); ?></p>
				<p>
					<a href="<?php echo esc_url( mcr_settings_tab_url( 'advanced' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Go to Advanced Setup', 'music-club-registrations' ); ?>
					</a>
				</p>
			</div>

		<?php elseif ( ! \Music_Club_Registrations\Excel_OAuth::is_connected() ) : ?>

			<div class="mcr-ms-card mcr-ms-card-connect">
				<h2><?php esc_html_e( 'Microsoft Excel Online', 'music-club-registrations' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Automatically synchronize new registrations with an Excel Online workbook.', 'music-club-registrations' ); ?></p>

				<p class="mcr-ms-status">
					<span class="mcr-status-dot mcr-status-dot-off"></span>
					<?php esc_html_e( 'Not Connected', 'music-club-registrations' ); ?>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'mcr_ms_oauth_start' ); ?>
					<input type="hidden" name="action" value="mcr_ms_oauth_start" />
					<button type="submit" class="button button-primary button-hero mcr-ms-connect-btn">
						<?php esc_html_e( 'Connect Microsoft 365', 'music-club-registrations' ); ?>
					</button>
				</form>

				<p class="description"><?php esc_html_e( 'No technical configuration is required.', 'music-club-registrations' ); ?></p>
			</div>

		<?php else : ?>

			<div class="mcr-ms-card">
				<h2><?php esc_html_e( 'Microsoft Excel Online', 'music-club-registrations' ); ?></h2>

				<p class="mcr-ms-status">
					<span class="mcr-status-dot mcr-status-dot-on"></span>
					<?php esc_html_e( 'Connected', 'music-club-registrations' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Microsoft Account:', 'music-club-registrations' ); ?></strong>
					<?php echo esc_html( $ms_connection['account_email'] ?: $ms_connection['account_name'] ); ?>
				</p>

				<!-- Etapa 1: escolher o workbook -->
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcr-ms-select-form">
					<?php wp_nonce_field( 'mcr_ms_select_workbook' ); ?>
					<input type="hidden" name="action" value="mcr_ms_select_workbook" />
					<label for="mcr-ms-workbook"><strong><?php esc_html_e( 'Workbook', 'music-club-registrations' ); ?></strong></label><br />
					<select name="mcr_ms_workbook_choice" id="mcr-ms-workbook" class="mcr-ms-select">
						<option value=""><?php esc_html_e( '— Select workbook —', 'music-club-registrations' ); ?></option>
						<?php foreach ( $ms_workbooks as $workbook ) : ?>
							<option
								value="<?php echo esc_attr( $workbook['drive_id'] . '|' . $workbook['item_id'] . '|' . $workbook['name'] ); ?>"
								<?php selected( $ms_connection['item_id'], $workbook['item_id'] ); ?>
							>
								<?php echo esc_html( $workbook['name'] . ( $workbook['path'] ? ' (' . $workbook['path'] . ')' : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<input type="hidden" name="drive_id" class="mcr-ms-hidden-drive" value="<?php echo esc_attr( $ms_connection['drive_id'] ); ?>" />
					<input type="hidden" name="item_id" class="mcr-ms-hidden-item" value="<?php echo esc_attr( $ms_connection['item_id'] ); ?>" />
					<input type="hidden" name="workbook_name" class="mcr-ms-hidden-name" value="<?php echo esc_attr( $ms_connection['workbook_name'] ); ?>" />
					<noscript><button type="submit" class="button"><?php esc_html_e( 'Select', 'music-club-registrations' ); ?></button></noscript>
					<?php if ( empty( $ms_workbooks ) ) : ?>
						<p class="description"><?php esc_html_e( 'No Excel files were found in this Microsoft account\'s OneDrive/SharePoint. Create or upload a workbook first, then reload this page.', 'music-club-registrations' ); ?></p>
					<?php endif; ?>
				</form>

				<?php if ( ! empty( $ms_connection['item_id'] ) ) : ?>
					<!-- Etapa 2: escolher a worksheet -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcr-ms-select-form">
						<?php wp_nonce_field( 'mcr_ms_select_worksheet' ); ?>
						<input type="hidden" name="action" value="mcr_ms_select_worksheet" />
						<label for="mcr-ms-worksheet"><strong><?php esc_html_e( 'Worksheet', 'music-club-registrations' ); ?></strong></label><br />
						<select name="worksheet_name" id="mcr-ms-worksheet" class="mcr-ms-select mcr-ms-autosubmit">
							<option value=""><?php esc_html_e( '— Select worksheet —', 'music-club-registrations' ); ?></option>
							<?php foreach ( $ms_worksheets as $worksheet ) : ?>
								<option value="<?php echo esc_attr( $worksheet['name'] ); ?>" <?php selected( $ms_connection['worksheet_name'], $worksheet['name'] ); ?>>
									<?php echo esc_html( $worksheet['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<noscript><button type="submit" class="button"><?php esc_html_e( 'Select', 'music-club-registrations' ); ?></button></noscript>
					</form>
				<?php endif; ?>

				<?php if ( ! empty( $ms_connection['worksheet_name'] ) ) : ?>
					<!-- Etapa 3: escolher a tabela -->
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mcr-ms-select-form">
						<?php wp_nonce_field( 'mcr_ms_select_table' ); ?>
						<input type="hidden" name="action" value="mcr_ms_select_table" />
						<label for="mcr-ms-table"><strong><?php esc_html_e( 'Table', 'music-club-registrations' ); ?></strong></label><br />
						<?php if ( empty( $ms_tables ) ) : ?>
							<p class="description mcr-inline-warning"><?php esc_html_e( 'This workbook does not contain an Excel Table. Please create a Table in Excel before continuing (select your data in Excel, then choose Insert > Table).', 'music-club-registrations' ); ?></p>
						<?php else : ?>
							<select name="mcr_ms_table_choice" id="mcr-ms-table" class="mcr-ms-select">
								<option value=""><?php esc_html_e( '— Select table —', 'music-club-registrations' ); ?></option>
								<?php foreach ( $ms_tables as $table ) : ?>
									<option value="<?php echo esc_attr( $table['id'] . '|' . $table['name'] ); ?>" <?php selected( $ms_connection['table_id'], $table['id'] ); ?>>
										<?php echo esc_html( $table['name'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<input type="hidden" name="table_id" class="mcr-ms-hidden-table-id" value="<?php echo esc_attr( $ms_connection['table_id'] ); ?>" />
							<input type="hidden" name="table_name" class="mcr-ms-hidden-table-name" value="<?php echo esc_attr( $ms_connection['table_name'] ); ?>" />
							<noscript><button type="submit" class="button"><?php esc_html_e( 'Select', 'music-club-registrations' ); ?></button></noscript>
						<?php endif; ?>
					</form>
				<?php endif; ?>

				<?php if ( ! empty( $ms_connection['table_id'] ) && ! empty( $ms_connection['table_columns'] ) ) : ?>
					<!-- Etapa 4: mapeamento de campos -->
					<h3><?php esc_html_e( 'Field Mapping', 'music-club-registrations' ); ?></h3>
					<p class="description"><?php esc_html_e( 'We matched your columns automatically. Adjust any of them if needed.', 'music-club-registrations' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'mcr_ms_save_mapping' ); ?>
						<input type="hidden" name="action" value="mcr_ms_save_mapping" />

						<table class="widefat mcr-mapping-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Plugin Field', 'music-club-registrations' ); ?></th>
									<th><?php esc_html_e( 'Excel Column', 'music-club-registrations' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( \Music_Club_Registrations\Excel_Online::get_mappable_fields() as $slot => $label ) : ?>
									<tr>
										<td><?php echo esc_html( $label ); ?></td>
										<td>
											<select name="field_mapping[<?php echo esc_attr( $slot ); ?>]">
												<option value=""><?php esc_html_e( '— Not mapped —', 'music-club-registrations' ); ?></option>
												<?php foreach ( $ms_connection['table_columns'] as $column_name ) : ?>
													<option value="<?php echo esc_attr( $column_name ); ?>" <?php selected( $ms_connection['field_mapping'][ $slot ] ?? '', $column_name ); ?>>
														<?php echo esc_html( $column_name ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<p>
							<label>
								<input type="checkbox" name="auto_sync_enabled" value="1" <?php checked( ! empty( $ms_connection['auto_sync_enabled'] ) ); ?> />
								<?php esc_html_e( 'Automatically sync new registrations to this table.', 'music-club-registrations' ); ?>
							</label>
						</p>

						<p class="submit">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'music-club-registrations' ); ?></button>
						</p>
					</form>
				<?php endif; ?>

				<p class="mcr-ms-actions">
					<?php if ( \Music_Club_Registrations\Excel_OAuth::is_fully_configured() ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
							<?php wp_nonce_field( 'mcr_ms_test_connection' ); ?>
							<input type="hidden" name="action" value="mcr_ms_test_connection" />
							<button type="submit" class="button mcr-test-connection-btn"><?php esc_html_e( 'Test Connection', 'music-club-registrations' ); ?></button>
						</form>

						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
							<?php wp_nonce_field( 'mcr_ms_sync_now' ); ?>
							<input type="hidden" name="action" value="mcr_ms_sync_now" />
							<select name="scope">
								<option value="pending"><?php esc_html_e( 'Pending only', 'music-club-registrations' ); ?></option>
								<option value="failed"><?php esc_html_e( 'Failed only', 'music-club-registrations' ); ?></option>
								<option value="all"><?php esc_html_e( 'All registrations', 'music-club-registrations' ); ?></option>
							</select>
							<button type="submit" class="button mcr-sync-now-btn"><?php esc_html_e( 'Sync Now', 'music-club-registrations' ); ?></button>
						</form>

						<?php if ( ! empty( $ms_sync_stats['failed'] ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
								<?php wp_nonce_field( 'mcr_ms_retry_failed' ); ?>
								<input type="hidden" name="action" value="mcr_ms_retry_failed" />
								<button type="submit" class="button">
									<?php esc_html_e( 'Retry Failed Syncs', 'music-club-registrations' ); ?>
									(<?php echo (int) $ms_sync_stats['failed']; ?>)
								</button>
							</form>
						<?php endif; ?>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
						<?php wp_nonce_field( 'mcr_ms_oauth_disconnect' ); ?>
						<input type="hidden" name="action" value="mcr_ms_oauth_disconnect" />
						<button type="submit" class="button button-link-delete mcr-ms-disconnect-btn" onclick="return confirm('<?php echo esc_js( __( 'Disconnect this Microsoft account? Your existing registrations and sync history will be kept.', 'music-club-registrations' ) ); ?>');">
							<?php esc_html_e( 'Disconnect Microsoft Account', 'music-club-registrations' ); ?>
						</button>
					</form>
				</p>
			</div>

			<div class="mcr-ms-card mcr-ms-stats-card">
				<h3><?php esc_html_e( 'Sync Status', 'music-club-registrations' ); ?></h3>
				<div class="mcr-ms-stats-row">
					<div><strong><?php echo (int) $ms_sync_stats['synced']; ?></strong><span><?php esc_html_e( 'Synced', 'music-club-registrations' ); ?></span></div>
					<div><strong><?php echo (int) $ms_sync_stats['pending']; ?></strong><span><?php esc_html_e( 'Pending', 'music-club-registrations' ); ?></span></div>
					<div><strong><?php echo (int) $ms_sync_stats['failed']; ?></strong><span><?php esc_html_e( 'Failed', 'music-club-registrations' ); ?></span></div>
				</div>
				<?php if ( ! empty( $ms_sync_stats['last_sync_at'] ) ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: date/time of the last successful sync */
							esc_html__( 'Last sync: %s', 'music-club-registrations' ),
							esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ms_sync_stats['last_sync_at'] ) )
						);
						?>
					</p>
				<?php endif; ?>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \Music_Club_Registrations\Admin::LOGS_SLUG . '&context=excel_online' ) ); ?>"><?php esc_html_e( 'View Sync Logs', 'music-club-registrations' ); ?></a></p>
			</div>

		<?php endif; ?>

	<?php elseif ( 'backup' === $active_tab ) : ?>

		<h2><?php esc_html_e( 'Backup & Restore', 'music-club-registrations' ); ?></h2>

		<?php if ( $backup_notice ) : ?>
			<div class="notice <?php echo 'import_success' === $backup_notice[0] ? 'notice-success' : 'notice-error'; ?> inline">
				<p><?php echo esc_html( $backup_notice[1] ); ?></p>
			</div>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Export', 'music-club-registrations' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Download a copy of your configuration, or a full backup including all registrations.', 'music-club-registrations' ); ?></p>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mcr_export_settings' ), 'mcr_backup' ) ); ?>">
				<?php esc_html_e( 'Export Settings Only', 'music-club-registrations' ); ?>
			</a>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mcr_export_full_backup' ), 'mcr_backup' ) ); ?>">
				<?php esc_html_e( 'Export Full Backup', 'music-club-registrations' ); ?>
			</a>
		</p>

		<h3><?php esc_html_e( 'Import / Restore', 'music-club-registrations' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Upload a previously exported settings or full backup JSON file. Existing registrations are never duplicated (matched by registration number).', 'music-club-registrations' ); ?></p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mcr_backup' ); ?>
			<input type="hidden" name="action" value="mcr_import_backup" />
			<input type="file" name="backup_file" accept="application/json" required />
			<button type="submit" class="button" onclick="return confirm('<?php echo esc_js( __( 'Import this backup file? Settings will be overwritten.', 'music-club-registrations' ) ); ?>');">
				<?php esc_html_e( 'Import Backup', 'music-club-registrations' ); ?>
			</button>
		</form>

	<?php elseif ( 'advanced' === $active_tab && current_user_can( 'manage_options' ) ) : ?>

		<h2><?php esc_html_e( 'Microsoft Integration (Advanced)', 'music-club-registrations' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'This is a one-time, developer-level setup. It is separate from the simple "Connect Microsoft 365" experience your clients use — they will never see or need to fill in these fields.', 'music-club-registrations' ); ?>
		</p>

		<div class="notice notice-info inline">
			<p>
				<strong><?php esc_html_e( 'How to set this up:', 'music-club-registrations' ); ?></strong>
			</p>
			<ol class="mcr-advanced-steps">
				<li><?php esc_html_e( 'Go to the Microsoft Entra admin center (entra.microsoft.com) and create a new "App registration".', 'music-club-registrations' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: redirect URI to register */
						esc_html__( 'Under "Redirect URI", add a "Web" platform entry with this exact URL: %s', 'music-club-registrations' ),
						'<code>' . esc_html( $ms_redirect_uri ) . '</code>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Under "Certificates & secrets", create a new Client Secret and copy its value immediately (Microsoft only shows it once).', 'music-club-registrations' ); ?></li>
				<li><?php esc_html_e( 'Under "API permissions", add Microsoft Graph delegated permissions: openid, profile, email, offline_access, User.Read, Files.ReadWrite, Files.ReadWrite.All.', 'music-club-registrations' ); ?></li>
				<li><?php esc_html_e( 'Copy the "Application (client) ID" and the Client Secret you created into the fields below.', 'music-club-registrations' ); ?></li>
			</ol>
		</div>

		<form method="post">
			<?php wp_nonce_field( 'mcr_save_settings', 'mcr_settings_nonce' ); ?>
			<input type="hidden" name="settings_section" value="advanced" />

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="mcr-ms-redirect-uri"><?php esc_html_e( 'Redirect URI', 'music-club-registrations' ); ?></label></th>
					<td>
						<input type="text" id="mcr-ms-redirect-uri" readonly="readonly" value="<?php echo esc_attr( \Music_Club_Registrations\Excel_OAuth::get_redirect_uri() ); ?>" class="regular-text" onclick="this.select();" />
						<p class="description"><?php esc_html_e( 'Generated automatically for this WordPress installation. Register this exact URL in Microsoft Entra.', 'music-club-registrations' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mcr-ms-client-id"><?php esc_html_e( 'Client ID', 'music-club-registrations' ); ?></label></th>
					<td><input type="text" id="mcr-ms-client-id" name="excel_app[client_id]" value="<?php echo esc_attr( $excel_app['client_id'] ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="mcr-ms-client-secret"><?php esc_html_e( 'Client Secret', 'music-club-registrations' ); ?></label></th>
					<td>
						<input type="password" id="mcr-ms-client-secret" name="excel_app[client_secret]" value="" class="regular-text" placeholder="<?php echo ! empty( $excel_app['client_secret'] ) ? esc_attr__( '•••••••• (leave blank to keep current secret)', 'music-club-registrations' ) : ''; ?>" autocomplete="new-password" />
						<p class="description"><?php esc_html_e( 'Stored in the WordPress options table, the same way other plugin credentials are stored. Leave blank to keep the current secret.', 'music-club-registrations' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mcr-ms-tenant"><?php esc_html_e( 'Account Types', 'music-club-registrations' ); ?></label></th>
					<td>
						<select id="mcr-ms-tenant" name="excel_app[tenant]">
							<option value="common" <?php selected( $excel_app['tenant'], 'common' ); ?>><?php esc_html_e( 'Any Microsoft 365 organization or personal account (recommended)', 'music-club-registrations' ); ?></option>
							<option value="organizations" <?php selected( $excel_app['tenant'], 'organizations' ); ?>><?php esc_html_e( 'Any Microsoft 365 organization only', 'music-club-registrations' ); ?></option>
							<option value="consumers" <?php selected( $excel_app['tenant'], 'consumers' ); ?>><?php esc_html_e( 'Personal Microsoft accounts only', 'music-club-registrations' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Matches the "Supported account types" chosen when creating the app registration.', 'music-club-registrations' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="mcr_save_settings" value="1" class="button button-primary"><?php esc_html_e( 'Save Microsoft App Settings', 'music-club-registrations' ); ?></button>
			</p>
		</form>

	<?php endif; ?>
</div>

<?php
/**
 * View: tela de configurações do plugin.
 *
 * Variáveis disponíveis (definidas em Admin::render_settings_page):
 *
 * @var array                 $settings         Configurações atualmente salvas.
 * @var int                   $preview_form_id  ID do formulário sendo pré-visualizado no mapeamento.
 * @var array                 $available_forms  Formulários do Contact Form 7 disponíveis no site.
 * @var array<int,string>     $form_fields      Nomes de campo do formulário pré-visualizado.
 * @var array<string,string>  $field_slots      Slots internos de campo (rótulos amigáveis).
 * @var array<string,string>  $statuses         Status disponíveis.
 * @var string                $rest_namespace   Namespace da API REST.
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings_slug = \Music_Club_Registrations\Admin::SETTINGS_SLUG;
$field_map     = $settings['field_map'];
$rest_base_url = trailingslashit( get_rest_url() ) . $rest_namespace;
?>
<div class="wrap mcr-wrap mcr-settings-wrap">
	<h1><?php esc_html_e( 'Music Club Settings', 'music-club-registrations' ); ?></h1>

	<?php if ( empty( $available_forms ) ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'No Contact Form 7 forms were found on this site. Create a form first, then come back here to configure it.', 'music-club-registrations' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'mcr_save_settings', 'mcr_settings_nonce' ); ?>

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
					<p class="description"><?php esc_html_e( 'Requires the PhpSpreadsheet library (composer install). CSV export is always available.', 'music-club-registrations' ); ?></p>
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
				<code>POST <?php echo esc_html( $rest_base_url ); ?>/registration/status</code>
				<p class="description">
					<?php esc_html_e( 'Send the API key in the "X-MCR-API-Key" header, or a logged-in WordPress session with sufficient permissions.', 'music-club-registrations' ); ?>
				</p>
			</td>
		</tr>
	</table>
</div>

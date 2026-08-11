<?php
/**
 * View: assistente de configuração (Setup Wizard).
 *
 * Variáveis disponíveis (definidas em Setup_Wizard::render_page):
 *
 * @var int                   $step             Etapa atual (1 a 6).
 * @var array                 $environment      Resultado da verificação de ambiente.
 * @var array                 $settings         Configurações atuais.
 * @var array                 $available_forms  Formulários do CF7 disponíveis.
 * @var array<int,string>     $form_fields      Campos do formulário selecionado.
 * @var array<string,string>  $field_slots      Slots internos de campo.
 * @var array                 $system_tests     Resultado dos testes finais (apenas na etapa 6).
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$steps = array(
	1 => __( 'Environment', 'music-club-registrations' ),
	2 => __( 'Select Form', 'music-club-registrations' ),
	3 => __( 'Map Fields', 'music-club-registrations' ),
	4 => __( 'Database', 'music-club-registrations' ),
	5 => __( 'API & Defaults', 'music-club-registrations' ),
	6 => __( 'Finish', 'music-club-registrations' ),
);

$environment_ok = ! in_array( false, wp_list_pluck( $environment, 'ok' ), true );
?>
<div class="wrap mcr-wizard-wrap">
	<h1><?php esc_html_e( 'CF7 Registrations Manager — Setup Wizard', 'music-club-registrations' ); ?></h1>

	<ol class="mcr-wizard-steps">
		<?php foreach ( $steps as $number => $label ) : ?>
			<li class="<?php echo $number === $step ? 'is-active' : ( $number < $step ? 'is-done' : '' ); ?>">
				<?php echo esc_html( $number . '. ' . $label ); ?>
			</li>
		<?php endforeach; ?>
	</ol>

	<div class="mcr-wizard-card">

		<?php if ( 1 === $step ) : ?>
			<h2><?php esc_html_e( 'Environment Check', 'music-club-registrations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Making sure your site is ready to run Music Club Registrations.', 'music-club-registrations' ); ?></p>

			<ul class="mcr-check-list">
				<?php foreach ( $environment as $check ) : ?>
					<li class="<?php echo $check['ok'] ? 'mcr-check-ok' : 'mcr-check-fail'; ?>">
						<span class="dashicons <?php echo $check['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
						<strong><?php echo esc_html( $check['label'] ); ?></strong>
						<span class="mcr-check-detail"><?php echo esc_html( $check['detail'] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( ! $environment_ok ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Some checks did not pass. You can still continue, but some features may be limited until they are resolved.', 'music-club-registrations' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'mcr_setup_wizard', 'mcr_wizard_nonce' ); ?>
				<input type="hidden" name="mcr_wizard_step" value="1" />
				<p class="mcr-wizard-actions">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Continue', 'music-club-registrations' ); ?></button>
				</p>
			</form>

		<?php elseif ( 2 === $step ) : ?>
			<h2><?php esc_html_e( 'Select Your Contact Form', 'music-club-registrations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Choose which Contact Form 7 form should be monitored for registrations.', 'music-club-registrations' ); ?></p>

			<?php if ( empty( $available_forms ) ) : ?>
				<div class="notice notice-error inline"><p><?php esc_html_e( 'No Contact Form 7 forms were found. Please create one first, then reload this page.', 'music-club-registrations' ); ?></p></div>
			<?php else : ?>
				<form method="post">
					<?php wp_nonce_field( 'mcr_setup_wizard', 'mcr_wizard_nonce' ); ?>
					<input type="hidden" name="mcr_wizard_step" value="2" />
					<select name="form_id" class="mcr-wizard-select" required>
						<option value=""><?php esc_html_e( '— Select a form —', 'music-club-registrations' ); ?></option>
						<?php foreach ( $available_forms as $form ) : ?>
							<option value="<?php echo esc_attr( $form['id'] ); ?>" <?php selected( $settings['form_id'], $form['id'] ); ?>>
								<?php echo esc_html( $form['title'] ); ?> (<?php echo esc_html( $form['id'] ); ?>)
							</option>
						<?php endforeach; ?>
					</select>
					<p class="mcr-wizard-actions">
						<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Continue', 'music-club-registrations' ); ?></button>
					</p>
				</form>
			<?php endif; ?>

		<?php elseif ( 3 === $step ) : ?>
			<h2><?php esc_html_e( 'Map Your Fields', 'music-club-registrations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'We read the fields from your form automatically. Just match each one to the correct role below.', 'music-club-registrations' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'mcr_setup_wizard', 'mcr_wizard_nonce' ); ?>
				<input type="hidden" name="mcr_wizard_step" value="3" />

				<table class="form-table" role="presentation">
					<?php foreach ( $field_slots as $slot => $slot_label ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $slot_label ); ?></th>
							<td>
								<?php if ( ! empty( $form_fields ) ) : ?>
									<select name="field_map[<?php echo esc_attr( $slot ); ?>]">
										<option value=""><?php esc_html_e( '— Not mapped —', 'music-club-registrations' ); ?></option>
										<?php foreach ( $form_fields as $field_name ) : ?>
											<option value="<?php echo esc_attr( $field_name ); ?>" <?php selected( $settings['field_map'][ $slot ] ?? '', $field_name ); ?>>
												<?php echo esc_html( $field_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<em><?php esc_html_e( 'No fields were detected for this form.', 'music-club-registrations' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<p class="mcr-wizard-actions">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Continue', 'music-club-registrations' ); ?></button>
				</p>
			</form>

		<?php elseif ( 4 === $step ) : ?>
			<h2><?php esc_html_e( 'Database Setup', 'music-club-registrations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Click continue to automatically create the database tables used by this plugin. This is safe to run even if the tables already exist.', 'music-club-registrations' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'mcr_setup_wizard', 'mcr_wizard_nonce' ); ?>
				<input type="hidden" name="mcr_wizard_step" value="4" />
				<p class="mcr-wizard-actions">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Create Database Tables', 'music-club-registrations' ); ?></button>
				</p>
			</form>

		<?php elseif ( 5 === $step ) : ?>
			<h2><?php esc_html_e( 'API Key & Defaults', 'music-club-registrations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'An API key and default settings are generated automatically - no manual setup required.', 'music-club-registrations' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'REST API Key', 'music-club-registrations' ); ?></th>
					<td><code><?php echo esc_html( $settings['api_key'] ?: __( 'Will be generated on the next step', 'music-club-registrations' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Default Status', 'music-club-registrations' ); ?></th>
					<td><?php echo esc_html( mcr_get_status_label( $settings['default_status'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Duplicate Prevention', 'music-club-registrations' ); ?></th>
					<td><?php echo $settings['duplicate_check_enabled'] ? esc_html__( 'Enabled', 'music-club-registrations' ) : esc_html__( 'Disabled', 'music-club-registrations' ); ?></td>
				</tr>
			</table>

			<form method="post">
				<?php wp_nonce_field( 'mcr_setup_wizard', 'mcr_wizard_nonce' ); ?>
				<input type="hidden" name="mcr_wizard_step" value="5" />
				<p class="mcr-wizard-actions">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Continue', 'music-club-registrations' ); ?></button>
				</p>
			</form>

		<?php elseif ( 6 === $step ) : ?>
			<h2><?php esc_html_e( 'System Test', 'music-club-registrations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Final check before you start using the plugin.', 'music-club-registrations' ); ?></p>

			<ul class="mcr-check-list">
				<?php foreach ( $system_tests as $test ) : ?>
					<li class="<?php echo $test['ok'] ? 'mcr-check-ok' : 'mcr-check-fail'; ?>">
						<span class="dashicons <?php echo $test['ok'] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
						<strong><?php echo esc_html( $test['label'] ); ?></strong>
						<span class="mcr-check-detail"><?php echo $test['ok'] ? esc_html__( 'OK', 'music-club-registrations' ) : esc_html__( 'Needs attention', 'music-club-registrations' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( ! in_array( false, wp_list_pluck( $system_tests, 'ok' ), true ) ) : ?>
				<div class="notice notice-success inline"><p><strong><?php esc_html_e( 'Plugin ready!', 'music-club-registrations' ); ?></strong> <?php esc_html_e( 'Everything is configured correctly.', 'music-club-registrations' ); ?></p></div>
			<?php else : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'You can still finish setup and adjust anything later from Music Club > Settings.', 'music-club-registrations' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'mcr_setup_wizard', 'mcr_wizard_nonce' ); ?>
				<input type="hidden" name="mcr_wizard_step" value="6" />
				<input type="hidden" name="mcr_finish_wizard" value="1" />
				<p class="mcr-wizard-actions">
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Finish & Go to Dashboard', 'music-club-registrations' ); ?></button>
				</p>
			</form>
		<?php endif; ?>

	</div>
</div>

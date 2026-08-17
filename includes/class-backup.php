<?php
/**
 * Classe responsável por backup e restauração das configurações e dos
 * dados do plugin, permitindo migrar entre sites ou recuperar uma
 * configuração anterior sem qualquer edição manual de arquivos.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Backup
 *
 * Responsabilidade única: serializar e restaurar configurações e dados em
 * formato JSON.
 */
class Backup {

	/**
	 * Registra os hooks de admin-post.php utilizados pelas ações de
	 * backup e restauração.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_mcr_export_settings', array( $this, 'handle_export_settings' ) );
		add_action( 'admin_post_mcr_export_full_backup', array( $this, 'handle_export_full_backup' ) );
		add_action( 'admin_post_mcr_import_backup', array( $this, 'handle_import_backup' ) );
	}

	/**
	 * Verifica permissões e nonce antes de processar uma ação de backup.
	 *
	 * @return void
	 */
	private function verify_request() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		check_admin_referer( 'mcr_backup' );
	}

	/**
	 * Exporta apenas as configurações do plugin em formato JSON.
	 *
	 * @return void
	 */
	public function handle_export_settings() {
		$this->verify_request();

		$payload = array(
			'type'          => 'mcr_settings_backup',
			'plugin_version' => MCR_VERSION,
			'exported_at'   => gmdate( 'c' ),
			'settings'      => Settings::get_all(),
		);

		$this->send_json_download( $payload, 'music-club-settings-' . gmdate( 'Y-m-d-His' ) . '.json' );
	}

	/**
	 * Exporta um backup completo: configurações + todas as inscrições.
	 *
	 * @return void
	 */
	public function handle_export_full_backup() {
		$this->verify_request();

		$ids           = Database::get_all_ids( array() );
		$registrations = Database::get_registrations_by_ids( $ids );

		$payload = array(
			'type'          => 'mcr_full_backup',
			'plugin_version' => MCR_VERSION,
			'exported_at'   => gmdate( 'c' ),
			'settings'      => Settings::get_all(),
			'registrations' => $registrations,
		);

		$this->send_json_download( $payload, 'music-club-full-backup-' . gmdate( 'Y-m-d-His' ) . '.json' );

		Logger::info( 'backup', sprintf( 'Full backup exported with %d registration(s).', count( $registrations ) ), get_current_user_id() );
	}

	/**
	 * Processa a importação de um arquivo de backup (configurações ou
	 * backup completo), restaurando os dados de forma segura.
	 *
	 * @return void
	 */
	public function handle_import_backup() {
		$this->verify_request();

		if ( empty( $_FILES['backup_file']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['backup_file']['error'] ) {
			$this->redirect_with_message( 'import_error', __( 'No valid file was uploaded.', 'music-club-registrations' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lendo um arquivo temporário de upload já validado pelo WordPress.
		$raw = file_get_contents( $_FILES['backup_file']['tmp_name'] );
		$data = json_decode( (string) $raw, true );

		if ( ! is_array( $data ) || empty( $data['type'] ) ) {
			$this->redirect_with_message( 'import_error', __( 'This file is not a valid Music Club Registrations backup.', 'music-club-registrations' ) );
		}

		if ( ! empty( $data['settings'] ) && is_array( $data['settings'] ) ) {
			Settings::save( $data['settings'] );
		}

		$imported_count = 0;

		if ( 'mcr_full_backup' === $data['type'] && ! empty( $data['registrations'] ) && is_array( $data['registrations'] ) ) {
			$imported_count = $this->restore_registrations( $data['registrations'] );
		}

		Logger::info(
			'backup',
			sprintf( 'Backup imported (%s). %d registration(s) restored.', $data['type'], $imported_count ),
			get_current_user_id()
		);

		$this->redirect_with_message( 'import_success', sprintf(
			/* translators: %d: number of imported registrations */
			__( 'Backup imported successfully. %d registration(s) restored.', 'music-club-registrations' ),
			$imported_count
		) );
	}

	/**
	 * Restaura inscrições a partir de um backup completo, evitando
	 * duplicar registros já existentes (identificados pelo número de
	 * inscrição).
	 *
	 * @param array $registrations Lista de inscrições do backup.
	 * @return int Número de registros efetivamente restaurados.
	 */
	private function restore_registrations( array $registrations ) {
		global $wpdb;

		$table   = Database::table_name();
		$existing = $wpdb->get_col( "SELECT registration_number FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$existing = array_flip( $existing ?: array() );

		$restored = 0;

		foreach ( $registrations as $item ) {
			if ( empty( $item['registration_number'] ) || isset( $existing[ $item['registration_number'] ] ) ) {
				continue;
			}

			$wpdb->insert(
				$table,
				array(
					'registration_number' => sanitize_text_field( $item['registration_number'] ),
					'child_name'           => sanitize_text_field( $item['child_name'] ?? '' ),
					'child_age'            => ( isset( $item['child_age'] ) && is_numeric( $item['child_age'] ) ) ? absint( $item['child_age'] ) : null,
					'parent_name'          => sanitize_text_field( $item['parent_name'] ?? '' ),
					'second_parent_name'   => sanitize_text_field( $item['second_parent_name'] ?? '' ),
					'parent_email'         => sanitize_email( $item['parent_email'] ?? '' ),
					'phone'                => mcr_sanitize_phone( $item['phone'] ?? '' ),
					'second_parent_email'  => sanitize_email( $item['second_parent_email'] ?? '' ),
					'second_parent_phone'  => mcr_sanitize_phone( $item['second_parent_phone'] ?? '' ),
					'child_class'          => sanitize_text_field( $item['child_class'] ?? '' ),
					'interests'            => sanitize_text_field( $item['interests'] ?? '' ),
					'total_amount'         => sanitize_text_field( $item['total_amount'] ?? '' ),
					'additional_message'   => sanitize_textarea_field( $item['additional_message'] ?? '' ),
					'status'               => mcr_is_valid_status( $item['status'] ?? '' ) ? $item['status'] : 'new',
					'internal_notes'       => sanitize_textarea_field( $item['internal_notes'] ?? '' ),
					'created_at'           => ! empty( $item['created_at'] ) ? $item['created_at'] : current_time( 'mysql' ),
					'updated_at'           => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			++$restored;
		}

		return $restored;
	}

	/**
	 * Envia um payload como download de arquivo JSON.
	 *
	 * @param array  $payload  Dados a serializar.
	 * @param string $filename Nome do arquivo de download.
	 * @return void
	 */
	private function send_json_download( array $payload, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );

		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON file content, not HTML.
		exit;
	}

	/**
	 * Redireciona de volta para a tela de Settings com uma mensagem de
	 * status sobre a importação.
	 *
	 * @param string $status_key Chave de status ('import_success' ou 'import_error').
	 * @param string $message    Mensagem a exibir.
	 * @return void
	 */
	private function redirect_with_message( $status_key, $message ) {
		set_transient( 'mcr_backup_notice_' . get_current_user_id(), array( $status_key, $message ), 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . Admin::SETTINGS_SLUG . '&tab=backup' ) );
		exit;
	}
}

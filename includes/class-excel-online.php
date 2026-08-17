<?php
/**
 * Orquestra a experiência de configuração da integração com o Excel
 * Online: seleção de workbook/worksheet/table (descobertos
 * automaticamente via Microsoft Graph API, sem exigir nenhum ID técnico
 * do cliente), mapeamento automático de colunas, teste de conexão e
 * sincronização manual.
 *
 * A autenticação em si (OAuth) fica em Excel_OAuth; as chamadas HTTP à
 * Graph API ficam em Excel_Graph; o processamento em fila fica em
 * Excel_Sync_Queue. Esta classe é a camada de UI/ações administrativas
 * que conecta as três.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Excel_Online
 *
 * Responsabilidade única: processar as ações administrativas da tela
 * "Excel Online" (seleção de workbook/worksheet/table, mapeamento,
 * teste de conexão, sincronização manual).
 */
class Excel_Online {

	/**
	 * Registra os hooks de admin-post.php usados por esta tela.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_mcr_ms_select_workbook', array( $this, 'handle_select_workbook' ) );
		add_action( 'admin_post_mcr_ms_select_worksheet', array( $this, 'handle_select_worksheet' ) );
		add_action( 'admin_post_mcr_ms_select_table', array( $this, 'handle_select_table' ) );
		add_action( 'admin_post_mcr_ms_save_mapping', array( $this, 'handle_save_mapping' ) );
		add_action( 'admin_post_mcr_ms_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_post_mcr_ms_sync_now', array( $this, 'handle_sync_now' ) );
		add_action( 'admin_post_mcr_ms_retry_failed', array( $this, 'handle_retry_failed' ) );
		add_action( 'admin_post_mcr_ms_sync_single', array( $this, 'handle_sync_single' ) );
	}

	/**
	 * Retorna os campos do plugin disponíveis para mapeamento (os mesmos
	 * usados na exportação CSV/Excel), reaproveitando Export::get_all_columns()
	 * em vez de duplicar a lista.
	 *
	 * @return array<string,string>
	 */
	public static function get_mappable_fields() {
		return Export::get_all_columns();
	}

	/**
	 * Tenta casar automaticamente cada campo do plugin com uma coluna do
	 * Excel, comparando os nomes de forma tolerante (ignorando espaços,
	 * pontuação, maiúsculas/minúsculas) e usando um pequeno dicionário de
	 * sinônimos para os casos mais comuns.
	 *
	 * @param array<int,string> $excel_columns Nomes das colunas da tabela do Excel.
	 * @return array<string,string> Mapeamento sugerido (slot do plugin => nome da coluna do Excel).
	 */
	public static function auto_match_columns( array $excel_columns ) {
		$synonyms = array(
			'registration_number' => array( 'registration', 'registrationnumber', 'regnumber', 'id' ),
			'child_name'           => array( 'studentname', 'student', 'childname', 'child', 'name' ),
			'child_age'            => array( 'age', 'childage', 'studentage' ),
			'parent_name'          => array( 'parentname', 'parent', 'guardianname', 'guardian', 'parentguardian' ),
			'second_parent_name'   => array( 'additionalparentname', 'secondparentname', 'parent2name', 'guardianname2', 'additionalguardian', 'secondguardian' ),
			'parent_email'         => array( 'email', 'parentemail', 'emailaddress', 'primaryemail' ),
			'phone'                => array( 'phone', 'phonenumber', 'telephone', 'mobile', 'primaryphone' ),
			'second_parent_email'  => array( 'additionalemail', 'secondemail', 'secondparentemail', 'parent2email', 'alternateemail' ),
			'second_parent_phone'  => array( 'additionalphone', 'secondphone', 'secondparentphone', 'parent2phone', 'alternatephone' ),
			'child_class'          => array( 'class', 'classroom', 'grade' ),
			'interests'            => array( 'program', 'interests', 'activity', 'programs' ),
			'total_amount'         => array( 'totalamount', 'total', 'amount', 'price', 'cost', 'payment', 'fee' ),
			'additional_message'   => array( 'message', 'additionalmessage', 'notes', 'comments' ),
			'status'               => array( 'status' ),
			'created_at'           => array( 'createdat', 'date', 'submitted', 'registrationdate', 'created' ),
		);

		$normalized_columns = array();
		foreach ( $excel_columns as $column ) {
			$normalized_columns[ self::normalize( $column ) ] = $column;
		}

		$mapping = array();

		foreach ( self::get_mappable_fields() as $slot => $label ) {
			$candidates   = $synonyms[ $slot ] ?? array();
			$candidates[] = $slot;
			$candidates[] = $label;

			foreach ( $candidates as $candidate ) {
				$normalized_candidate = self::normalize( $candidate );

				if ( isset( $normalized_columns[ $normalized_candidate ] ) ) {
					$mapping[ $slot ] = $normalized_columns[ $normalized_candidate ];
					continue 2;
				}
			}

			// Segunda passada: correspondência parcial (uma string contém a outra).
			foreach ( $normalized_columns as $normalized_column => $original_column ) {
				foreach ( $candidates as $candidate ) {
					$normalized_candidate = self::normalize( $candidate );

					if ( $normalized_candidate && ( false !== strpos( $normalized_column, $normalized_candidate ) || false !== strpos( $normalized_candidate, $normalized_column ) ) ) {
						$mapping[ $slot ] = $original_column;
						continue 3;
					}
				}
			}
		}

		return $mapping;
	}

	/**
	 * Normaliza uma string para comparação tolerante (minúsculas, apenas
	 * letras e números).
	 *
	 * @param string $value Valor bruto.
	 * @return string
	 */
	private static function normalize( $value ) {
		$value = strtolower( (string) $value );

		return preg_replace( '/[^a-z0-9]/', '', $value );
	}

	/**
	 * Verifica permissão e nonce padrão para as ações desta tela.
	 *
	 * @param string $nonce_action Ação do nonce a validar.
	 * @return void
	 */
	private function verify_request( $nonce_action ) {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Redireciona de volta para a aba "Excel Online", opcionalmente com
	 * parâmetros extras de status.
	 *
	 * @param array $extra_args Parâmetros de query adicionais.
	 * @return void
	 */
	private function redirect_to_excel_tab( array $extra_args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page' => Admin::SETTINGS_SLUG,
					'tab'  => 'excel',
				),
				$extra_args
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Processa a seleção de um workbook (arquivo .xlsx), descoberto
	 * automaticamente pela busca da Microsoft Graph API - o cliente
	 * apenas escolhe da lista, sem informar nenhum ID.
	 *
	 * @return void
	 */
	public function handle_select_workbook() {
		$this->verify_request( 'mcr_ms_select_workbook' );

		$drive_id = isset( $_POST['drive_id'] ) ? sanitize_text_field( wp_unslash( $_POST['drive_id'] ) ) : '';
		$item_id  = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
		$name     = isset( $_POST['workbook_name'] ) ? sanitize_text_field( wp_unslash( $_POST['workbook_name'] ) ) : '';

		if ( empty( $drive_id ) || empty( $item_id ) ) {
			$this->redirect_to_excel_tab( array( 'ms_error' => 'missing_workbook' ) );
		}

		// Selecionar um novo workbook invalida a worksheet/tabela/mapeamento
		// escolhidos anteriormente, já que pertenciam ao arquivo anterior.
		Excel_OAuth::update_connection(
			array(
				'drive_id'       => $drive_id,
				'item_id'        => $item_id,
				'workbook_name'  => $name,
				'worksheet_name' => '',
				'table_id'       => '',
				'table_name'     => '',
				'table_columns'  => array(),
				'field_mapping'  => array(),
			)
		);

		Logger::info( 'excel_online', 'Workbook selected: ' . $name, get_current_user_id() );

		$this->redirect_to_excel_tab();
	}

	/**
	 * Processa a seleção de uma worksheet dentro do workbook já escolhido.
	 *
	 * @return void
	 */
	public function handle_select_worksheet() {
		$this->verify_request( 'mcr_ms_select_worksheet' );

		$worksheet_name = isset( $_POST['worksheet_name'] ) ? sanitize_text_field( wp_unslash( $_POST['worksheet_name'] ) ) : '';

		if ( empty( $worksheet_name ) ) {
			$this->redirect_to_excel_tab( array( 'ms_error' => 'missing_worksheet' ) );
		}

		Excel_OAuth::update_connection(
			array(
				'worksheet_name' => $worksheet_name,
				'table_id'       => '',
				'table_name'     => '',
				'table_columns'  => array(),
				'field_mapping'  => array(),
			)
		);

		Logger::info( 'excel_online', 'Worksheet selected: ' . $worksheet_name, get_current_user_id() );

		$this->redirect_to_excel_tab();
	}

	/**
	 * Processa a seleção de uma tabela do Excel dentro da worksheet já
	 * escolhida, lê automaticamente as colunas existentes e sugere um
	 * mapeamento automático por nome.
	 *
	 * @return void
	 */
	public function handle_select_table() {
		$this->verify_request( 'mcr_ms_select_table' );

		$table_id   = isset( $_POST['table_id'] ) ? sanitize_text_field( wp_unslash( $_POST['table_id'] ) ) : '';
		$table_name = isset( $_POST['table_name'] ) ? sanitize_text_field( wp_unslash( $_POST['table_name'] ) ) : '';

		if ( empty( $table_id ) ) {
			$this->redirect_to_excel_tab( array( 'ms_error' => 'missing_table' ) );
		}

		$connection = Excel_OAuth::get_connection();

		$columns = Excel_Graph::list_table_columns( $connection['drive_id'], $connection['item_id'], $table_id );

		if ( is_wp_error( $columns ) ) {
			Logger::error( 'excel_online', 'Failed to read table columns: ' . $columns->get_error_message() );
			$this->redirect_to_excel_tab( array( 'ms_error' => 'columns_read_failed' ) );
		}

		if ( empty( $columns ) ) {
			$this->redirect_to_excel_tab( array( 'ms_error' => 'no_columns' ) );
		}

		$auto_mapping = self::auto_match_columns( $columns );

		Excel_OAuth::update_connection(
			array(
				'table_id'      => $table_id,
				'table_name'    => $table_name,
				'table_columns' => $columns,
				'field_mapping' => $auto_mapping,
			)
		);

		Logger::info( 'excel_online', 'Table selected: ' . $table_name . '. Columns auto-detected and mapping suggested.', get_current_user_id() );

		$this->redirect_to_excel_tab();
	}

	/**
	 * Salva o mapeamento de campos (com eventuais ajustes manuais do
	 * administrador) e habilita a sincronização automática.
	 *
	 * @return void
	 */
	public function handle_save_mapping() {
		$this->verify_request( 'mcr_ms_save_mapping' );

		$connection = Excel_OAuth::get_connection();

		$raw_mapping = isset( $_POST['field_mapping'] ) && is_array( $_POST['field_mapping'] )
			? wp_unslash( $_POST['field_mapping'] )
			: array();

		$mapping = array();
		foreach ( array_keys( self::get_mappable_fields() ) as $slot ) {
			$value = isset( $raw_mapping[ $slot ] ) ? sanitize_text_field( $raw_mapping[ $slot ] ) : '';

			if ( '' !== $value && in_array( $value, $connection['table_columns'], true ) ) {
				$mapping[ $slot ] = $value;
			}
		}

		Excel_OAuth::update_connection(
			array(
				'field_mapping'     => $mapping,
				'auto_sync_enabled' => ! empty( $_POST['auto_sync_enabled'] ),
			)
		);

		Logger::info( 'excel_online', 'Field mapping saved. Automatic sync is now fully configured.', get_current_user_id() );

		$this->redirect_to_excel_tab( array( 'ms_saved' => 1 ) );
	}

	/**
	 * Processa o botão "Test Connection": executa o checklist completo
	 * (autenticação, workbook, worksheet, tabela, permissão de escrita) e
	 * armazena o resultado para exibição amigável.
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		$this->verify_request( 'mcr_ms_test_connection' );

		$result = Excel_Graph::test_connection();

		set_transient( 'mcr_ms_test_result_' . get_current_user_id(), $result, 60 );

		Logger::info(
			'excel_online',
			$result['success'] ? 'Connection test passed.' : 'Connection test failed.',
			get_current_user_id()
		);

		$this->redirect_to_excel_tab();
	}

	/**
	 * Processa o botão "Sync Now": sincroniza manualmente os registros
	 * pendentes, os que falharam, ou todos, conforme a opção escolhida.
	 *
	 * @return void
	 */
	public function handle_sync_now() {
		$this->verify_request( 'mcr_ms_sync_now' );

		$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'pending';

		if ( 'all' === $scope ) {
			Database::mark_unsynced_as_pending();
		}

		$queue   = new Excel_Sync_Queue();
		$summary = 'failed' === $scope ? $queue->retry_all_failed() : $queue->process_queue( 200 );

		set_transient( 'mcr_ms_sync_summary_' . get_current_user_id(), $summary, 60 );

		Logger::info(
			'excel_online',
			sprintf( 'Manual sync (%s) completed: %d synced, %d failed.', $scope, $summary['synced'], $summary['failed'] ),
			get_current_user_id()
		);

		$this->redirect_to_excel_tab( array( 'ms_synced' => 1 ) );
	}

	/**
	 * Processa o botão "Retry Failed Syncs".
	 *
	 * @return void
	 */
	public function handle_retry_failed() {
		$this->verify_request( 'mcr_ms_retry_failed' );

		$queue   = new Excel_Sync_Queue();
		$summary = $queue->retry_all_failed();

		set_transient( 'mcr_ms_sync_summary_' . get_current_user_id(), $summary, 60 );

		Logger::info(
			'excel_online',
			sprintf( 'Retry failed syncs completed: %d synced, %d failed.', $summary['synced'], $summary['failed'] ),
			get_current_user_id()
		);

		$this->redirect_to_excel_tab( array( 'ms_synced' => 1 ) );
	}

	/**
	 * Processa o botão "Sync Again" de um registro individual, na tela de
	 * detalhes da inscrição.
	 *
	 * @return void
	 */
	public function handle_sync_single() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		check_admin_referer( 'mcr_ms_sync_single_' . $id );

		$registration = $id ? Database::get_registration( $id ) : null;

		if ( $registration ) {
			Database::reset_sync_status( $id );
			$registration['excel_sync_status']   = 'pending';
			$registration['excel_row_reference'] = '';

			( new Excel_Sync_Queue() )->sync_registration( $registration );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => Admin::DETAIL_SLUG,
					'id'      => $id,
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}

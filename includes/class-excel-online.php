<?php
/**
 * Orquestra a experiência de configuração da integração com o Excel
 * Online: seleção de workbook/worksheet/table (descobertos
 * automaticamente via Microsoft Graph API, sem exigir nenhum ID técnico
 * do cliente), mapeamento automático de colunas, teste de conexão e
 * sincronização manual.
 *
 * A partir da versão 2.10.0, cada ação é parametrizada por um "destino"
 * (`$target`) - "registrations" ou "payments" - permitindo configurar
 * duas tabelas do Excel completamente independentes (workbook, aba,
 * tabela e mapeamento próprios) sem duplicar nenhum código: os mesmos
 * métodos abaixo atendem qualquer destino presente em
 * Excel_OAuth::get_target_keys(), sem nenhuma lógica específica de uma
 * inscrição, aluno, programa ou pagamento em particular.
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
 * teste de conexão, sincronização manual), para qualquer destino.
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
	 * Lê e valida o destino ("target") informado numa requisição POST,
	 * caindo no destino padrão (Registrations) quando ausente - o que
	 * mantém formulários/links de versões anteriores à 2.10.0 funcionando
	 * sem alteração.
	 *
	 * @return string
	 */
	private function get_requested_target() {
		$target = isset( $_POST['target'] ) ? sanitize_key( wp_unslash( $_POST['target'] ) ) : Excel_OAuth::DEFAULT_TARGET;

		return in_array( $target, Excel_OAuth::get_target_keys(), true ) ? $target : Excel_OAuth::DEFAULT_TARGET;
	}

	/**
	 * Retorna os campos disponíveis para mapeamento de um destino
	 * específico. "Registrations" reaproveita a mesma lista já usada na
	 * exportação CSV/Excel (Export::get_all_columns()); "Payments" usa um
	 * conjunto próprio e enxuto, com o Registration ID como campo de
	 * relacionamento (nunca duplicando os dados completos da inscrição)
	 * e o nome da criança apenas como campo auxiliar de leitura.
	 *
	 * @param string $target Chave do destino.
	 * @return array<string,string>
	 */
	public static function get_mappable_fields( $target = Excel_OAuth::DEFAULT_TARGET ) {
		if ( 'payments' === $target ) {
			return array(
				'payment_number'      => __( 'Payment ID', 'music-club-registrations' ),
				'registration_number' => __( 'Registration ID', 'music-club-registrations' ),
				'child_name'          => __( "Child's Name (reference only)", 'music-club-registrations' ),
				'total_amount'        => __( 'Amount', 'music-club-registrations' ),
				'payment_status'      => __( 'Payment Status', 'music-club-registrations' ),
				'payment_confirmed_at' => __( 'Payment Confirmed At', 'music-club-registrations' ),
			);
		}

		return Export::get_all_columns();
	}

	/**
	 * Tenta casar automaticamente cada campo de um destino com uma coluna
	 * do Excel, comparando os nomes de forma tolerante (ignorando
	 * espaços, pontuação, maiúsculas/minúsculas) e usando um pequeno
	 * dicionário de sinônimos para os casos mais comuns.
	 *
	 * @param array<int,string> $excel_columns Nomes das colunas da tabela do Excel.
	 * @param string            $target        Chave do destino.
	 * @return array<string,string> Mapeamento sugerido (slot => nome da coluna do Excel).
	 */
	public static function auto_match_columns( array $excel_columns, $target = Excel_OAuth::DEFAULT_TARGET ) {
		$synonyms = array(
			'registration_number'  => array( 'registration', 'registrationnumber', 'regnumber', 'registrationid', 'id' ),
			'child_name'            => array( 'studentname', 'student', 'childname', 'child', 'name' ),
			'child_age'             => array( 'age', 'childage', 'studentage' ),
			'parent_name'           => array( 'parentname', 'parent', 'guardianname', 'guardian', 'parentguardian' ),
			'second_parent_name'    => array( 'additionalparentname', 'secondparentname', 'parent2name', 'guardianname2', 'additionalguardian', 'secondguardian' ),
			'parent_email'          => array( 'email', 'parentemail', 'emailaddress', 'primaryemail' ),
			'phone'                 => array( 'phone', 'phonenumber', 'telephone', 'mobile', 'primaryphone' ),
			'second_parent_email'   => array( 'additionalemail', 'secondemail', 'secondparentemail', 'parent2email', 'alternateemail' ),
			'second_parent_phone'   => array( 'additionalphone', 'secondphone', 'secondparentphone', 'parent2phone', 'alternatephone' ),
			'child_class'           => array( 'class', 'classroom', 'grade' ),
			'interests'             => array( 'program', 'interests', 'activity', 'programs' ),
			'total_amount'          => array( 'totalamount', 'total', 'amount', 'price', 'cost', 'payment', 'fee' ),
			'photo_permission'      => array( 'photopermission', 'photographypermission', 'permission', 'photo', 'picture', 'image', 'photography' ),
			'additional_message'    => array( 'message', 'additionalmessage', 'notes', 'comments' ),
			'status'                => array( 'status' ),
			'created_at'            => array( 'createdat', 'date', 'submitted', 'registrationdate', 'created' ),
			'payment_number'        => array( 'paymentid', 'payid', 'payment', 'paymentnumber' ),
			'payment_status'        => array( 'paymentstatus', 'status', 'paid' ),
			'payment_confirmed_at'  => array( 'paymentconfirmedat', 'confirmedat', 'paiddate', 'datepaid' ),
		);

		$normalized_columns = array();
		foreach ( $excel_columns as $column ) {
			$normalized_columns[ self::normalize( $column ) ] = $column;
		}

		$mapping = array();

		foreach ( self::get_mappable_fields( $target ) as $slot => $label ) {
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
	 * parâmetros extras de status, preservando o destino ("target") em
	 * que o administrador estava trabalhando.
	 *
	 * @param string $target     Chave do destino.
	 * @param array  $extra_args Parâmetros de query adicionais.
	 * @return void
	 */
	private function redirect_to_excel_tab( $target, array $extra_args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page'   => Admin::SETTINGS_SLUG,
					'tab'    => 'excel',
					'target' => $target,
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

		$target = $this->get_requested_target();

		$drive_id = isset( $_POST['drive_id'] ) ? sanitize_text_field( wp_unslash( $_POST['drive_id'] ) ) : '';
		$item_id  = isset( $_POST['item_id'] ) ? sanitize_text_field( wp_unslash( $_POST['item_id'] ) ) : '';
		$name     = isset( $_POST['workbook_name'] ) ? sanitize_text_field( wp_unslash( $_POST['workbook_name'] ) ) : '';

		if ( empty( $drive_id ) || empty( $item_id ) ) {
			$this->redirect_to_excel_tab( $target, array( 'ms_error' => 'missing_workbook' ) );
		}

		// Selecionar um novo workbook invalida a worksheet/tabela/mapeamento
		// escolhidos anteriormente para ESTE destino, já que pertenciam ao
		// arquivo anterior - os demais destinos não são afetados.
		Excel_OAuth::update_target_connection(
			$target,
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

		Logger::info( 'excel_online', sprintf( 'Workbook selected for "%s": %s', $target, $name ), get_current_user_id() );

		$this->redirect_to_excel_tab( $target );
	}

	/**
	 * Processa a seleção de uma worksheet dentro do workbook já escolhido.
	 *
	 * @return void
	 */
	public function handle_select_worksheet() {
		$this->verify_request( 'mcr_ms_select_worksheet' );

		$target = $this->get_requested_target();

		$worksheet_name = isset( $_POST['worksheet_name'] ) ? sanitize_text_field( wp_unslash( $_POST['worksheet_name'] ) ) : '';

		if ( empty( $worksheet_name ) ) {
			$this->redirect_to_excel_tab( $target, array( 'ms_error' => 'missing_worksheet' ) );
		}

		Excel_OAuth::update_target_connection(
			$target,
			array(
				'worksheet_name' => $worksheet_name,
				'table_id'       => '',
				'table_name'     => '',
				'table_columns'  => array(),
				'field_mapping'  => array(),
			)
		);

		Logger::info( 'excel_online', sprintf( 'Worksheet selected for "%s": %s', $target, $worksheet_name ), get_current_user_id() );

		$this->redirect_to_excel_tab( $target );
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

		$target = $this->get_requested_target();

		$table_id   = isset( $_POST['table_id'] ) ? sanitize_text_field( wp_unslash( $_POST['table_id'] ) ) : '';
		$table_name = isset( $_POST['table_name'] ) ? sanitize_text_field( wp_unslash( $_POST['table_name'] ) ) : '';

		if ( empty( $table_id ) ) {
			$this->redirect_to_excel_tab( $target, array( 'ms_error' => 'missing_table' ) );
		}

		$connection = Excel_OAuth::get_target_connection( $target );

		$columns = Excel_Graph::list_table_columns( $connection['drive_id'], $connection['item_id'], $table_id );

		if ( is_wp_error( $columns ) ) {
			Logger::error( 'excel_online', 'Failed to read table columns: ' . $columns->get_error_message() );
			$this->redirect_to_excel_tab( $target, array( 'ms_error' => 'columns_read_failed' ) );
		}

		if ( empty( $columns ) ) {
			$this->redirect_to_excel_tab( $target, array( 'ms_error' => 'no_columns' ) );
		}

		$auto_mapping = self::auto_match_columns( $columns, $target );

		Excel_OAuth::update_target_connection(
			$target,
			array(
				'table_id'      => $table_id,
				'table_name'    => $table_name,
				'table_columns' => $columns,
				'field_mapping' => $auto_mapping,
			)
		);

		Logger::info( 'excel_online', sprintf( 'Table selected for "%s": %s. Columns auto-detected and mapping suggested.', $target, $table_name ), get_current_user_id() );

		$this->redirect_to_excel_tab( $target );
	}

	/**
	 * Salva o mapeamento de campos (com eventuais ajustes manuais do
	 * administrador) e habilita a sincronização automática.
	 *
	 * @return void
	 */
	public function handle_save_mapping() {
		$this->verify_request( 'mcr_ms_save_mapping' );

		$target     = $this->get_requested_target();
		$connection = Excel_OAuth::get_target_connection( $target );

		$raw_mapping = isset( $_POST['field_mapping'] ) && is_array( $_POST['field_mapping'] )
			? wp_unslash( $_POST['field_mapping'] )
			: array();

		$mapping = array();
		foreach ( array_keys( self::get_mappable_fields( $target ) ) as $slot ) {
			$value = isset( $raw_mapping[ $slot ] ) ? sanitize_text_field( $raw_mapping[ $slot ] ) : '';

			if ( '' !== $value && in_array( $value, $connection['table_columns'], true ) ) {
				$mapping[ $slot ] = $value;
			}
		}

		Excel_OAuth::update_target_connection(
			$target,
			array(
				'field_mapping'     => $mapping,
				'auto_sync_enabled' => ! empty( $_POST['auto_sync_enabled'] ),
			)
		);

		Logger::info( 'excel_online', sprintf( 'Field mapping saved for "%s". Automatic sync is now fully configured.', $target ), get_current_user_id() );

		$this->redirect_to_excel_tab( $target, array( 'ms_saved' => 1 ) );
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

		$target = $this->get_requested_target();
		$result = Excel_Graph::test_connection( $target );

		set_transient( 'mcr_ms_test_result_' . $target . '_' . get_current_user_id(), $result, 60 );

		Logger::info(
			'excel_online',
			sprintf( '[%s] %s', $target, $result['success'] ? 'Connection test passed.' : 'Connection test failed.' ),
			get_current_user_id()
		);

		$this->redirect_to_excel_tab( $target );
	}

	/**
	 * Processa o botão "Sync Now": sincroniza manualmente os registros
	 * pendentes, os que falharam, ou todos, conforme a opção escolhida.
	 *
	 * @return void
	 */
	public function handle_sync_now() {
		$this->verify_request( 'mcr_ms_sync_now' );

		$target = $this->get_requested_target();
		$scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'pending';

		$queue = new Excel_Sync_Queue();

		if ( 'payments' === $target ) {
			if ( 'all' === $scope ) {
				Database::mark_unsynced_payments_as_pending();
			} elseif ( 'force_all' === $scope ) {
				Database::force_all_payments_as_pending();
			} elseif ( 'reset_all' === $scope ) {
				Database::reset_all_payment_sync_references();
			}

			$summary = 'failed' === $scope ? $queue->retry_all_failed_payments() : $queue->process_payment_queue( in_array( $scope, array( 'force_all', 'reset_all' ), true ) ? 500 : 200 );
		} else {
			if ( 'all' === $scope ) {
				Database::mark_unsynced_as_pending();
			} elseif ( 'force_all' === $scope ) {
				Database::force_all_as_pending();
			} elseif ( 'reset_all' === $scope ) {
				Database::reset_all_sync_references();
			}

			$summary = 'failed' === $scope ? $queue->retry_all_failed() : $queue->process_queue( in_array( $scope, array( 'force_all', 'reset_all' ), true ) ? 500 : 200 );
		}

		set_transient( 'mcr_ms_sync_summary_' . $target . '_' . get_current_user_id(), $summary, 60 );

		Logger::info(
			'excel_online',
			sprintf( '[%s] Manual sync (%s) completed: %d synced, %d failed.', $target, $scope, $summary['synced'], $summary['failed'] ),
			get_current_user_id()
		);

		$this->redirect_to_excel_tab( $target, array( 'ms_synced' => 1 ) );
	}

	/**
	 * Processa o botão "Retry Failed Syncs".
	 *
	 * @return void
	 */
	public function handle_retry_failed() {
		$this->verify_request( 'mcr_ms_retry_failed' );

		$target = $this->get_requested_target();
		$queue  = new Excel_Sync_Queue();

		$summary = 'payments' === $target ? $queue->retry_all_failed_payments() : $queue->retry_all_failed();

		set_transient( 'mcr_ms_sync_summary_' . $target . '_' . get_current_user_id(), $summary, 60 );

		Logger::info(
			'excel_online',
			sprintf( '[%s] Retry failed syncs completed: %d synced, %d failed.', $target, $summary['synced'], $summary['failed'] ),
			get_current_user_id()
		);

		$this->redirect_to_excel_tab( $target, array( 'ms_synced' => 1 ) );
	}

	/**
	 * Processa o botão "Sync Again" de um registro individual, na tela de
	 * detalhes da inscrição - tanto para o destino Registrations quanto
	 * para o destino Payments.
	 *
	 * @return void
	 */
	public function handle_sync_single() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$target = $this->get_requested_target();

		check_admin_referer( 'mcr_ms_sync_single_' . $target . '_' . $id );

		$registration = $id ? Database::get_registration( $id ) : null;

		if ( $registration ) {
			$queue = new Excel_Sync_Queue();

			if ( 'payments' === $target ) {
				Database::reset_payment_sync_status( $id );
				$queue->sync_payment( $registration );
			} else {
				Database::reset_sync_status( $id );

				// IMPORTANTE: NÃO zerar excel_row_reference aqui. Se esta
				// inscrição já foi sincronizada antes, queremos que
				// sync_registration() ATUALIZE a linha existente no Excel, em
				// vez de criar uma linha duplicada - zerar a referência fazia
				// exatamente isso (esse era o bug original do "Sync Again").
				$queue->sync_registration( $registration );
			}
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

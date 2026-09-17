<?php
/**
 * Fila de sincronização com o Excel Online.
 *
 * Garante que a gravação de uma inscrição no banco de dados do WordPress
 * NUNCA dependa da disponibilidade da Microsoft: o registro é sempre
 * salvo primeiro, e a sincronização com o Excel acontece de forma
 * assíncrona (via WP-Cron), com retentativas automáticas e prevenção de
 * duplicidade.
 *
 * A partir da versão 2.10.0, a fila é genérica em relação ao "destino"
 * (`$target`) - "registrations" ou "payments" - reaproveitando a MESMA
 * lógica de fila/backoff/atualização-em-vez-de-duplicação para qualquer
 * tabela do Excel configurada, sem nenhum código específico de uma
 * inscrição, aluno, programa ou pagamento em particular.
 *
 * Fluxo: Contact Form 7 → Form_Handler → banco de dados do WordPress →
 * fila de sincronização → Microsoft Graph API → Excel Online.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Excel_Sync_Queue
 *
 * Responsabilidade única: decidir o que sincronizar, quando tentar de
 * novo, e evitar duplicar linhas no Excel - para qualquer destino
 * configurado.
 */
class Excel_Sync_Queue {

	/**
	 * Número máximo de tentativas automáticas antes de desistir de uma
	 * inscrição (ela continua disponível para "Retry Failed Syncs" manual).
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 5;

	/**
	 * Nome do evento de cron usado para processar a fila em segundo plano.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'mcr_process_excel_sync_queue';

	/**
	 * Nome do evento de cron usado para processar uma única inscrição
	 * imediatamente após ser criada (agendado para rodar poucos segundos
	 * depois, em segundo plano, sem atrasar a resposta ao usuário do CF7).
	 *
	 * @var string
	 */
	const CRON_HOOK_SINGLE = 'mcr_process_single_excel_sync';

	/**
	 * Registra os hooks da fila: o gatilho de nova inscrição, o
	 * processamento em segundo plano (single e em lote) e o agendamento
	 * do cron recorrente.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'mcr_registration_created', array( $this, 'enqueue_new_registration' ), 10, 1 );
		add_action( 'mcr_registration_updated', array( $this, 'enqueue_updated_registration' ), 10, 2 );
		add_action( self::CRON_HOOK_SINGLE, array( $this, 'process_single' ), 10, 2 );
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'mcr_fifteen_minutes', self::CRON_HOOK );
		}

		add_filter( 'cron_schedules', array( $this, 'register_cron_interval' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
	}

	/**
	 * Remove o evento de cron recorrente (usado na desativação do plugin).
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Registra o intervalo customizado de 15 minutos usado pelo cron da
	 * fila de sincronização.
	 *
	 * @param array $schedules Intervalos já registrados.
	 * @return array
	 */
	public function register_cron_interval( $schedules ) {
		$schedules['mcr_fifteen_minutes'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (Excel sync queue)', 'music-club-registrations' ),
		);

		return $schedules;
	}

	/**
	 * Lista, de forma genérica, os campos que pertencem ao destino
	 * "payments" e correspondem a colunas reais da tabela de inscrições
	 * (excluindo campos derivados/calculados, como o próprio Payment ID
	 * ou o nome da criança usado apenas como referência auxiliar). Usada
	 * para decidir se uma edição deve disparar uma re-sincronização do
	 * destino Payments, sem depender de nenhum campo específico
	 * "hardcoded" fora desta única lista central.
	 *
	 * @return array<int,string>
	 */
	private function get_payment_relevant_columns() {
		$mappable = array_keys( Excel_Online::get_mappable_fields( 'payments' ) );

		return array_diff( $mappable, array( 'payment_number', 'registration_number', 'child_name' ) );
	}

	/**
	 * Coloca uma inscrição recém-criada na fila de sincronização de todos
	 * os destinos configurados (Registrations e Payments), agendando o
	 * processamento em segundo plano sem bloquear a resposta ao
	 * formulário do Contact Form 7.
	 *
	 * @param int $registration_id ID da inscrição criada.
	 * @return void
	 */
	public function enqueue_new_registration( $registration_id ) {
		foreach ( Excel_OAuth::get_target_keys() as $target ) {
			$this->maybe_enqueue( $registration_id, $target, true );
		}
	}

	/**
	 * Reage a uma edição de inscrição (ex: correção de e-mail, confirmação
	 * de pagamento, mudança de status). Cada destino já sincronizado
	 * anteriormente é colocado de volta na fila para ser ATUALIZADO
	 * (nunca duplicado) - o destino "registrations" reage a qualquer
	 * campo alterado; o destino "payments" reage apenas quando um campo
	 * relevante a pagamento muda, evitando sincronizações desnecessárias.
	 *
	 * @param int   $registration_id ID da inscrição alterada.
	 * @param array $changed_fields  Campos que efetivamente mudaram (coluna => novo valor).
	 * @return void
	 */
	public function enqueue_updated_registration( $registration_id, array $changed_fields = array() ) {
		$this->maybe_enqueue( $registration_id, 'registrations', false );

		if ( array_intersect( array_keys( $changed_fields ), $this->get_payment_relevant_columns() ) ) {
			$this->maybe_enqueue( $registration_id, 'payments', false );
		}
	}

	/**
	 * Implementação compartilhada de enfileiramento, usada tanto para
	 * inscrições novas quanto para atualizações - válida para qualquer
	 * destino.
	 *
	 * @param int  $registration_id ID da inscrição.
	 * @param string $target        Chave do destino.
	 * @param bool $is_new          Se é uma inscrição nova (true) ou uma atualização (false).
	 * @return void
	 */
	private function maybe_enqueue( $registration_id, $target, $is_new ) {
		if ( ! Excel_OAuth::is_fully_configured( $target ) ) {
			return;
		}

		$target_connection = Excel_OAuth::get_target_connection( $target );

		if ( empty( $target_connection['auto_sync_enabled'] ) ) {
			return;
		}

		if ( ! $is_new ) {
			$registration = Database::get_registration( $registration_id );

			if ( ! $registration ) {
				return;
			}

			$status_field    = 'payments' === $target ? 'payment_excel_sync_status' : 'excel_sync_status';
			$reference_field = 'payments' === $target ? 'payment_excel_row_reference' : 'excel_row_reference';

			if ( 'synced' !== $registration[ $status_field ] || empty( $registration[ $reference_field ] ) ) {
				// Ainda não tinha uma linha própria neste destino - o ciclo
				// normal da fila (pending/failed) já cuida de sincronizá-la
				// quando for o caso, sem necessidade de ação extra aqui.
				return;
			}
		}

		$mark_pending = 'payments' === $target ? array( 'Music_Club_Registrations\\Database', 'mark_payment_sync_pending' ) : array( 'Music_Club_Registrations\\Database', 'mark_sync_pending' );
		call_user_func( $mark_pending, $registration_id );

		// Agenda o processamento em segundo plano (via WP-Cron) para
		// rodar assim que possível, sem atrasar a resposta atual ao
		// usuário.
		wp_schedule_single_event( time() + 5, self::CRON_HOOK_SINGLE, array( $registration_id, $target ) );

		Logger::info( 'excel_online', sprintf( '[%s] Registration #%d queued for Excel sync.', $target, $registration_id ) );
	}

	/**
	 * Processa uma única inscrição da fila (chamado pelo cron agendado
	 * logo após a criação/edição de uma inscrição).
	 *
	 * @param int    $registration_id ID da inscrição.
	 * @param string $target          Chave do destino (padrão: "registrations", para compatibilidade com eventos agendados por versões anteriores à 2.10.0).
	 * @return void
	 */
	public function process_single( $registration_id, $target = Excel_OAuth::DEFAULT_TARGET ) {
		$registration = Database::get_registration( $registration_id );

		if ( ! $registration ) {
			return;
		}

		$this->sync( $registration, $target );
	}

	/**
	 * Processa um lote da fila de sincronização do destino Registrations
	 * (pendentes + falhas elegíveis para nova tentativa, respeitando o
	 * backoff). Chamado automaticamente pelo cron recorrente, e também
	 * disponível para execução manual ("Sync Now" / "Retry Failed Syncs").
	 *
	 * @param int $limit Número máximo de inscrições a processar nesta execução.
	 * @return array{synced:int,failed:int,total:int} Resumo do processamento.
	 */
	public function process_queue( $limit = 20 ) {
		return $this->process_queue_for( 'registrations', $limit );
	}

	/**
	 * Processa um lote da fila de sincronização do destino Payments.
	 *
	 * @param int $limit Número máximo de inscrições a processar nesta execução.
	 * @return array{synced:int,failed:int,total:int}
	 */
	public function process_payment_queue( $limit = 20 ) {
		return $this->process_queue_for( 'payments', $limit );
	}

	/**
	 * Implementação genérica de processamento de lote da fila.
	 *
	 * @param string $target Chave do destino.
	 * @param int    $limit  Número máximo de inscrições a processar.
	 * @return array{synced:int,failed:int,total:int}
	 */
	private function process_queue_for( $target, $limit ) {
		if ( ! Excel_OAuth::is_fully_configured( $target ) ) {
			return array(
				'synced' => 0,
				'failed' => 0,
				'total'  => 0,
			);
		}

		$get_items = 'payments' === $target ? array( 'Music_Club_Registrations\\Database', 'get_payment_sync_queue_items' ) : array( 'Music_Club_Registrations\\Database', 'get_sync_queue_items' );
		$items     = call_user_func( $get_items, $limit, self::MAX_ATTEMPTS );

		$synced = 0;
		$failed = 0;

		foreach ( $items as $registration ) {
			if ( $this->sync( $registration, $target ) ) {
				++$synced;
			} else {
				++$failed;
			}
		}

		return array(
			'synced' => $synced,
			'failed' => $failed,
			'total'  => count( $items ),
		);
	}

	/**
	 * Processa manualmente todas as inscrições do destino Registrations
	 * atualmente com falha, ignorando o backoff automático.
	 *
	 * @return array{synced:int,failed:int,total:int}
	 */
	public function retry_all_failed() {
		return $this->retry_all_failed_for( 'registrations' );
	}

	/**
	 * Processa manualmente todas as inscrições do destino Payments
	 * atualmente com falha, ignorando o backoff automático.
	 *
	 * @return array{synced:int,failed:int,total:int}
	 */
	public function retry_all_failed_payments() {
		return $this->retry_all_failed_for( 'payments' );
	}

	/**
	 * Implementação genérica de retentativa manual das falhas.
	 *
	 * @param string $target Chave do destino.
	 * @return array{synced:int,failed:int,total:int}
	 */
	private function retry_all_failed_for( $target ) {
		if ( ! Excel_OAuth::is_fully_configured( $target ) ) {
			return array(
				'synced' => 0,
				'failed' => 0,
				'total'  => 0,
			);
		}

		$get_items = 'payments' === $target ? array( 'Music_Club_Registrations\\Database', 'get_failed_payment_sync_items' ) : array( 'Music_Club_Registrations\\Database', 'get_failed_sync_items' );
		$items     = call_user_func( $get_items, 200 );

		$synced = 0;
		$failed = 0;

		foreach ( $items as $registration ) {
			if ( $this->sync( $registration, $target ) ) {
				++$synced;
			} else {
				++$failed;
			}
		}

		return array(
			'synced' => $synced,
			'failed' => $failed,
			'total'  => count( $items ),
		);
	}

	/**
	 * Sincroniza uma única inscrição com a tabela "Registrations" do
	 * Excel Online.
	 *
	 * @param array $registration Registro completo da inscrição.
	 * @return bool True em caso de sucesso.
	 */
	public function sync_registration( array $registration ) {
		return $this->sync( $registration, 'registrations' );
	}

	/**
	 * Sincroniza o pagamento de uma única inscrição com a tabela
	 * "Payments" do Excel Online.
	 *
	 * @param array $registration Registro completo da inscrição.
	 * @return bool True em caso de sucesso.
	 */
	public function sync_payment( array $registration ) {
		return $this->sync( $registration, 'payments' );
	}

	/**
	 * Implementação única e genérica de sincronização, usada por
	 * QUALQUER destino: monta os valores da linha de acordo com o
	 * mapeamento daquele destino, decide entre ATUALIZAR uma linha já
	 * conhecida ou CRIAR uma nova, e atualiza os indicadores de
	 * sincronização correspondentes. Nenhuma parte deste método é
	 * específica de uma inscrição, aluno, programa ou pagamento -
	 * qualquer destino presente em Excel_OAuth::get_target_keys() é
	 * atendido pela mesma lógica.
	 *
	 * @param array  $registration Registro completo da inscrição (linha do banco).
	 * @param string $target       Chave do destino ("registrations" ou "payments").
	 * @return bool True em caso de sucesso.
	 */
	private function sync( array $registration, $target ) {
		$id = (int) $registration['id'];

		$is_payment      = 'payments' === $target;
		$reference_field = $is_payment ? 'payment_excel_row_reference' : 'excel_row_reference';
		$method_prefix   = $is_payment ? 'mark_payment_sync_' : 'mark_sync_';

		$mark = function ( $method, ...$args ) use ( $method_prefix ) {
			return call_user_func_array( array( 'Music_Club_Registrations\\Database', $method_prefix . $method ), $args );
		};

		$connection = Excel_OAuth::get_target_connection( $target );
		$row_values = $this->build_row_values( $registration, $target, $connection['field_mapping'] );
		$row_index  = $this->parse_row_index( $registration[ $reference_field ] ?? '' );

		$mark( 'syncing', $id );

		// Se a inscrição já possui uma linha conhecida neste destino (de
		// uma sincronização anterior bem-sucedida), ATUALIZA essa linha em
		// vez de criar uma nova - isso é o que permite que edições feitas
		// depois (corrigir um e-mail, confirmar um pagamento, etc.) sejam
		// refletidas corretamente na planilha, sem duplicar registros.
		if ( null !== $row_index ) {
			$update_result = Excel_Graph::update_table_row(
				$connection['drive_id'],
				$connection['item_id'],
				$connection['table_id'],
				$row_index,
				$row_values
			);

			if ( ! is_wp_error( $update_result ) ) {
				$mark( 'synced', $id, $registration[ $reference_field ] );

				Logger::info( 'excel_online', sprintf( '[%s] Registration #%d updated in Excel (row %d).', $target, $id, $row_index ) );

				return true;
			}

			// A linha pode ter sido apagada manualmente na planilha pelo
			// cliente - nesse caso específico, tratamos como uma nova
			// sincronização (cria a linha de novo) em vez de falhar.
			// Qualquer outro erro é reportado normalmente.
			if ( 'mcr_ms_not_found' !== $update_result->get_error_code() ) {
				$friendly_message = $this->translate_error( $update_result );

				$mark( 'failed', $id, $friendly_message );

				Logger::error(
					'excel_online',
					sprintf( '[%s] Failed to update registration #%d in Excel: %s', $target, $id, $friendly_message )
				);

				return false;
			}

			Logger::warning(
				'excel_online',
				sprintf( '[%s] The Excel row for registration #%d no longer exists (row %d); a new row will be created.', $target, $id, $row_index )
			);
		}

		$result = Excel_Graph::add_table_row(
			$connection['drive_id'],
			$connection['item_id'],
			$connection['table_id'],
			$row_values
		);

		if ( is_wp_error( $result ) ) {
			$friendly_message = $this->translate_error( $result );

			$mark( 'failed', $id, $friendly_message );

			Logger::error(
				'excel_online',
				sprintf( '[%s] Failed to sync registration #%d to Excel: %s', $target, $id, $friendly_message )
			);

			return false;
		}

		// Referência única desta linha: guardamos o índice retornado pela
		// Graph API, usado nas próximas edições para atualizar a mesma
		// linha em vez de criar uma nova.
		$row_reference = isset( $result['index'] ) ? 'row:' . $result['index'] : $target . ':' . $id;

		$mark( 'synced', $id, $row_reference );

		Logger::info( 'excel_online', sprintf( '[%s] Registration #%d synced to Excel successfully.', $target, $id ) );

		return true;
	}

	/**
	 * Extrai o índice numérico da linha do Excel a partir da referência
	 * armazenada (formato "row:N"), usado para decidir se uma
	 * sincronização deve ATUALIZAR uma linha existente em vez de criar
	 * uma nova.
	 *
	 * @param string $reference Referência armazenada.
	 * @return int|null Índice da linha, ou null se não houver uma referência válida neste formato.
	 */
	private function parse_row_index( $reference ) {
		if ( is_string( $reference ) && 0 === strpos( $reference, 'row:' ) ) {
			$index = substr( $reference, 4 );

			return is_numeric( $index ) ? (int) $index : null;
		}

		return null;
	}

	/**
	 * Monta os valores de uma linha a enviar ao Excel, na ordem das
	 * colunas da tabela de destino, a partir do mapeamento salvo para
	 * aquele destino. Colunas do Excel sem mapeamento correspondente
	 * recebem uma célula vazia, preservando a estrutura da tabela.
	 *
	 * Campos que não são colunas diretas da tabela de inscrições (como
	 * "Payment ID", derivado do próprio ID interno) são calculados aqui a
	 * partir de dados já existentes - nunca duplicando informação, apenas
	 * formatando-a para exibição.
	 *
	 * @param array  $registration  Registro completo da inscrição.
	 * @param string $target        Chave do destino.
	 * @param array  $field_mapping Mapeamento (slot => nome da coluna do Excel) daquele destino.
	 * @return array<int,string>
	 */
	private function build_row_values( array $registration, $target, array $field_mapping ) {
		if ( 'payments' === $target ) {
			$values_by_slot = array(
				'payment_number'       => mcr_format_payment_number( $registration['id'] ),
				'registration_number'  => $registration['registration_number'],
				'child_name'           => $registration['child_name'],
				'total_amount'         => $registration['total_amount'] ?? '',
				'payment_status'       => 'paid' === ( $registration['payment_status'] ?? 'unpaid' ) ? __( 'Paid', 'music-club-registrations' ) : __( 'Pending', 'music-club-registrations' ),
				'payment_confirmed_at' => ! empty( $registration['payment_confirmed_at'] ) ? $registration['payment_confirmed_at'] : '',
			);
		} else {
			$columns        = Export::get_all_columns();
			$source         = Export::build_row( $registration, $columns );
			$values_by_slot = array_combine( array_keys( $columns ), $source );
		}

		$connection    = Excel_OAuth::get_target_connection( $target );
		$excel_columns = ! empty( $connection['table_columns'] ) ? $connection['table_columns'] : array_values( $values_by_slot );

		$row = array();
		foreach ( $excel_columns as $excel_column_name ) {
			$slot  = array_search( $excel_column_name, $field_mapping, true );
			$row[] = ( false !== $slot && isset( $values_by_slot[ $slot ] ) ) ? $values_by_slot[ $slot ] : '';
		}

		return $row;
	}

	/**
	 * Converte um WP_Error técnico da Microsoft Graph API em uma mensagem
	 * amigável e acionável, sem nunca expor detalhes técnicos
	 * desnecessários (nem tokens) ao usuário final.
	 *
	 * @param \WP_Error $error Erro retornado pela camada Graph/OAuth.
	 * @return string
	 */
	private function translate_error( \WP_Error $error ) {
		$map = array(
			'mcr_ms_not_connected'       => __( 'Microsoft account is not connected. Please reconnect under Settings > Excel Online.', 'music-club-registrations' ),
			'mcr_ms_token_invalid_grant' => __( 'Microsoft authorization was revoked. Please reconnect your Microsoft account.', 'music-club-registrations' ),
			'mcr_ms_unauthorized'        => __( 'Microsoft rejected the request (unauthorized). Please reconnect your Microsoft account.', 'music-club-registrations' ),
			'mcr_ms_forbidden'           => __( 'Microsoft denied permission to write to this workbook. Please check sharing/edit permissions.', 'music-club-registrations' ),
			'mcr_ms_not_found'           => __( 'The workbook, worksheet or table could not be found. It may have been renamed, moved or deleted.', 'music-club-registrations' ),
			'mcr_ms_rate_limited'        => __( 'Microsoft Graph API rate limit reached. This will be retried automatically.', 'music-club-registrations' ),
		);

		return $map[ $error->get_error_code() ] ?? sprintf(
			/* translators: %s: raw error message from the Microsoft Graph API */
			__( 'A temporary error occurred while syncing to Excel: %s. This will be retried automatically.', 'music-club-registrations' ),
			$error->get_error_message()
		);
	}
}

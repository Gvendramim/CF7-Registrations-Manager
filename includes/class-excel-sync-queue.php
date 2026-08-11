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
 * novo, e evitar duplicar linhas no Excel.
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
		add_action( self::CRON_HOOK_SINGLE, array( $this, 'process_single' ), 10, 1 );
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
	 * Coloca uma inscrição recém-criada na fila de sincronização e agenda
	 * o processamento em segundo plano, sem bloquear a resposta ao
	 * formulário do Contact Form 7.
	 *
	 * @param int $registration_id ID da inscrição criada.
	 * @return void
	 */
	public function enqueue_new_registration( $registration_id ) {
		if ( ! Excel_OAuth::is_fully_configured() ) {
			// Integração não configurada: a coluna já nasce com o valor
			// padrão 'not_configured' e simplesmente não entra na fila.
			return;
		}

		$connection = Excel_OAuth::get_connection();

		if ( empty( $connection['auto_sync_enabled'] ) ) {
			return;
		}

		Database::mark_sync_pending( $registration_id );

		// Agenda o processamento em segundo plano (via WP-Cron) para
		// rodar assim que possível, sem atrasar a resposta atual ao
		// usuário que está preenchendo o formulário.
		wp_schedule_single_event( time() + 5, self::CRON_HOOK_SINGLE, array( $registration_id ) );

		Logger::info( 'excel_online', sprintf( 'Registration #%d queued for Excel sync.', $registration_id ) );
	}

	/**
	 * Processa uma única inscrição da fila (chamado pelo cron agendado
	 * logo após a criação de uma nova inscrição).
	 *
	 * @param int $registration_id ID da inscrição.
	 * @return void
	 */
	public function process_single( $registration_id ) {
		$registration = Database::get_registration( $registration_id );

		if ( ! $registration ) {
			return;
		}

		$this->sync_registration( $registration );
	}

	/**
	 * Processa um lote da fila de sincronização (pendentes + falhas
	 * elegíveis para nova tentativa, respeitando o backoff). Chamado
	 * automaticamente pelo cron recorrente, e também disponível para
	 * execução manual (botões "Sync Now" / "Retry Failed Syncs").
	 *
	 * @param int $limit Número máximo de inscrições a processar nesta execução.
	 * @return array{synced:int,failed:int,total:int} Resumo do processamento.
	 */
	public function process_queue( $limit = 20 ) {
		if ( ! Excel_OAuth::is_fully_configured() ) {
			return array(
				'synced' => 0,
				'failed' => 0,
				'total'  => 0,
			);
		}

		$items  = Database::get_sync_queue_items( $limit, self::MAX_ATTEMPTS );
		$synced = 0;
		$failed = 0;

		foreach ( $items as $registration ) {
			$result = $this->sync_registration( $registration );

			if ( $result ) {
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
	 * Processa manualmente todas as inscrições atualmente com falha,
	 * ignorando o backoff automático - usado pelo botão "Retry Failed
	 * Syncs".
	 *
	 * @return array{synced:int,failed:int,total:int}
	 */
	public function retry_all_failed() {
		if ( ! Excel_OAuth::is_fully_configured() ) {
			return array(
				'synced' => 0,
				'failed' => 0,
				'total'  => 0,
			);
		}

		$items  = Database::get_failed_sync_items( 200 );
		$synced = 0;
		$failed = 0;

		foreach ( $items as $registration ) {
			$result = $this->sync_registration( $registration );

			if ( $result ) {
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
	 * Sincroniza uma única inscrição com a tabela do Excel Online
	 * configurada, aplicando o mapeamento de campos salvo e prevenindo
	 * duplicidade em caso de nova tentativa.
	 *
	 * @param array $registration Registro completo da inscrição (linha do banco).
	 * @return bool True em caso de sucesso.
	 */
	public function sync_registration( array $registration ) {
		$id = (int) $registration['id'];

		// Prevenção de duplicidade: se esta inscrição já possui uma
		// referência de linha no Excel, ela já foi sincronizada com
		// sucesso anteriormente - uma nova tentativa nunca deve criar uma
		// segunda linha para o mesmo registro.
		if ( ! empty( $registration['excel_row_reference'] ) && 'synced' === $registration['excel_sync_status'] ) {
			return true;
		}

		Database::mark_sync_syncing( $id );

		$connection = Excel_OAuth::get_connection();
		$row_values = $this->build_row_values( $registration, $connection['field_mapping'] );

		$result = Excel_Graph::add_table_row(
			$connection['drive_id'],
			$connection['item_id'],
			$connection['table_id'],
			$row_values
		);

		if ( is_wp_error( $result ) ) {
			$friendly_message = $this->translate_error( $result );

			Database::mark_sync_failed( $id, $friendly_message );

			Logger::error(
				'excel_online',
				sprintf( 'Failed to sync registration #%d to Excel: %s', $id, $friendly_message )
			);

			return false;
		}

		// Referência única desta linha: usamos o índice retornado pela
		// Graph API combinado com o próprio ID da inscrição, suficiente
		// para auditoria e para os checks de "já sincronizado" acima.
		$row_reference = isset( $result['index'] ) ? 'row:' . $result['index'] : 'reg:' . $id;

		Database::mark_sync_synced( $id, $row_reference );

		Logger::info( 'excel_online', sprintf( 'Registration #%d synced to Excel successfully.', $id ) );

		return true;
	}

	/**
	 * Monta os valores da linha a enviar ao Excel, na ordem das colunas
	 * da tabela, a partir do mapeamento salvo (slot interno → nome da
	 * coluna do Excel). Colunas do Excel sem mapeamento correspondente
	 * recebem uma célula vazia, preservando a estrutura da tabela.
	 *
	 * @param array $registration  Registro completo da inscrição.
	 * @param array $field_mapping Mapeamento (slot interno => nome da coluna do Excel).
	 * @return array<int,string>
	 */
	private function build_row_values( array $registration, array $field_mapping ) {
		$columns = Export::get_all_columns();
		$source  = Export::build_row( $registration, $columns );

		// $source está na ordem de Export::get_all_columns(); montamos um
		// mapa slot => valor para permitir reordenar conforme a tabela do
		// Excel realmente está estruturada.
		$values_by_slot = array_combine( array_keys( $columns ), $source );

		$connection    = Excel_OAuth::get_connection();
		$excel_columns = ! empty( $connection['table_columns'] ) ? $connection['table_columns'] : array_values( $columns );

		$row = array();
		foreach ( $excel_columns as $excel_column_name ) {
			$slot          = array_search( $excel_column_name, $field_mapping, true );
			$row[]         = ( false !== $slot && isset( $values_by_slot[ $slot ] ) ) ? $values_by_slot[ $slot ] : '';
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

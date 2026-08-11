<?php
/**
 * Classe responsável por toda a interação com o banco de dados:
 * criação de tabelas, inserção, atualização, consultas e histórico.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Database
 *
 * Responsabilidade única: persistência e leitura de dados de inscrições.
 */
class Database {

	/**
	 * Retorna o nome completo da tabela de inscrições (com prefixo do WP).
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . MCR_TABLE_NAME;
	}

	/**
	 * Retorna o nome completo da tabela de histórico de alterações.
	 *
	 * @return string
	 */
	public static function history_table_name() {
		global $wpdb;

		return $wpdb->prefix . MCR_HISTORY_TABLE_NAME;
	}

	/**
	 * Cria (ou atualiza) as tabelas do plugin utilizando dbDelta().
	 *
	 * dbDelta() é idempotente: se a tabela já existir com a estrutura
	 * correta, nenhuma alteração destrutiva é feita. Isso garante que a
	 * tabela nunca é "recriada" - apenas criada na primeira ativação ou
	 * ajustada em atualizações futuras de schema.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$table   = self::table_name();
		$history = self::history_table_name();

		$sql_registrations = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			registration_number VARCHAR(20) NOT NULL DEFAULT '',
			child_name VARCHAR(255) NOT NULL DEFAULT '',
			child_age SMALLINT UNSIGNED NULL,
			parent_name VARCHAR(255) NOT NULL DEFAULT '',
			parent_email VARCHAR(255) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			second_parent_email VARCHAR(255) NOT NULL DEFAULT '',
			second_parent_phone VARCHAR(50) NOT NULL DEFAULT '',
			child_class VARCHAR(100) NOT NULL DEFAULT '',
			interests TEXT NULL,
			additional_message TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			internal_notes LONGTEXT NULL,
			excel_sync_status VARCHAR(20) NOT NULL DEFAULT 'not_configured',
			excel_sync_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			excel_last_sync_at DATETIME NULL,
			excel_last_sync_error TEXT NULL,
			excel_row_reference VARCHAR(100) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY registration_number (registration_number),
			KEY parent_email (parent_email),
			KEY child_name (child_name),
			KEY status (status),
			KEY created_at (created_at),
			KEY excel_sync_status (excel_sync_status)
		) {$charset_collate};";

		$sql_history = "CREATE TABLE {$history} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			registration_id BIGINT UNSIGNED NOT NULL,
			field VARCHAR(50) NOT NULL,
			old_value TEXT NULL,
			new_value TEXT NULL,
			changed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY registration_id (registration_id),
			KEY changed_at (changed_at)
		) {$charset_collate};";

		dbDelta( $sql_registrations );
		dbDelta( $sql_history );

		// Cria também a tabela de logs internos do plugin.
		Logger::install();

		// Garante que uma chave de API exista automaticamente, sem exigir
		// nenhuma configuração manual após a instalação.
		Settings::ensure_api_key();

		update_option( 'mcr_db_version', MCR_DB_VERSION );
	}

	/**
	 * Verifica se já existe uma inscrição recente com o mesmo e-mail e
	 * mesmo nome de criança dentro da janela de tempo configurada, para
	 * evitar duplicidade de envios (ex: duplo clique, reenvio de formulário).
	 *
	 * @param string $parent_email E-mail do responsável.
	 * @param string $child_name   Nome da criança.
	 * @return bool True se já existir uma inscrição duplicada recente.
	 */
	public static function has_recent_duplicate( $parent_email, $child_name ) {
		global $wpdb;

		$table = self::table_name();

		/**
		 * Filtra a janela de tempo (em minutos) usada para detectar duplicidade.
		 *
		 * O valor padrão vem da tela de Settings do plugin (opção
		 * `duplicate_window_minutes`), nunca de uma constante fixa no código.
		 *
		 * @param int $minutes Minutos configurados pelo administrador.
		 */
		$minutes = (int) apply_filters( 'mcr_duplicate_window_minutes', Settings::get( 'duplicate_window_minutes', 5 ) );

		if ( $minutes <= 0 ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $table é um nome de tabela controlado internamente.
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			WHERE parent_email = %s
			AND child_name = %s
			AND created_at >= (NOW() - INTERVAL %d MINUTE)",
			$parent_email,
			$child_name,
			$minutes
		);

		$count = (int) $wpdb->get_var( $sql );

		return $count > 0;
	}

	/**
	 * Insere uma nova inscrição no banco de dados.
	 *
	 * @param array $data Dados já sanitizados e validados da inscrição.
	 * @return int|\WP_Error ID do novo registro, ou WP_Error em caso de falha.
	 */
	public static function insert_registration( array $data ) {
		global $wpdb;

		$table = self::table_name();

		$defaults = array(
			'child_name'          => '',
			'child_age'           => null,
			'parent_name'         => '',
			'parent_email'        => '',
			'phone'               => '',
			'second_parent_email' => '',
			'second_parent_phone' => '',
			'child_class'         => '',
			'interests'           => '',
			'additional_message'  => '',
			'status'              => 'new',
			'internal_notes'      => '',
		);

		$data = wp_parse_args( $data, $defaults );

		$now = current_time( 'mysql' );

		$inserted = $wpdb->insert(
			$table,
			array(
				'registration_number' => '', // Preenchido logo após o insert, com base no ID gerado.
				'child_name'           => $data['child_name'],
				'child_age'            => $data['child_age'], // null quando não informado/inválido - nunca 0 ou string vazia.
				'parent_name'          => $data['parent_name'],
				'parent_email'         => $data['parent_email'],
				'phone'                => $data['phone'],
				'second_parent_email'  => $data['second_parent_email'],
				'second_parent_phone'  => $data['second_parent_phone'],
				'child_class'          => $data['child_class'],
				'interests'            => $data['interests'],
				'additional_message'   => $data['additional_message'],
				'status'               => $data['status'],
				'internal_notes'       => $data['internal_notes'],
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			Logger::error( 'db', sprintf( 'Failed to insert registration: %s', $wpdb->last_error ) );

			return new \WP_Error(
				'mcr_insert_failed',
				__( 'Não foi possível salvar a inscrição no banco de dados.', 'music-club-registrations' )
			);
		}

		$id = (int) $wpdb->insert_id;

		// Gera e grava o número sequencial de inscrição com base no ID gerado.
		$registration_number = mcr_format_registration_number( $id );

		$wpdb->update(
			$table,
			array( 'registration_number' => $registration_number ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		self::log_history( $id, 'status', '', $data['status'], 0 );

		return $id;
	}

	/**
	 * Busca uma inscrição pelo ID.
	 *
	 * @param int $id ID do registro.
	 * @return array|null
	 */
	public static function get_registration( $id ) {
		global $wpdb;

		$table = self::table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Atualiza o status de uma inscrição, registrando o histórico da alteração.
	 *
	 * @param int    $id         ID do registro.
	 * @param string $new_status Novo status.
	 * @param int    $user_id    ID do usuário que realizou a alteração.
	 * @return bool
	 */
	public static function update_status( $id, $new_status, $user_id = 0 ) {
		global $wpdb;

		if ( ! mcr_is_valid_status( $new_status ) ) {
			return false;
		}

		$current = self::get_registration( $id );

		if ( ! $current ) {
			return false;
		}

		$table = self::table_name();

		$updated = $wpdb->update(
			$table,
			array(
				'status'     => $new_status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		if ( $current['status'] !== $new_status ) {
			self::log_history( $id, 'status', $current['status'], $new_status, $user_id );

			Logger::info(
				'status',
				sprintf(
					'Registration #%d status changed from "%s" to "%s".',
					absint( $id ),
					$current['status'],
					$new_status
				),
				$user_id
			);
		}

		return true;
	}

	/**
	 * Atualiza as observações internas de uma inscrição.
	 *
	 * @param int    $id      ID do registro.
	 * @param string $notes   Novo conteúdo das observações internas.
	 * @param int    $user_id ID do usuário que realizou a alteração.
	 * @return bool
	 */
	public static function update_internal_notes( $id, $notes, $user_id = 0 ) {
		global $wpdb;

		$current = self::get_registration( $id );

		if ( ! $current ) {
			return false;
		}

		$table = self::table_name();

		$updated = $wpdb->update(
			$table,
			array(
				'internal_notes' => $notes,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( false === $updated ) {
			return false;
		}

		if ( $current['internal_notes'] !== $notes ) {
			self::log_history( $id, 'internal_notes', $current['internal_notes'], $notes, $user_id );
		}

		return true;
	}

	/**
	 * Exclui uma inscrição (e seu histórico associado).
	 *
	 * @param int $id ID do registro.
	 * @return bool
	 */
	public static function delete_registration( $id ) {
		global $wpdb;

		$table   = self::table_name();
		$history = self::history_table_name();

		$wpdb->delete( $history, array( 'registration_id' => absint( $id ) ), array( '%d' ) );

		$deleted = $wpdb->delete( $table, array( 'id' => absint( $id ) ), array( '%d' ) );

		return false !== $deleted;
	}

	/**
	 * -----------------------------------------------------------------------
	 * Sincronização com o Excel Online (fila e status por registro)
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Marca uma inscrição como pendente de sincronização com o Excel
	 * Online, sem alterar o contador de tentativas.
	 *
	 * @param int $id ID da inscrição.
	 * @return void
	 */
	public static function mark_sync_pending( $id ) {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( 'excel_sync_status' => 'pending' ),
			array( 'id' => absint( $id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marca uma inscrição como "sincronizando no momento", evitando que a
	 * mesma inscrição seja processada duas vezes em execuções concorrentes
	 * da fila.
	 *
	 * @param int $id ID da inscrição.
	 * @return void
	 */
	public static function mark_sync_syncing( $id ) {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( 'excel_sync_status' => 'syncing' ),
			array( 'id' => absint( $id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marca uma inscrição como sincronizada com sucesso, registrando a
	 * referência da linha criada no Excel (usada para evitar duplicidade
	 * em novas tentativas).
	 *
	 * @param int    $id            ID da inscrição.
	 * @param string $row_reference Referência da linha no Excel (ex: número da linha ou ID da tabela).
	 * @return void
	 */
	public static function mark_sync_synced( $id, $row_reference = '' ) {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'excel_sync_status'     => 'synced',
				'excel_last_sync_at'    => current_time( 'mysql' ),
				'excel_last_sync_error' => '',
				'excel_row_reference'   => $row_reference,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marca uma inscrição como falha na sincronização, incrementando o
	 * contador de tentativas e registrando a mensagem de erro (nunca um
	 * token ou segredo - apenas uma mensagem amigável/técnica do erro).
	 *
	 * @param int    $id      ID da inscrição.
	 * @param string $message Mensagem de erro.
	 * @return void
	 */
	public static function mark_sync_failed( $id, $message ) {
		global $wpdb;

		$table = self::table_name();

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET excel_sync_status = 'failed',
					excel_sync_attempts = excel_sync_attempts + 1,
					excel_last_sync_at = %s,
					excel_last_sync_error = %s
				WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql' ),
				$message,
				absint( $id )
			)
		);
	}

	/**
	 * Reseta o status de sincronização de uma inscrição para "pending",
	 * usado pelo botão "Sync Again" na tela de detalhes.
	 *
	 * @param int $id ID da inscrição.
	 * @return void
	 */
	public static function reset_sync_status( $id ) {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'excel_sync_status'     => 'pending',
				'excel_last_sync_error' => '',
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marca todas as inscrições configuradas para sincronização (ou seja,
	 * que ainda não foram tentadas) com um status inicial "not_configured"
	 * → "pending", usado quando a integração é conectada/ativada pela
	 * primeira vez.
	 *
	 * @return void
	 */
	public static function mark_all_not_configured_as_pending() {
		global $wpdb;

		$table = self::table_name();

		$wpdb->query(
			"UPDATE {$table} SET excel_sync_status = 'pending' WHERE excel_sync_status = 'not_configured'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Marca todas as inscrições ainda não sincronizadas (pendentes,
	 * falhas ou nunca configuradas) como "pending", usado pela opção
	 * "Sync All" do botão "Sync Now" para reprocessar todo o histórico
	 * após a integração ser configurada.
	 *
	 * @return void
	 */
	public static function mark_unsynced_as_pending() {
		global $wpdb;

		$table = self::table_name();

		$wpdb->query(
			"UPDATE {$table} SET excel_sync_status = 'pending' WHERE excel_sync_status != 'synced'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/**
	 * Retorna inscrições elegíveis para a fila de sincronização: as
	 * pendentes, mais as que falharam e ainda não atingiram o número
	 * máximo de tentativas (respeitando um pequeno backoff baseado no
	 * número de tentativas já realizadas).
	 *
	 * @param int $limit       Número máximo de inscrições a retornar.
	 * @param int $max_attempts Número máximo de tentativas antes de desistir automaticamente.
	 * @return array
	 */
	public static function get_sync_queue_items( $limit = 20, $max_attempts = 5 ) {
		global $wpdb;

		$table = self::table_name();
		$limit = max( 1, absint( $limit ) );

		// Backoff simples: quanto mais tentativas, mais tempo esperamos
		// antes de tentar de novo (1, 2, 4, 8, 16 minutos...).
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table}
			WHERE excel_sync_status = 'pending'
			   OR (
					excel_sync_status = 'failed'
					AND excel_sync_attempts < %d
					AND (
						excel_last_sync_at IS NULL
						OR excel_last_sync_at <= (NOW() - INTERVAL POW(2, excel_sync_attempts) MINUTE)
					)
			   )
			ORDER BY created_at ASC
			LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$max_attempts,
			$limit
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $rows ?: array();
	}

	/**
	 * Retorna todas as inscrições com status de falha, ignorando o
	 * backoff - usado pelo botão manual "Retry Failed Syncs".
	 *
	 * @param int $limit Número máximo de inscrições a retornar.
	 * @return array
	 */
	public static function get_failed_sync_items( $limit = 100 ) {
		global $wpdb;

		$table = self::table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE excel_sync_status = 'failed' ORDER BY created_at ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, absint( $limit ) )
			),
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Retorna os indicadores de sincronização usados no Dashboard e na
	 * tela de Excel Integration: quantas inscrições estão pendentes,
	 * sincronizadas ou com falha.
	 *
	 * @return array{pending:int,synced:int,failed:int,not_configured:int,last_sync_at:?string}
	 */
	public static function get_sync_stats() {
		global $wpdb;

		$table = self::table_name();

		$counts = array(
			'pending'        => 0,
			'synced'         => 0,
			'failed'         => 0,
			'not_configured' => 0,
		);

		$rows = $wpdb->get_results( "SELECT excel_sync_status, COUNT(*) AS total FROM {$table} GROUP BY excel_sync_status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		foreach ( $rows ?: array() as $row ) {
			if ( isset( $counts[ $row['excel_sync_status'] ] ) ) {
				$counts[ $row['excel_sync_status'] ] = (int) $row['total'];
			}
		}

		$last_sync_at = $wpdb->get_var( "SELECT MAX(excel_last_sync_at) FROM {$table} WHERE excel_sync_status = 'synced'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts['last_sync_at'] = $last_sync_at ?: null;

		return $counts;
	}

	/**
	 * Registra uma entrada no histórico de alterações de uma inscrição.
	 *
	 * @param int    $registration_id ID da inscrição.
	 * @param string $field           Campo alterado.
	 * @param string $old_value       Valor anterior.
	 * @param string $new_value       Novo valor.
	 * @param int    $user_id         ID do usuário responsável pela alteração.
	 * @return void
	 */
	public static function log_history( $registration_id, $field, $old_value, $new_value, $user_id = 0 ) {
		global $wpdb;

		$wpdb->insert(
			self::history_table_name(),
			array(
				'registration_id' => absint( $registration_id ),
				'field'            => sanitize_key( $field ),
				'old_value'        => $old_value,
				'new_value'        => $new_value,
				'changed_by'       => absint( $user_id ),
				'changed_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Retorna o histórico de alterações de uma inscrição, mais recente primeiro.
	 *
	 * @param int $registration_id ID da inscrição.
	 * @return array
	 */
	public static function get_history( $registration_id ) {
		global $wpdb;

		$table = self::history_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE registration_id = %d ORDER BY changed_at DESC", absint( $registration_id ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $rows ?: array();
	}

	/**
	 * Consulta paginada/filtrada de inscrições, utilizada pela WP_List_Table
	 * e pela API REST.
	 *
	 * @param array $args {
	 *     Argumentos de consulta.
	 *
	 *     @type string $search    Termo de busca (nome, e-mail, telefone, número).
	 *     @type string $status    Filtrar por status.
	 *     @type string $orderby   Coluna de ordenação.
	 *     @type string $order     ASC|DESC.
	 *     @type int    $per_page  Itens por página.
	 *     @type int    $page      Página atual (1-indexed).
	 * }
	 * @return array{items: array, total: int}
	 */
	public static function query_registrations( array $args = array() ) {
		global $wpdb;

		$table = self::table_name();

		$defaults = array(
			'search'   => '',
			'status'   => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'page'     => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		$allowed_orderby = array( 'id', 'registration_number', 'child_name', 'parent_name', 'parent_email', 'phone', 'child_class', 'created_at', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order            = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) && mcr_is_valid_status( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(child_name LIKE %s OR parent_name LIKE %s OR parent_email LIKE %s OR phone LIKE %s OR second_parent_email LIKE %s OR second_parent_phone LIKE %s OR registration_number LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		$per_page = max( 1, absint( $args['per_page'] ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		// Total de registros (para paginação), respeitando os mesmos filtros.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql );

		$query_values   = $values;
		$query_values[] = $per_page;
		$query_values[] = $offset;

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$sql = $wpdb->prepare( $sql, $query_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = $wpdb->get_results( $sql, ARRAY_A );

		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Retorna todos os IDs correspondentes a um conjunto de filtros
	 * (utilizado para exportações "Todos" e "Filtrados").
	 *
	 * @param array $args Mesmos argumentos aceitos por query_registrations(), sem paginação.
	 * @return array<int,int>
	 */
	public static function get_all_ids( array $args = array() ) {
		$args['per_page'] = PHP_INT_MAX;
		$args['page']     = 1;

		global $wpdb;
		$table = self::table_name();

		$defaults = array(
			'search' => '',
			'status' => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['status'] ) && mcr_is_valid_status( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = $args['status'];
		}

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(child_name LIKE %s OR parent_name LIKE %s OR parent_email LIKE %s OR phone LIKE %s OR second_parent_email LIKE %s OR second_parent_phone LIKE %s OR registration_number LIKE %s)';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT id FROM {$table} WHERE {$where_sql}";

		if ( ! empty( $values ) ) {
			$sql = $wpdb->prepare( $sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'absint', $ids ?: array() );
	}

	/**
	 * Busca múltiplas inscrições por uma lista de IDs, preservando a ordem
	 * mais recente primeiro.
	 *
	 * @param array<int,int> $ids Lista de IDs.
	 * @return array
	 */
	public static function get_registrations_by_ids( array $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', $ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$table        = self::table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE id IN ({$placeholders}) ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$ids
		);

		$rows = $wpdb->get_results( $sql, ARRAY_A );

		return $rows ?: array();
	}

	/**
	 * Retorna os indicadores utilizados na tela de Dashboard: totais gerais,
	 * totais por período e totais por status.
	 *
	 * @return array
	 */
	public static function get_dashboard_stats() {
		global $wpdb;

		$table = self::table_name();

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$today = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$this_week = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$this_month = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$by_status = array();
		foreach ( array_keys( mcr_get_statuses() ) as $status ) {
			$by_status[ $status ] = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
		}

		return array(
			'total'      => $total,
			'today'      => $today,
			'this_week'  => $this_week,
			'this_month' => $this_month,
			'by_status'  => $by_status,
		);
	}

	/**
	 * Retorna o número de inscrições por dia, nos últimos N dias, para
	 * alimentar o gráfico de linha do dashboard.
	 *
	 * @param int $days Número de dias a considerar (padrão: 30).
	 * @return array<int,array{date:string,count:int}>
	 */
	public static function get_registrations_per_day( $days = 30 ) {
		global $wpdb;

		$table = self::table_name();
		$days  = max( 1, absint( $days ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS reg_date, COUNT(*) AS reg_count
				FROM {$table}
				WHERE created_at >= (CURDATE() - INTERVAL %d DAY)
				GROUP BY DATE(created_at)
				ORDER BY reg_date ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$days
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows ?: array() as $row ) {
			$counts[ $row['reg_date'] ] = (int) $row['reg_count'];
		}

		$series = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date            = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
			$series[]        = array(
				'date'  => $date,
				'count' => $counts[ $date ] ?? 0,
			);
		}

		return $series;
	}

	/**
	 * Retorna o número de inscrições por turma/classe, ordenado do maior
	 * para o menor, para alimentar o gráfico "Inscrições por Turma" do
	 * dashboard. Usa uma consulta agregada e indexada (coluna child_class),
	 * evitando carregar todos os registros em memória.
	 *
	 * @param int $limit Número máximo de turmas a retornar (padrão: 10).
	 * @return array<int,array{class:string,count:int}>
	 */
	public static function get_registrations_by_class( $limit = 10 ) {
		global $wpdb;

		$table = self::table_name();
		$limit = max( 1, absint( $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CASE WHEN child_class = '' THEN %s ELSE child_class END AS class_label,
					COUNT(*) AS reg_count
				FROM {$table}
				GROUP BY class_label
				ORDER BY reg_count DESC
				LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				__( 'Not specified', 'music-club-registrations' ),
				$limit
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $rows ?: array() as $row ) {
			$result[] = array(
				'class' => $row['class_label'],
				'count' => (int) $row['reg_count'],
			);
		}

		return $result;
	}

	/**
	 * Retorna o número de inscrições por programa/interesse selecionado,
	 * para o gráfico "Registrations by Program" do dashboard. Como uma
	 * mesma inscrição pode selecionar vários programas (campo
	 * `interests`, armazenado como texto separado por vírgulas), a
	 * contagem é feita em PHP após ler os valores já persistidos - não é
	 * possível agrupar corretamente por SQL puro em um campo
	 * multivalorado.
	 *
	 * @param int $limit Número máximo de programas a retornar (padrão: 10).
	 * @return array<int,array{interest:string,count:int}>
	 */
	public static function get_registrations_by_interest( $limit = 10 ) {
		global $wpdb;

		$table = self::table_name();
		$limit = max( 1, absint( $limit ) );

		$values = $wpdb->get_col( "SELECT interests FROM {$table} WHERE interests != ''" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$counts = array();
		foreach ( $values ?: array() as $interests_string ) {
			foreach ( mcr_interests_to_array( $interests_string ) as $interest ) {
				$counts[ $interest ] = ( $counts[ $interest ] ?? 0 ) + 1;
			}
		}

		arsort( $counts );
		$counts = array_slice( $counts, 0, $limit, true );

		$result = array();
		foreach ( $counts as $interest => $count ) {
			$result[] = array(
				'interest' => $interest,
				'count'    => $count,
			);
		}

		return $result;
	}

	/**
	 * Retorna o número de inscrições por mês, nos últimos N meses, para o
	 * gráfico "Inscrições por Mês" do dashboard.
	 *
	 * @param int $months Número de meses a considerar (padrão: 12).
	 * @return array<int,array{month:string,count:int}>
	 */
	public static function get_registrations_per_month( $months = 12 ) {
		global $wpdb;

		$table  = self::table_name();
		$months = max( 1, absint( $months ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(created_at, '%%Y-%%m') AS reg_month, COUNT(*) AS reg_count
				FROM {$table}
				WHERE created_at >= (CURDATE() - INTERVAL %d MONTH)
				GROUP BY reg_month
				ORDER BY reg_month ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$months
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows ?: array() as $row ) {
			$counts[ $row['reg_month'] ] = (int) $row['reg_count'];
		}

		$series = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$month      = gmdate( 'Y-m', strtotime( "-{$i} months" ) );
			$series[]   = array(
				'month' => $month,
				'count' => $counts[ $month ] ?? 0,
			);
		}

		return $series;
	}
}

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
			parent_name VARCHAR(255) NOT NULL DEFAULT '',
			parent_email VARCHAR(255) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			child_class VARCHAR(100) NOT NULL DEFAULT '',
			interests TEXT NULL,
			additional_message TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			internal_notes LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY registration_number (registration_number),
			KEY parent_email (parent_email),
			KEY child_name (child_name),
			KEY status (status),
			KEY created_at (created_at)
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
			'parent_name'         => '',
			'parent_email'        => '',
			'phone'               => '',
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
				'parent_name'          => $data['parent_name'],
				'parent_email'         => $data['parent_email'],
				'phone'                => $data['phone'],
				'child_class'          => $data['child_class'],
				'interests'            => $data['interests'],
				'additional_message'   => $data['additional_message'],
				'status'               => $data['status'],
				'internal_notes'       => $data['internal_notes'],
				'created_at'           => $now,
				'updated_at'           => $now,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
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
			$where[]  = '(child_name LIKE %s OR parent_name LIKE %s OR parent_email LIKE %s OR phone LIKE %s OR registration_number LIKE %s)';
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
			$where[]  = '(child_name LIKE %s OR parent_name LIKE %s OR parent_email LIKE %s OR phone LIKE %s OR registration_number LIKE %s)';
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
}

<?php
/**
 * Sistema interno de logs do plugin: registra erros de banco de dados,
 * falhas na API, exportações realizadas, alterações de status e erros
 * inesperados, disponibilizando uma tela administrativa de consulta.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Logger
 *
 * Responsabilidade única: gravação e leitura de entradas de log.
 */
class Logger {

	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Nome da tabela de logs (sem prefixo).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'music_club_logs';

	/**
	 * Retorna o nome completo da tabela de logs, com prefixo do WordPress.
	 *
	 * @return string
	 */
	public static function table_name() {
		global $wpdb;

		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Cria a tabela de logs via dbDelta(). Chamado junto com o restante da
	 * instalação do banco de dados do plugin.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table            = self::table_name();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			context VARCHAR(50) NOT NULL DEFAULT '',
			message TEXT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY context (context),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Registra uma nova entrada de log.
	 *
	 * @param string $level   Nível: info, warning ou error.
	 * @param string $context Contexto: db, api, export, status, form, general.
	 * @param string $message Mensagem descritiva.
	 * @param int    $user_id ID do usuário responsável pela ação, quando aplicável.
	 * @return void
	 */
	public static function log( $level, $context, $message, $user_id = 0 ) {
		global $wpdb;

		$allowed_levels = array( self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR );

		if ( ! in_array( $level, $allowed_levels, true ) ) {
			$level = self::LEVEL_INFO;
		}

		$wpdb->insert(
			self::table_name(),
			array(
				'level'      => $level,
				'context'    => sanitize_key( $context ),
				'message'    => $message,
				'user_id'    => absint( $user_id ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Atalho para registrar uma entrada de erro.
	 *
	 * @param string $context Contexto do erro.
	 * @param string $message Mensagem descritiva.
	 * @param int    $user_id ID do usuário, quando aplicável.
	 * @return void
	 */
	public static function error( $context, $message, $user_id = 0 ) {
		self::log( self::LEVEL_ERROR, $context, $message, $user_id );
	}

	/**
	 * Atalho para registrar uma entrada de aviso.
	 *
	 * @param string $context Contexto do aviso.
	 * @param string $message Mensagem descritiva.
	 * @param int    $user_id ID do usuário, quando aplicável.
	 * @return void
	 */
	public static function warning( $context, $message, $user_id = 0 ) {
		self::log( self::LEVEL_WARNING, $context, $message, $user_id );
	}

	/**
	 * Atalho para registrar uma entrada informativa.
	 *
	 * @param string $context Contexto da informação.
	 * @param string $message Mensagem descritiva.
	 * @param int    $user_id ID do usuário, quando aplicável.
	 * @return void
	 */
	public static function info( $context, $message, $user_id = 0 ) {
		self::log( self::LEVEL_INFO, $context, $message, $user_id );
	}

	/**
	 * Consulta paginada/filtrada de logs, utilizada pela tela administrativa.
	 *
	 * @param array $args {
	 *     @type string $level    Filtrar por nível.
	 *     @type string $context  Filtrar por contexto.
	 *     @type int    $per_page Itens por página.
	 *     @type int    $page     Página atual (1-indexed).
	 * }
	 * @return array{items: array, total: int}
	 */
	public static function query_logs( array $args = array() ) {
		global $wpdb;

		$table = self::table_name();

		$defaults = array(
			'level'    => '',
			'context'  => '',
			'per_page' => 20,
			'page'     => 1,
		);

		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$values[] = $args['level'];
		}

		if ( ! empty( $args['context'] ) ) {
			$where[]  = 'context = %s';
			$values[] = $args['context'];
		}

		$where_sql = implode( ' AND ', $where );

		$per_page = max( 1, absint( $args['per_page'] ) );
		$page     = max( 1, absint( $args['page'] ) );
		$offset   = ( $page - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		if ( ! empty( $values ) ) {
			$count_sql = $wpdb->prepare( $count_sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		$total = (int) $wpdb->get_var( $count_sql );

		$query_values   = $values;
		$query_values[] = $per_page;
		$query_values[] = $offset;

		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$sql = $wpdb->prepare( $sql, $query_values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$items = $wpdb->get_results( $sql, ARRAY_A );

		return array(
			'items' => $items ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Remove todas as entradas de log (usado pelo botão "Clear Logs").
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = self::table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}
}

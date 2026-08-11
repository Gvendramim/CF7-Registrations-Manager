<?php
/**
 * Cliente da Microsoft Graph API para descoberta de arquivos do
 * OneDrive/SharePoint, worksheets, tabelas do Excel e escrita de linhas.
 *
 * Toda comunicação exige um access_token válido (obtido via Excel_OAuth).
 * Esta classe nunca lida com Client ID/Secret diretamente - apenas com o
 * token já emitido.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Excel_Graph
 *
 * Responsabilidade única: chamadas HTTP à Microsoft Graph API relativas a
 * arquivos do Excel (workbooks, worksheets, tabelas, colunas e linhas).
 */
class Excel_Graph {

	/**
	 * URL base da Microsoft Graph API.
	 *
	 * @var string
	 */
	const BASE_URL = 'https://graph.microsoft.com/v1.0';

	/**
	 * Executa uma chamada autenticada à Microsoft Graph API, renovando o
	 * token automaticamente quando necessário.
	 *
	 * @param string $method Método HTTP (GET, POST, PATCH, DELETE...).
	 * @param string $path   Caminho relativo à API (ex: "/me/drive/root").
	 * @param array  $args   Argumentos extras (body, headers) para wp_remote_request().
	 * @return array|\WP_Error Corpo decodificado (array) em caso de sucesso, ou WP_Error.
	 */
	private static function request( $method, $path, array $args = array() ) {
		$access_token = Excel_OAuth::get_valid_access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$url = 0 === strpos( $path, 'http' ) ? $path : self::BASE_URL . $path;

		$defaults = array(
			'method'  => $method,
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
			),
		);

		if ( isset( $args['body'] ) && is_array( $args['body'] ) ) {
			$defaults['headers']['Content-Type'] = 'application/json';
			$defaults['body']                    = wp_json_encode( $args['body'] );
			unset( $args['body'] );
		}

		$request_args = wp_parse_args( $args, $defaults );

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			return self::error_from_response( $status, $body );
		}

		return is_array( $body ) ? $body : array();
	}

	/**
	 * Converte uma resposta de erro da Microsoft Graph API em um WP_Error
	 * com uma mensagem amigável e um código identificável, usado pela
	 * camada de UI para orientar o usuário sobre o que fazer.
	 *
	 * @param int        $status HTTP status code.
	 * @param array|null $body   Corpo decodificado da resposta.
	 * @return \WP_Error
	 */
	private static function error_from_response( $status, $body ) {
		$graph_code    = $body['error']['code'] ?? '';
		$graph_message = $body['error']['message'] ?? sprintf( 'HTTP %d', $status );

		$map = array(
			401 => 'mcr_ms_unauthorized',
			403 => 'mcr_ms_forbidden',
			404 => 'mcr_ms_not_found',
			429 => 'mcr_ms_rate_limited',
		);

		$code = $map[ $status ] ?? 'mcr_ms_graph_error';

		return new \WP_Error( $code, $graph_message, array( 'status' => $status, 'graph_code' => $graph_code ) );
	}

	/**
	 * Lista arquivos Excel (.xlsx) disponíveis para a conta conectada,
	 * usando a busca nativa do OneDrive/SharePoint. O usuário nunca
	 * precisa informar IDs de drive ou de arquivo - eles são descobertos
	 * automaticamente aqui.
	 *
	 * @return array<int,array{drive_id:string,item_id:string,name:string,path:string}>|\WP_Error
	 */
	public static function list_workbooks() {
		$result = self::request( 'GET', "/me/drive/root/search(q='.xlsx')?\$select=id,name,parentReference,webUrl" );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = array();

		foreach ( $result['value'] ?? array() as $item ) {
			if ( empty( $item['name'] ) || false === stripos( $item['name'], '.xlsx' ) ) {
				continue;
			}

			$items[] = array(
				'drive_id' => $item['parentReference']['driveId'] ?? '',
				'item_id'  => $item['id'],
				'name'     => $item['name'],
				'path'     => $item['parentReference']['path'] ?? '',
			);
		}

		return $items;
	}

	/**
	 * Lista as worksheets (abas) de um workbook.
	 *
	 * @param string $drive_id ID do drive.
	 * @param string $item_id  ID do arquivo.
	 * @return array<int,array{name:string}>|\WP_Error
	 */
	public static function list_worksheets( $drive_id, $item_id ) {
		$result = self::request( 'GET', self::workbook_path( $drive_id, $item_id ) . '/worksheets' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$sheets = array();
		foreach ( $result['value'] ?? array() as $sheet ) {
			$sheets[] = array( 'name' => $sheet['name'] );
		}

		return $sheets;
	}

	/**
	 * Lista as tabelas do Excel (Excel Tables, não apenas intervalos)
	 * existentes em uma worksheet específica.
	 *
	 * @param string $drive_id       ID do drive.
	 * @param string $item_id        ID do arquivo.
	 * @param string $worksheet_name Nome da worksheet.
	 * @return array<int,array{id:string,name:string}>|\WP_Error
	 */
	public static function list_tables( $drive_id, $item_id, $worksheet_name ) {
		$path = self::workbook_path( $drive_id, $item_id ) . '/worksheets/' . rawurlencode( $worksheet_name ) . '/tables';

		$result = self::request( 'GET', $path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$tables = array();
		foreach ( $result['value'] ?? array() as $table ) {
			$tables[] = array(
				'id'   => $table['id'],
				'name' => $table['name'],
			);
		}

		return $tables;
	}

	/**
	 * Lista as colunas de uma tabela do Excel, usadas para o mapeamento
	 * automático de campos.
	 *
	 * @param string $drive_id ID do drive.
	 * @param string $item_id  ID do arquivo.
	 * @param string $table_id ID (ou nome) da tabela.
	 * @return array<int,string>|\WP_Error Lista de nomes de colunas, na ordem da tabela.
	 */
	public static function list_table_columns( $drive_id, $item_id, $table_id ) {
		$path = self::workbook_path( $drive_id, $item_id ) . '/tables/' . rawurlencode( $table_id ) . '/columns';

		$result = self::request( 'GET', $path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$columns = array();
		foreach ( $result['value'] ?? array() as $column ) {
			$columns[] = $column['name'];
		}

		return $columns;
	}

	/**
	 * Adiciona uma nova linha ao final de uma tabela do Excel.
	 *
	 * @param string $drive_id ID do drive.
	 * @param string $item_id  ID do arquivo.
	 * @param string $table_id ID (ou nome) da tabela.
	 * @param array  $values   Valores da linha, na mesma ordem das colunas da tabela.
	 * @return array|\WP_Error Linha criada (incluindo seu índice), ou WP_Error.
	 */
	public static function add_table_row( $drive_id, $item_id, $table_id, array $values ) {
		$path = self::workbook_path( $drive_id, $item_id ) . '/tables/' . rawurlencode( $table_id ) . '/rows';

		return self::request(
			'POST',
			$path,
			array(
				'body' => array( 'values' => array( array_values( $values ) ) ),
			)
		);
	}

	/**
	 * Monta o caminho base da Graph API para o objeto "workbook" de um
	 * arquivo específico.
	 *
	 * @param string $drive_id ID do drive.
	 * @param string $item_id  ID do arquivo.
	 * @return string
	 */
	private static function workbook_path( $drive_id, $item_id ) {
		return sprintf( '/drives/%s/items/%s/workbook', rawurlencode( $drive_id ), rawurlencode( $item_id ) );
	}

	/**
	 * Executa o checklist completo de "Test Connection": autenticação,
	 * validade do token, acesso ao workbook, existência da worksheet e da
	 * tabela, e permissão de escrita (via uma escrita de teste seguida de
	 * remoção imediata da linha).
	 *
	 * @return array{success:bool,checks:array<int,array{label:string,ok:bool,detail:string}>}
	 */
	public static function test_connection() {
		$checks = array();

		// 1. Autenticação / token válido.
		$token    = Excel_OAuth::get_valid_access_token();
		$checks[] = array(
			'label'  => __( 'Microsoft account connected', 'music-club-registrations' ),
			'ok'     => ! is_wp_error( $token ),
			'detail' => is_wp_error( $token ) ? $token->get_error_message() : __( 'Token is valid.', 'music-club-registrations' ),
		);

		if ( is_wp_error( $token ) ) {
			return array(
				'success' => false,
				'checks'  => $checks,
			);
		}

		$connection = Excel_OAuth::get_connection();

		if ( empty( $connection['drive_id'] ) || empty( $connection['item_id'] ) ) {
			$checks[] = array(
				'label'  => __( 'Workbook selected', 'music-club-registrations' ),
				'ok'     => false,
				'detail' => __( 'No workbook has been selected yet.', 'music-club-registrations' ),
			);

			return array(
				'success' => false,
				'checks'  => $checks,
			);
		}

		// 2. Workbook acessível.
		$workbook_info = self::request( 'GET', self::workbook_path( $connection['drive_id'], $connection['item_id'] ) . '/application' );
		$checks[]      = array(
			'label'  => __( 'Workbook accessible', 'music-club-registrations' ),
			'ok'     => ! is_wp_error( $workbook_info ),
			'detail' => is_wp_error( $workbook_info ) ? $workbook_info->get_error_message() : $connection['workbook_name'],
		);

		if ( is_wp_error( $workbook_info ) ) {
			return array(
				'success' => false,
				'checks'  => $checks,
			);
		}

		// 3. Worksheet encontrada.
		$worksheets      = self::list_worksheets( $connection['drive_id'], $connection['item_id'] );
		$worksheet_found = ! is_wp_error( $worksheets ) && in_array( $connection['worksheet_name'], wp_list_pluck( $worksheets, 'name' ), true );
		$checks[]        = array(
			'label'  => __( 'Worksheet found', 'music-club-registrations' ),
			'ok'     => $worksheet_found,
			'detail' => $worksheet_found ? $connection['worksheet_name'] : __( 'The selected worksheet was not found. It may have been renamed or deleted.', 'music-club-registrations' ),
		);

		if ( ! $worksheet_found ) {
			return array(
				'success' => false,
				'checks'  => $checks,
			);
		}

		// 4. Tabela encontrada.
		$tables      = self::list_tables( $connection['drive_id'], $connection['item_id'], $connection['worksheet_name'] );
		$table_found = ! is_wp_error( $tables ) && in_array( $connection['table_id'], wp_list_pluck( $tables, 'id' ), true );
		$checks[]    = array(
			'label'  => __( 'Table found', 'music-club-registrations' ),
			'ok'     => $table_found,
			'detail' => $table_found ? $connection['table_name'] : __( 'The selected table was not found. It may have been renamed or deleted.', 'music-club-registrations' ),
		);

		if ( ! $table_found ) {
			return array(
				'success' => false,
				'checks'  => $checks,
			);
		}

		// 5. Permissão de escrita: adiciona uma linha de teste e a remove
		// em seguida, confirmando a capacidade de escrita sem deixar
		// dados de teste na planilha do cliente.
		$columns = self::list_table_columns( $connection['drive_id'], $connection['item_id'], $connection['table_id'] );

		if ( is_wp_error( $columns ) ) {
			$checks[] = array(
				'label'  => __( 'Table structure readable', 'music-club-registrations' ),
				'ok'     => false,
				'detail' => $columns->get_error_message(),
			);

			return array(
				'success' => false,
				'checks'  => $checks,
			);
		}

		$test_row        = array_fill( 0, count( $columns ), '' );
		$test_row_result = self::add_table_row( $connection['drive_id'], $connection['item_id'], $connection['table_id'], $test_row );

		$write_ok = ! is_wp_error( $test_row_result );

		$checks[] = array(
			'label'  => __( 'Write permission confirmed', 'music-club-registrations' ),
			'ok'     => $write_ok,
			'detail' => $write_ok ? __( 'A test row was written and removed successfully.', 'music-club-registrations' ) : $test_row_result->get_error_message(),
		);

		if ( $write_ok && isset( $test_row_result['index'] ) ) {
			// Remove a linha de teste imediatamente, sem deixar rastro na
			// planilha do cliente.
			self::request(
				'DELETE',
				self::workbook_path( $connection['drive_id'], $connection['item_id'] )
					. '/tables/' . rawurlencode( $connection['table_id'] )
					. '/rows/' . absint( $test_row_result['index'] )
			);
		}

		$success = ! in_array( false, wp_list_pluck( $checks, 'ok' ), true );

		return array(
			'success' => $success,
			'checks'  => $checks,
		);
	}
}

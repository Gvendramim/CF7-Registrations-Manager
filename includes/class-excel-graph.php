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
	 * Atualiza uma linha JÁ EXISTENTE da tabela do Excel, pelo seu índice
	 * (posição), em vez de criar uma nova linha. Usado sempre que uma
	 * inscrição já sincronizada anteriormente é editada, para que a
	 * planilha reflita a correção em vez de acumular linhas duplicadas.
	 *
	 * O índice é o mesmo devolvido pela Microsoft Graph API no momento em
	 * que a linha foi originalmente criada (ver add_table_row()) e
	 * permanece estável desde que ninguém reordene ou apague linhas
	 * manualmente na planilha. Se a linha não existir mais nesse índice
	 * (ex: apagada manualmente no Excel), a chamada retorna um WP_Error
	 * com código "mcr_ms_not_found" - o chamador decide então se cria uma
	 * nova linha em vez de falhar silenciosamente.
	 *
	 * @param string $drive_id  ID do drive.
	 * @param string $item_id   ID do arquivo.
	 * @param string $table_id  ID (ou nome) da tabela.
	 * @param int    $row_index Índice (posição) da linha a atualizar.
	 * @param array  $values    Novos valores da linha, na mesma ordem das colunas da tabela.
	 * @return array|\WP_Error
	 */
	public static function update_table_row( $drive_id, $item_id, $table_id, $row_index, array $values ) {
		// IMPORTANTE: o recurso "rows/itemAt(index=N)" da Microsoft Graph
		// API está repetidamente confirmado como problemático em relatos
		// de outros desenvolvedores - seja retornando "ApiNotFound" ao
		// tentar PATCH direto, seja (como visto em produção aqui) falhando
		// já na simples leitura de "/range" encadeada a partir dele, com
		// "The argument is invalid or missing or has an incorrect
		// format.". Como esse comportamento não é confiável em nenhuma
		// das variações testadas, evitamos completamente o "itemAt" em
		// TODAS as operações (leitura, escrita e exclusão de linha).
		$location = self::locate_row_range( $drive_id, $item_id, $table_id, $row_index );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$update_path = self::workbook_path( $drive_id, $item_id )
			. '/worksheets/' . rawurlencode( $location['worksheet_name'] )
			. "/range(address='" . $location['cell_range'] . "')";

		$update_result = self::request(
			'PATCH',
			$update_path,
			array(
				'body' => array( 'values' => array( array_values( $values ) ) ),
			)
		);

		if ( is_wp_error( $update_result ) ) {
			return new \WP_Error(
				$update_result->get_error_code(),
				sprintf(
					'[Step 2/2 - writing to range "%1$s" (%2$d value(s) sent)] %3$s',
					$location['cell_range'],
					count( $values ),
					$update_result->get_error_message()
				)
			);
		}

		return $update_result;
	}

	/**
	 * Exclui uma linha de uma tabela do Excel pelo seu índice (posição),
	 * SEM usar o recurso "itemAt" (confirmado instável para exclusão, da
	 * mesma forma que já era para leitura e escrita). Em vez disso,
	 * localiza o endereço de célula exato da linha e usa a operação
	 * documentada de exclusão de intervalo, deslocando as células
	 * abaixo para cima - o mesmo efeito de remover a linha da tabela.
	 *
	 * Usada apenas internamente por test_connection(), para remover a
	 * linha de teste escrita durante a verificação de permissão de
	 * escrita, sem deixar rastro na planilha do cliente.
	 *
	 * @param string $drive_id  ID do drive.
	 * @param string $item_id   ID do arquivo.
	 * @param string $table_id  ID (ou nome) da tabela.
	 * @param int    $row_index Índice (posição) da linha a excluir.
	 * @return bool|\WP_Error True em caso de sucesso.
	 */
	public static function delete_table_row_by_index( $drive_id, $item_id, $table_id, $row_index ) {
		$location = self::locate_row_range( $drive_id, $item_id, $table_id, $row_index );

		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$delete_path = self::workbook_path( $drive_id, $item_id )
			. '/worksheets/' . rawurlencode( $location['worksheet_name'] )
			. "/range(address='" . $location['cell_range'] . "')/delete";

		$result = self::request(
			'POST',
			$delete_path,
			array(
				'body' => array( 'shift' => 'Up' ),
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Calcula o endereço de célula exato de uma linha de tabela, a partir
	 * do seu índice (posição) - sem depender do recurso "itemAt", que se
	 * mostrou instável na prática (tanto para leitura quanto escrita).
	 *
	 * Funciona perguntando à Microsoft o endereço da ÁREA DE DADOS da
	 * tabela inteira ("dataBodyRange" - endpoint básico e amplamente
	 * documentado, sem nenhuma relação com "itemAt"), e somando o índice
	 * da linha à primeira célula dessa área.
	 *
	 * @param string $drive_id  ID do drive.
	 * @param string $item_id   ID do arquivo.
	 * @param string $table_id  ID (ou nome) da tabela.
	 * @param int    $row_index Índice (posição) da linha, começando em zero.
	 * @return array{worksheet_name:string,cell_range:string}|\WP_Error
	 */
	private static function locate_row_range( $drive_id, $item_id, $table_id, $row_index ) {
		$data_body_range_path = self::workbook_path( $drive_id, $item_id )
			. '/tables/' . rawurlencode( $table_id )
			. '/dataBodyRange';

		$range_info = self::request( 'GET', $data_body_range_path );

		if ( is_wp_error( $range_info ) ) {
			return new \WP_Error(
				$range_info->get_error_code(),
				'[Step 1/2 - reading table data range] ' . $range_info->get_error_message()
			);
		}

		if ( empty( $range_info['address'] ) ) {
			return new \WP_Error( 'mcr_ms_not_found', __( '[Step 1/2] Could not determine the data range of this table (no "address" in the response).', 'music-club-registrations' ) );
		}

		// O endereço vem no formato "NomeDaAba!A2:F50" (ou "'Nome Da
		// Aba'!A2:F50" quando o nome da aba tem espaço) - separamos o
		// nome da aba do intervalo de células da área de dados.
		$address_parts = explode( '!', $range_info['address'], 2 );

		if ( 2 !== count( $address_parts ) ) {
			return new \WP_Error(
				'mcr_ms_graph_error',
				sprintf( '[Step 1/2] Unexpected data range format returned by Microsoft: "%s".', $range_info['address'] )
			);
		}

		$worksheet_name    = trim( $address_parts[0], "'" );
		$data_range_bounds = explode( ':', $address_parts[1], 2 );

		if ( 2 !== count( $data_range_bounds ) ) {
			return new \WP_Error(
				'mcr_ms_graph_error',
				sprintf( '[Step 1/2] Unexpected data range bounds returned by Microsoft: "%s".', $address_parts[1] )
			);
		}

		$start_cell = self::parse_cell_address( $data_range_bounds[0] );
		$end_cell   = self::parse_cell_address( $data_range_bounds[1] );

		if ( ! $start_cell || ! $end_cell ) {
			return new \WP_Error(
				'mcr_ms_graph_error',
				sprintf( '[Step 1/2] Could not parse the data range cells: "%s".', $address_parts[1] )
			);
		}

		// A linha física da tabela é a primeira linha de dados (a área de
		// dados já exclui o cabeçalho) somada ao índice da linha - ambos
		// contam a partir de zero.
		$target_row = $start_cell['row'] + absint( $row_index );

		return array(
			'worksheet_name' => $worksheet_name,
			'cell_range'     => $start_cell['column'] . $target_row . ':' . $end_cell['column'] . $target_row,
		);
	}

	/**
	 * Interpreta um endereço de célula no estilo Excel (ex: "AB12") em
	 * suas partes de coluna (letras) e linha (número).
	 *
	 * @param string $cell Endereço da célula (ex: "A2").
	 * @return array{column:string,row:int}|null
	 */
	private static function parse_cell_address( $cell ) {
		if ( ! preg_match( '/^([A-Za-z]+)(\d+)$/', trim( $cell ), $matches ) ) {
			return null;
		}

		return array(
			'column' => strtoupper( $matches[1] ),
			'row'    => (int) $matches[2],
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
	public static function test_connection( $target = Excel_OAuth::DEFAULT_TARGET ) {
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

		$connection = Excel_OAuth::get_target_connection( $target );

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
			// planilha do cliente. IMPORTANTE: nunca usa "itemAt" (ver
			// delete_table_row_by_index()) - uma versão anterior deste
			// código usava esse recurso instável para a exclusão e, ao
			// falhar silenciosamente (sem checar o resultado), deixava a
			// linha de teste esquecida na planilha a cada clique em "Test
			// Connection", corrompendo silenciosamente a correspondência
			// entre os índices salvos e as linhas físicas reais.
			$cleanup_result = self::delete_table_row_by_index( $connection['drive_id'], $connection['item_id'], $connection['table_id'], $test_row_result['index'] );

			if ( is_wp_error( $cleanup_result ) ) {
				// Não escondemos essa falha: se a linha de teste não pôde
				// ser removida, o administrador precisa saber e apagá-la
				// manualmente, em vez de ela ficar esquecida sem aviso.
				$checks[] = array(
					'label'  => __( 'Test row cleanup', 'music-club-registrations' ),
					'ok'     => false,
					'detail' => sprintf(
						/* translators: %s: raw error message from the cleanup attempt */
						__( 'The test row could not be automatically removed (%s). Please delete the last blank row of the table manually in Excel.', 'music-club-registrations' ),
						$cleanup_result->get_error_message()
					),
				);
			}
		}

		$success = ! in_array( false, wp_list_pluck( $checks, 'ok' ), true );

		return array(
			'success' => $success,
			'checks'  => $checks,
		);
	}
}

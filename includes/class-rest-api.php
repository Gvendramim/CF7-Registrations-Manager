<?php
/**
 * Classe responsável por expor a API REST protegida do plugin.
 *
 * A API é registrada automaticamente na inicialização do plugin através
 * de register_rest_route(), sem exigir nenhuma configuração manual após a
 * instalação. Uma chave de API é gerada automaticamente na ativação
 * (ver Settings::ensure_api_key()) e pode ser regenerada a qualquer
 * momento na tela de Settings.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class REST_API
 *
 * Responsabilidade única: expor endpoints REST seguros para integração
 * externa com os dados de inscrições, incluindo exportação direta em
 * CSV/Excel para ferramentas como Excel e Power BI.
 */
class REST_API {

	/**
	 * Namespace da API REST.
	 *
	 * @var string
	 */
	const NAMESPACE_NAME = 'cf7-registrations/v1';

	/**
	 * Cabeçalho HTTP utilizado para autenticação via chave de API.
	 *
	 * @var string
	 */
	const API_KEY_HEADER = 'X-MCR-API-Key';

	/**
	 * Limite de requisições permitidas por chave/IP dentro da janela de
	 * rate limit.
	 *
	 * @var int
	 */
	const RATE_LIMIT_MAX_REQUESTS = 60;

	/**
	 * Janela de tempo (em segundos) usada para o rate limit da API.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WINDOW = 60;

	/**
	 * Registra os hooks de inicialização da API REST.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registra as rotas da API REST. Executado automaticamente pelo
	 * WordPress durante a inicialização da REST API - nenhuma etapa manual
	 * é necessária após a instalação do plugin.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_NAME,
			'/registrations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_registrations' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'search'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'required'          => false,
					),
					'status'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
						'required'          => false,
					),
					'page'     => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'default'           => 20,
						'sanitize_callback' => 'absint',
					),
					'orderby'  => array(
						'type'              => 'string',
						'default'           => 'created_at',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'order'    => array(
						'type'              => 'string',
						'default'           => 'DESC',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			'/registration/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_registration' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_registration' ),
					'permission_callback' => array( $this, 'permission_check' ),
					'args'                => array(
						'id' => array(
							'validate_callback' => function ( $param ) {
								return is_numeric( $param );
							},
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			'/registration/status',
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_registration_status' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'id'     => array(
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					),
					'status' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => function ( $param ) {
							return mcr_is_valid_status( $param );
						},
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			'/export\.csv',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_csv' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_NAME,
			'/export\.xlsx',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_xlsx' ),
				'permission_callback' => array( $this, 'permission_check' ),
			)
		);
	}

	/**
	 * Verifica se a requisição possui permissão para acessar a API.
	 *
	 * Duas formas de autenticação são aceitas, para facilitar a integração
	 * com ferramentas como Excel e Power BI que não permitem cabeçalhos
	 * HTTP customizados:
	 * 1. Chave de API própria do plugin, enviada no cabeçalho
	 *    "X-MCR-API-Key" OU no parâmetro de URL "api_key".
	 * 2. Autenticação padrão do WordPress (usuário logado com a capability
	 *    necessária).
	 *
	 * Um limite de requisições (rate limit) é aplicado por chave de API/IP,
	 * para proteger o site contra uso abusivo.
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return bool|\WP_Error
	 */
	public function permission_check( \WP_REST_Request $request ) {
		if ( ! $this->has_valid_api_key( $request ) && ! ( is_user_logged_in() && mcr_current_user_can_manage() ) ) {
			Logger::warning( 'api', sprintf( 'Unauthorized REST API access attempt on %s.', $request->get_route() ) );

			return new \WP_Error(
				'mcr_rest_forbidden',
				__( 'Authentication is required to access this endpoint. Provide a valid API key or log in with sufficient permissions.', 'music-club-registrations' ),
				array( 'status' => 401 )
			);
		}

		$rate_limit_error = $this->check_rate_limit( $request );

		if ( is_wp_error( $rate_limit_error ) ) {
			return $rate_limit_error;
		}

		return true;
	}

	/**
	 * Aplica um limite simples de requisições por chave de API (ou IP,
	 * quando autenticado via sessão do WordPress), evitando uso abusivo
	 * da API REST.
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return true|\WP_Error
	 */
	private function check_rate_limit( \WP_REST_Request $request ) {
		$identifier = $this->get_rate_limit_identifier( $request );
		$key        = 'mcr_rate_' . md5( $identifier );

		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX_REQUESTS ) {
			Logger::warning( 'api', sprintf( 'Rate limit exceeded for %s.', $identifier ) );

			return new \WP_Error(
				'mcr_rate_limited',
				__( 'Too many requests. Please slow down and try again in a minute.', 'music-club-registrations' ),
				array( 'status' => 429 )
			);
		}

		if ( 0 === $count ) {
			set_transient( $key, 1, self::RATE_LIMIT_WINDOW );
		} else {
			set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
		}

		return true;
	}

	/**
	 * Resolve um identificador único para fins de rate limit: a própria
	 * chave de API quando presente, ou o endereço IP da requisição.
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return string
	 */
	private function get_rate_limit_identifier( \WP_REST_Request $request ) {
		$api_key = $request->get_header( self::API_KEY_HEADER );

		if ( empty( $api_key ) ) {
			$api_key = $request->get_param( 'api_key' );
		}

		if ( ! empty( $api_key ) ) {
			return 'key:' . $api_key;
		}

		return 'ip:' . ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- usado apenas como parte de uma chave de cache hash.
	}

	/**
	 * Verifica se a requisição contém uma chave de API válida.
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return bool
	 */
	private function has_valid_api_key( \WP_REST_Request $request ) {
		$provided_key = $request->get_header( self::API_KEY_HEADER );

		if ( empty( $provided_key ) ) {
			// Aceita também via parâmetro de query, para clientes que não
			// conseguem definir cabeçalhos customizados (Excel, Power BI).
			$provided_key = $request->get_param( 'api_key' );
		}

		if ( empty( $provided_key ) ) {
			return false;
		}

		$configured_key = Settings::get( 'api_key', '' );

		if ( empty( $configured_key ) ) {
			return false;
		}

		return hash_equals( $configured_key, (string) $provided_key );
	}

	/**
	 * GET /cf7-registrations/v1/registrations
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return \WP_REST_Response
	 */
	public function get_registrations( \WP_REST_Request $request ) {
		$result = Database::query_registrations(
			array(
				'search'   => $request->get_param( 'search' ),
				'status'   => $request->get_param( 'status' ),
				'orderby'  => $request->get_param( 'orderby' ),
				'order'    => $request->get_param( 'order' ),
				'per_page' => $request->get_param( 'per_page' ),
				'page'     => $request->get_param( 'page' ),
			)
		);

		$items = array_map( array( $this, 'prepare_registration_for_response' ), $result['items'] );

		return $this->success_response(
			array(
				'items'    => $items,
				'total'    => $result['total'],
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			)
		);
	}

	/**
	 * GET /cf7-registrations/v1/registration/{id}
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_registration( \WP_REST_Request $request ) {
		$id           = absint( $request->get_param( 'id' ) );
		$registration = Database::get_registration( $id );

		if ( ! $registration ) {
			return new \WP_Error(
				'mcr_not_found',
				__( 'Registration not found.', 'music-club-registrations' ),
				array( 'status' => 404 )
			);
		}

		$data            = $this->prepare_registration_for_response( $registration );
		$data['history'] = array_map(
			function ( $entry ) {
				return array(
					'field'      => $entry['field'],
					'old_value'  => $entry['old_value'],
					'new_value'  => $entry['new_value'],
					'changed_by' => (int) $entry['changed_by'],
					'changed_at' => $entry['changed_at'],
				);
			},
			Database::get_history( $id )
		);

		return $this->success_response( $data );
	}

	/**
	 * DELETE /cf7-registrations/v1/registration/{id}
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_registration( \WP_REST_Request $request ) {
		$id           = absint( $request->get_param( 'id' ) );
		$registration = Database::get_registration( $id );

		if ( ! $registration ) {
			return new \WP_Error(
				'mcr_not_found',
				__( 'Registration not found.', 'music-club-registrations' ),
				array( 'status' => 404 )
			);
		}

		$deleted = Database::delete_registration( $id );

		if ( ! $deleted ) {
			Logger::error( 'api', sprintf( 'Failed to delete registration #%d via REST API.', $id ) );

			return new \WP_Error(
				'mcr_delete_failed',
				__( 'Unable to delete the registration.', 'music-club-registrations' ),
				array( 'status' => 500 )
			);
		}

		Logger::info( 'api', sprintf( 'Registration #%d deleted via REST API.', $id ) );

		return $this->success_response( array( 'deleted' => true, 'id' => $id ) );
	}

	/**
	 * POST /cf7-registrations/v1/registration/status
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_registration_status( \WP_REST_Request $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$status = $request->get_param( 'status' );

		$registration = Database::get_registration( $id );

		if ( ! $registration ) {
			return new \WP_Error(
				'mcr_not_found',
				__( 'Registration not found.', 'music-club-registrations' ),
				array( 'status' => 404 )
			);
		}

		$updated = Database::update_status( $id, $status, get_current_user_id() );

		if ( ! $updated ) {
			Logger::error( 'api', sprintf( 'Failed to update status of registration #%d via REST API.', $id ) );

			return new \WP_Error(
				'mcr_update_failed',
				__( 'Unable to update the registration status.', 'music-club-registrations' ),
				array( 'status' => 500 )
			);
		}

		Logger::info( 'api', sprintf( 'Registration #%d status updated to "%s" via REST API.', $id, $status ) );

		$registration = Database::get_registration( $id );

		return $this->success_response( $this->prepare_registration_for_response( $registration ) );
	}

	/**
	 * GET /cf7-registrations/v1/export.csv
	 *
	 * Permite baixar diretamente um arquivo CSV com todas as inscrições,
	 * ideal para uso com Excel ou Power BI ("Obter Dados" a partir de uma
	 * URL da Web), sem precisar acessar o painel administrativo.
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return void
	 */
	public function export_csv( \WP_REST_Request $request ) {
		$ids   = Database::get_all_ids( array() );
		$items = Database::get_registrations_by_ids( $ids );

		$content = Export::build_csv_content( $items, Export::get_all_columns() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=registrations-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		header( 'Content-Length: ' . strlen( $content ) );

		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary/CSV file content, not HTML.

		Logger::info( 'api', sprintf( 'CSV export downloaded via REST API (%d records).', count( $items ) ) );

		exit;
	}

	/**
	 * GET /cf7-registrations/v1/export.xlsx
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return void|\WP_Error
	 */
	public function export_xlsx( \WP_REST_Request $request ) {
		if ( ! Xlsx_Writer::is_supported() ) {
			return new \WP_Error(
				'mcr_excel_unavailable',
				__( 'Excel export is not available on this server. Please use export.csv instead.', 'music-club-registrations' ),
				array( 'status' => 501 )
			);
		}

		$ids   = Database::get_all_ids( array() );
		$items = Database::get_registrations_by_ids( $ids );

		$tmp_file = Export::build_xlsx_file( $items, Export::get_all_columns() );

		if ( ! $tmp_file ) {
			return new \WP_Error(
				'mcr_export_failed',
				__( 'Something went wrong while generating the Excel file.', 'music-club-registrations' ),
				array( 'status' => 500 )
			);
		}

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename=registrations-' . gmdate( 'Y-m-d-His' ) . '.xlsx' );
		header( 'Content-Length: ' . filesize( $tmp_file ) );

		readfile( $tmp_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile -- streaming a temporary export file for direct download.
		wp_delete_file( $tmp_file );

		Logger::info( 'api', sprintf( 'Excel export downloaded via REST API (%d records).', count( $items ) ) );

		exit;
	}

	/**
	 * Formata um registro de inscrição para uma resposta REST limpa e
	 * consistente, sem expor colunas internas desnecessárias.
	 *
	 * @param array $registration Registro bruto do banco de dados.
	 * @return array
	 */
	private function prepare_registration_for_response( array $registration ) {
		return array(
			'id'                   => (int) $registration['id'],
			'registration_number' => $registration['registration_number'],
			'child_name'           => $registration['child_name'],
			'child_age'            => isset( $registration['child_age'] ) && '' !== $registration['child_age'] ? (int) $registration['child_age'] : null,
			'parent_name'          => $registration['parent_name'],
			'second_parent_name'   => $registration['second_parent_name'] ?? '',
			'parent_email'         => $registration['parent_email'],
			'phone'                => $registration['phone'],
			'second_parent_email'  => $registration['second_parent_email'] ?? '',
			'second_parent_phone'  => $registration['second_parent_phone'] ?? '',
			'child_class'          => $registration['child_class'],
			'interests'            => mcr_interests_to_array( $registration['interests'] ),
			'additional_message'   => $registration['additional_message'],
			'status'               => $registration['status'],
			'status_label'         => mcr_get_status_label( $registration['status'] ),
			'created_at'           => $this->mysql_to_rfc3339( $registration['created_at'] ),
		);
	}

	/**
	 * Monta uma resposta JSON padronizada de sucesso.
	 *
	 * @param mixed $data Dados a retornar.
	 * @return \WP_REST_Response
	 */
	private function success_response( $data ) {
		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * Converte uma data no formato MySQL para RFC3339, formato padrão da
	 * API REST do WordPress.
	 *
	 * @param string $mysql_date Data no formato MySQL.
	 * @return string
	 */
	private function mysql_to_rfc3339( $mysql_date ) {
		$timestamp = mysql2date( 'U', $mysql_date );

		return gmdate( 'Y-m-d\TH:i:s', (int) $timestamp );
	}
}

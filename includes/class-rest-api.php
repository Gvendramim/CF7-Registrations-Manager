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
 * externa com os dados de inscrições.
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
	}

	/**
	 * Verifica se a requisição possui permissão para acessar a API.
	 *
	 * Duas formas de autenticação são aceitas:
	 * 1. Chave de API própria do plugin, enviada no cabeçalho
	 *    "X-MCR-API-Key" (gerada automaticamente e configurável em Settings).
	 * 2. Autenticação padrão do WordPress (usuário logado com a capability
	 *    necessária), útil para chamadas feitas a partir do próprio admin.
	 *
	 * Nenhum endpoint é acessível anonimamente sem uma credencial válida.
	 *
	 * @param \WP_REST_Request $request Requisição REST.
	 * @return bool|\WP_Error
	 */
	public function permission_check( \WP_REST_Request $request ) {
		if ( $this->has_valid_api_key( $request ) ) {
			return true;
		}

		if ( is_user_logged_in() && mcr_current_user_can_manage() ) {
			return true;
		}

		Logger::warning( 'api', sprintf( 'Unauthorized REST API access attempt on %s.', $request->get_route() ) );

		return new \WP_Error(
			'mcr_rest_forbidden',
			__( 'Authentication is required to access this endpoint. Provide a valid API key or log in with sufficient permissions.', 'music-club-registrations' ),
			array( 'status' => 401 )
		);
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
			// conseguem definir cabeçalhos customizados.
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
			'parent_name'          => $registration['parent_name'],
			'parent_email'         => $registration['parent_email'],
			'phone'                => $registration['phone'],
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

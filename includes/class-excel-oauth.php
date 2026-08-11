<?php
/**
 * Gerencia a autenticação OAuth 2.0 (Authorization Code Flow) com a
 * Microsoft, incluindo o fluxo completo de login, armazenamento seguro
 * dos tokens, renovação automática e desconexão.
 *
 * O cliente final nunca vê nem precisa informar Tenant ID, Client ID,
 * Client Secret, tokens ou qualquer identificador técnico - ele apenas
 * clica em "Connect Microsoft 365", faz login e autoriza o aplicativo.
 *
 * As credenciais do aplicativo Microsoft (Client ID/Secret, criadas uma
 * única vez pelo desenvolvedor no Microsoft Entra) ficam em
 * Settings::get('excel_app'), gerenciadas na tela avançada
 * "Settings > Advanced > Microsoft Integration". O estado da conexão
 * (tokens, conta, seleção de workbook/worksheet/table) fica em uma opção
 * própria (`mcr_excel_connection`), separada das demais configurações,
 * para que "Disconnect" possa limpar apenas os dados sensíveis sem
 * afetar o restante do plugin.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Excel_OAuth
 *
 * Responsabilidade única: autenticação OAuth 2.0 com a Microsoft e
 * persistência do estado da conexão.
 */
class Excel_OAuth {

	/**
	 * Nome da opção que guarda o estado da conexão (tokens, conta,
	 * seleção de workbook/worksheet/table e mapeamento de campos).
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcr_excel_connection';

	/**
	 * Nome da transient usada para validar o parâmetro `state` do OAuth
	 * (proteção CSRF), única por usuário.
	 *
	 * @var string
	 */
	const STATE_TRANSIENT_PREFIX = 'mcr_ms_oauth_state_';

	/**
	 * Escopos solicitados à Microsoft Graph API. `offline_access` é
	 * obrigatório para recebermos um refresh_token; os demais permitem
	 * identificar a conta conectada e ler/escrever arquivos do OneDrive/
	 * SharePoint (necessário para localizar e atualizar o workbook).
	 *
	 * @var string
	 */
	const SCOPES = 'openid profile email offline_access User.Read Files.ReadWrite Files.ReadWrite.All';

	/**
	 * Registra os hooks do fluxo OAuth (início, callback e desconexão).
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_post_mcr_ms_oauth_start', array( $this, 'handle_oauth_start' ) );
		add_action( 'admin_post_mcr_ms_oauth_callback', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_post_mcr_ms_oauth_disconnect', array( $this, 'handle_disconnect' ) );
	}

	/**
	 * Retorna o estado completo da conexão, já mesclado com os valores
	 * padrão.
	 *
	 * @return array
	 */
	public static function get_connection() {
		$defaults = array(
			'connected'         => false,
			'account_email'     => '',
			'account_name'      => '',
			'access_token'      => '',
			'refresh_token'     => '',
			'token_expires_at'  => 0,
			'drive_id'          => '',
			'item_id'           => '',
			'workbook_name'     => '',
			'worksheet_name'    => '',
			'table_id'          => '',
			'table_name'        => '',
			'table_columns'     => array(),
			'field_mapping'     => array(),
			'auto_sync_enabled' => true,
			'connected_at'      => 0,
		);

		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Atualiza (mescla) o estado da conexão.
	 *
	 * @param array $data Campos a atualizar.
	 * @return array Estado completo após a atualização.
	 */
	public static function update_connection( array $data ) {
		$connection = wp_parse_args( $data, self::get_connection() );

		update_option( self::OPTION_NAME, $connection );

		return $connection;
	}

	/**
	 * Verifica se há uma conta Microsoft conectada.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$connection = self::get_connection();

		return ! empty( $connection['connected'] ) && ! empty( $connection['refresh_token'] );
	}

	/**
	 * Verifica se a seleção de workbook/worksheet/table foi concluída.
	 *
	 * @return bool
	 */
	public static function is_fully_configured() {
		$connection = self::get_connection();

		return self::is_connected()
			&& ! empty( $connection['drive_id'] )
			&& ! empty( $connection['item_id'] )
			&& ! empty( $connection['worksheet_name'] )
			&& ! empty( $connection['table_id'] );
	}

	/**
	 * Retorna a URL de callback (Redirect URI) que deve ser cadastrada no
	 * registro do aplicativo no Microsoft Entra. É gerada dinamicamente a
	 * partir da instalação atual do WordPress - nunca fixa no código,
	 * garantindo que o plugin funcione em qualquer domínio.
	 *
	 * @return string
	 */
	public static function get_redirect_uri() {
		return admin_url( 'admin-post.php?action=mcr_ms_oauth_callback' );
	}

	/**
	 * Inicia o fluxo OAuth: gera o parâmetro `state` (proteção CSRF) e
	 * redireciona o administrador para a tela de login/autorização da
	 * Microsoft.
	 *
	 * @return void
	 */
	public function handle_oauth_start() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		check_admin_referer( 'mcr_ms_oauth_start' );

		if ( ! Settings::is_excel_app_configured() ) {
			wp_die(
				esc_html__( 'Microsoft integration has not been configured yet. Please ask your site administrator to complete the one-time setup under Settings > Advanced > Microsoft Integration.', 'music-club-registrations' ),
				esc_html__( 'Not configured', 'music-club-registrations' ),
				array( 'back_link' => true )
			);
		}

		$state = wp_generate_password( 32, false );
		set_transient( self::STATE_TRANSIENT_PREFIX . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );

		$app = Settings::get( 'excel_app' );

		$authorize_url = add_query_arg(
			array(
				'client_id'     => rawurlencode( $app['client_id'] ),
				'response_type' => 'code',
				'redirect_uri'  => rawurlencode( self::get_redirect_uri() ),
				'response_mode' => 'query',
				'scope'         => rawurlencode( self::SCOPES ),
				'state'         => rawurlencode( $state ),
				'prompt'        => 'select_account',
			),
			sprintf( 'https://login.microsoftonline.com/%s/oauth2/v2.0/authorize', rawurlencode( $app['tenant'] ) )
		);

		Logger::info( 'excel_online', 'Microsoft OAuth flow started.', get_current_user_id() );

		wp_redirect( $authorize_url ); // phpcs:ignore WordPress.Security.SafeRedirect -- destino é a Microsoft, fora do site; wp_safe_redirect bloquearia por domínio externo.
		exit;
	}

	/**
	 * Processa o retorno da Microsoft após o login/autorização: valida o
	 * `state`, troca o `code` por tokens de acesso e atualiza, e busca os
	 * dados básicos da conta conectada.
	 *
	 * @return void
	 */
	public function handle_oauth_callback() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		// O usuário cancelou o login ou negou a autorização.
		if ( isset( $_GET['error'] ) ) {
			$description = isset( $_GET['error_description'] ) ? sanitize_text_field( wp_unslash( $_GET['error_description'] ) ) : '';

			Logger::warning( 'excel_online', 'Microsoft OAuth authorization was cancelled or denied: ' . sanitize_text_field( wp_unslash( $_GET['error'] ) ) );

			$this->redirect_to_excel_tab( array( 'ms_error' => 'authorization_denied' ) );
		}

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';

		$expected_state = get_transient( self::STATE_TRANSIENT_PREFIX . get_current_user_id() );
		delete_transient( self::STATE_TRANSIENT_PREFIX . get_current_user_id() );

		if ( empty( $code ) || empty( $state ) || ! $expected_state || ! hash_equals( (string) $expected_state, $state ) ) {
			Logger::error( 'excel_online', 'Microsoft OAuth callback failed state validation (possible CSRF attempt or expired session).' );

			$this->redirect_to_excel_tab( array( 'ms_error' => 'invalid_state' ) );
		}

		$token_result = $this->exchange_code_for_tokens( $code );

		if ( is_wp_error( $token_result ) ) {
			Logger::error( 'excel_online', 'Microsoft OAuth token exchange failed: ' . $token_result->get_error_message() );

			$this->redirect_to_excel_tab( array( 'ms_error' => 'token_exchange_failed' ) );
		}

		$account = $this->fetch_account_info( $token_result['access_token'] );

		self::update_connection(
			array(
				'connected'        => true,
				'account_email'    => $account['email'] ?? '',
				'account_name'     => $account['name'] ?? '',
				'access_token'     => $token_result['access_token'],
				'refresh_token'    => $token_result['refresh_token'],
				'token_expires_at' => time() + absint( $token_result['expires_in'] ),
				'connected_at'     => time(),
			)
		);

		Logger::info( 'excel_online', 'Microsoft account connected successfully.', get_current_user_id() );

		$this->redirect_to_excel_tab( array( 'ms_connected' => 1 ) );
	}

	/**
	 * Troca o `authorization code` recebido da Microsoft por um par de
	 * tokens (access_token + refresh_token).
	 *
	 * @param string $code Código de autorização.
	 * @return array{access_token:string,refresh_token:string,expires_in:int}|\WP_Error
	 */
	private function exchange_code_for_tokens( $code ) {
		$app = Settings::get( 'excel_app' );

		$response = wp_remote_post(
			sprintf( 'https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode( $app['tenant'] ) ),
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $app['client_id'],
					'client_secret' => $app['client_secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => self::get_redirect_uri(),
					'scope'         => self::SCOPES,
				),
			)
		);

		return $this->parse_token_response( $response );
	}

	/**
	 * Renova o access_token usando o refresh_token armazenado. Chamado
	 * automaticamente sempre que o token de acesso está expirado (ou
	 * prestes a expirar) antes de qualquer chamada à Microsoft Graph API.
	 *
	 * @return string|\WP_Error Novo access_token, ou WP_Error em caso de falha (ex: autorização revogada).
	 */
	public static function get_valid_access_token() {
		$connection = self::get_connection();

		if ( ! self::is_connected() ) {
			return new \WP_Error( 'mcr_ms_not_connected', __( 'No Microsoft account is connected.', 'music-club-registrations' ) );
		}

		// Ainda válido por pelo menos 2 minutos - reutiliza sem chamar a Microsoft.
		if ( $connection['token_expires_at'] > ( time() + 120 ) ) {
			return $connection['access_token'];
		}

		$app = Settings::get( 'excel_app' );

		$response = wp_remote_post(
			sprintf( 'https://login.microsoftonline.com/%s/oauth2/v2.0/token', rawurlencode( $app['tenant'] ) ),
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $app['client_id'],
					'client_secret' => $app['client_secret'],
					'grant_type'    => 'refresh_token',
					'refresh_token' => $connection['refresh_token'],
					'scope'         => self::SCOPES,
				),
			)
		);

		$instance = new self();
		$result   = $instance->parse_token_response( $response );

		if ( is_wp_error( $result ) ) {
			// O refresh_token pode ter sido revogado (usuário removeu o
			// acesso do app na conta Microsoft, ou trocou a senha). Nesse
			// caso, marcamos a conexão como desconectada para que a tela
			// oriente o administrador a reconectar - nunca deixamos o
			// plugin "preso" tentando repetidamente um token morto.
			if ( 'mcr_ms_token_invalid_grant' === $result->get_error_code() ) {
				self::update_connection(
					array(
						'connected'     => false,
						'access_token'  => '',
						'refresh_token' => '',
					)
				);

				Logger::critical( 'excel_online', 'Microsoft authorization appears to have been revoked. The connection was reset; please reconnect.' );
			} else {
				Logger::error( 'excel_online', 'Failed to refresh Microsoft access token: ' . $result->get_error_message() );
			}

			return $result;
		}

		self::update_connection(
			array(
				'access_token'     => $result['access_token'],
				// A Microsoft às vezes retorna um novo refresh_token junto
				// com o access_token renovado; se não vier, mantemos o atual.
				'refresh_token'    => ! empty( $result['refresh_token'] ) ? $result['refresh_token'] : $connection['refresh_token'],
				'token_expires_at' => time() + absint( $result['expires_in'] ),
			)
		);

		Logger::info( 'excel_online', 'Microsoft access token refreshed automatically.' );

		return $result['access_token'];
	}

	/**
	 * Interpreta a resposta HTTP de um endpoint de token da Microsoft,
	 * validando erros comuns sem nunca expor o conteúdo do token nos logs.
	 *
	 * @param array|\WP_Error $response Resposta de wp_remote_post().
	 * @return array{access_token:string,refresh_token:string,expires_in:int}|\WP_Error
	 */
	private function parse_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$error_code    = $body['error'] ?? 'unknown_error';
			$error_message = $body['error_description'] ?? sprintf( 'HTTP %d', $code );

			// invalid_grant normalmente significa refresh_token expirado ou revogado.
			$wp_error_code = 'invalid_grant' === $error_code ? 'mcr_ms_token_invalid_grant' : 'mcr_ms_token_error';

			return new \WP_Error( $wp_error_code, $error_message );
		}

		return array(
			'access_token'  => $body['access_token'],
			'refresh_token' => $body['refresh_token'] ?? '',
			'expires_in'    => $body['expires_in'] ?? 3600,
		);
	}

	/**
	 * Busca o nome e e-mail da conta Microsoft conectada, apenas para
	 * exibição amigável na tela (nunca usado para autenticação em si).
	 *
	 * @param string $access_token Token de acesso válido.
	 * @return array{email:string,name:string}
	 */
	private function fetch_account_info( $access_token ) {
		$response = wp_remote_get(
			'https://graph.microsoft.com/v1.0/me',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'email' => '',
				'name'  => '',
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'email' => $body['mail'] ?? ( $body['userPrincipalName'] ?? '' ),
			'name'  => $body['displayName'] ?? '',
		);
	}

	/**
	 * Processa a desconexão da conta Microsoft: apaga os tokens
	 * armazenados, mas preserva os registros do WordPress e o histórico
	 * de sincronização já realizado.
	 *
	 * @return void
	 */
	public function handle_disconnect() {
		if ( ! mcr_current_user_can_manage() ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'music-club-registrations' ) );
		}

		check_admin_referer( 'mcr_ms_oauth_disconnect' );

		delete_option( self::OPTION_NAME );

		Logger::info( 'excel_online', 'Microsoft account disconnected.', get_current_user_id() );

		$this->redirect_to_excel_tab( array( 'ms_disconnected' => 1 ) );
	}

	/**
	 * Redireciona de volta para a aba "Excel Online" da tela de
	 * configurações, opcionalmente com parâmetros extras de status.
	 *
	 * @param array $extra_args Parâmetros de query adicionais.
	 * @return void
	 */
	private function redirect_to_excel_tab( array $extra_args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'page' => Admin::SETTINGS_SLUG,
					'tab'  => 'excel',
				),
				$extra_args
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}
}

<?php
/**
 * Classe responsável por toda a configuração dinâmica do plugin: qual
 * formulário do Contact Form 7 é monitorado, o mapeamento de campos, as
 * regras de duplicidade, a chave de API e demais opções.
 *
 * Nenhuma dessas informações fica fixa no código - tudo é armazenado em
 * uma única opção do WordPress (`mcr_settings`) e gerenciado através da
 * tela de administração "Settings".
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * Responsabilidade única: leitura, gravação e valores padrão das
 * configurações do plugin.
 */
class Settings {

	/**
	 * Nome da opção usada para armazenar todas as configurações.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcr_settings';

	/**
	 * Retorna os slots de campo que o plugin sabe processar. Cada slot é
	 * mapeado, pelo administrador, para o "name" de um campo real do
	 * formulário do Contact Form 7 selecionado.
	 *
	 * @return array<string,string> Mapa slug => rótulo.
	 */
	public static function get_field_slots() {
		return array(
			'child_name'          => __( 'Student Name', 'music-club-registrations' ),
			'child_age'           => __( "Child's Age", 'music-club-registrations' ),
			'parent_name'         => __( 'Parent/Guardian Name', 'music-club-registrations' ),
			'second_parent_name'  => __( 'Parent/Guardian Name (Additional)', 'music-club-registrations' ),
			'parent_email'        => __( 'Primary Email', 'music-club-registrations' ),
			'phone'               => __( 'Primary Phone', 'music-club-registrations' ),
			'second_parent_email' => __( 'Additional Email', 'music-club-registrations' ),
			'second_parent_phone' => __( 'Additional Phone', 'music-club-registrations' ),
			'child_class'         => __( 'Class', 'music-club-registrations' ),
			'interests'           => __( 'Program', 'music-club-registrations' ),
			'additional_message'  => __( 'Message', 'music-club-registrations' ),
		);
	}

	/**
	 * Retorna os valores padrão de todas as configurações do plugin.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'form_id'                  => 0,
			'field_map'                => array(
				'child_name'          => '',
				'child_age'           => '',
				'parent_name'         => '',
				'second_parent_name'  => '',
				'parent_email'        => '',
				'phone'               => '',
				'second_parent_email' => '',
				'second_parent_phone' => '',
				'child_class'         => '',
				'interests'           => '',
				'additional_message'  => '',
			),
			'default_status'           => 'new',
			'duplicate_check_enabled'  => true,
			'duplicate_window_minutes' => 5,
			'api_key'                  => '',
			'remove_data_on_uninstall' => false,
			'enable_excel_export'      => true,
			'excel_app'                => array(
				'client_id'     => '',
				'client_secret' => '',
				// 'common' permite login com contas de qualquer organização
				// Microsoft 365 ou conta pessoal (multi-tenant), sem exigir
				// que o desenvolvedor conheça o Tenant ID do cliente final.
				'tenant'        => 'common',
			),
		);
	}

	/**
	 * Retorna todas as configurações do plugin, já mescladas com os
	 * valores padrão (para o caso de novas opções adicionadas em updates
	 * futuros).
	 *
	 * @return array
	 */
	public static function get_all() {
		$saved = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, self::get_defaults() );

		// Garante que o field_map sempre contenha todos os slots conhecidos,
		// mesmo que a instalação tenha sido feita com uma versão anterior.
		$settings['field_map'] = wp_parse_args(
			is_array( $settings['field_map'] ) ? $settings['field_map'] : array(),
			self::get_defaults()['field_map']
		);

		// Idem para as configurações avançadas do app Microsoft (Advanced >
		// Microsoft Integration), adicionadas em uma versão posterior.
		$settings['excel_app'] = wp_parse_args(
			is_array( $settings['excel_app'] ) ? $settings['excel_app'] : array(),
			self::get_defaults()['excel_app']
		);

		return $settings;
	}

	/**
	 * Retorna um valor específico de configuração.
	 *
	 * @param string $key     Chave da configuração.
	 * @param mixed  $default Valor padrão caso a chave não exista.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$settings = self::get_all();

		return $settings[ $key ] ?? $default;
	}

	/**
	 * Retorna o ID do formulário do Contact Form 7 atualmente monitorado.
	 *
	 * @return int 0 caso nenhum formulário tenha sido configurado ainda.
	 */
	public static function get_monitored_form_id() {
		return absint( self::get( 'form_id', 0 ) );
	}

	/**
	 * Retorna o mapeamento de campos configurado (slot => nome do campo CF7).
	 *
	 * @return array<string,string>
	 */
	public static function get_field_map() {
		return self::get( 'field_map', self::get_defaults()['field_map'] );
	}

	/**
	 * Grava as configurações do plugin, sanitizando cada valor conforme
	 * seu tipo.
	 *
	 * @param array $input Dados brutos vindos do formulário de configurações.
	 * @return array Configurações efetivamente salvas.
	 */
	public static function save( array $input ) {
		$defaults = self::get_defaults();

		$settings = array(
			'form_id'                  => isset( $input['form_id'] ) ? absint( $input['form_id'] ) : $defaults['form_id'],
			'field_map'                => array(),
			'default_status'           => isset( $input['default_status'] ) && mcr_is_valid_status( $input['default_status'] )
				? $input['default_status']
				: $defaults['default_status'],
			'duplicate_check_enabled'  => ! empty( $input['duplicate_check_enabled'] ),
			'duplicate_window_minutes' => isset( $input['duplicate_window_minutes'] ) ? max( 0, absint( $input['duplicate_window_minutes'] ) ) : $defaults['duplicate_window_minutes'],
			'api_key'                  => isset( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : self::get( 'api_key', '' ),
			'remove_data_on_uninstall' => ! empty( $input['remove_data_on_uninstall'] ),
			'enable_excel_export'      => ! empty( $input['enable_excel_export'] ),
			'excel_app'                => self::get( 'excel_app', $defaults['excel_app'] ),
		);

		foreach ( array_keys( $defaults['field_map'] ) as $slot ) {
			$settings['field_map'][ $slot ] = isset( $input['field_map'][ $slot ] )
				? sanitize_text_field( $input['field_map'][ $slot ] )
				: '';
		}

		// As credenciais do app Microsoft (Advanced > Microsoft
		// Integration) são enviadas por um formulário separado, visível
		// apenas ao desenvolvedor/administrador avançado. Quando
		// presentes, substituem os valores salvos anteriormente.
		if ( isset( $input['excel_app'] ) && is_array( $input['excel_app'] ) ) {
			$raw = $input['excel_app'];

			$current_secret = self::get( 'excel_app', $defaults['excel_app'] )['client_secret'];

			$settings['excel_app'] = array(
				'client_id'     => isset( $raw['client_id'] ) ? sanitize_text_field( $raw['client_id'] ) : '',
				'client_secret' => isset( $raw['client_secret'] ) && '' !== $raw['client_secret']
					? sanitize_text_field( $raw['client_secret'] )
					: $current_secret,
				'tenant'        => isset( $raw['tenant'] ) && '' !== trim( $raw['tenant'] )
					? sanitize_text_field( $raw['tenant'] )
					: 'common',
			);
		}

		if ( empty( $settings['api_key'] ) ) {
			$settings['api_key'] = self::generate_api_key();
		}

		update_option( self::OPTION_NAME, $settings );

		// Mantém a opção legada em sincronia, usada pelo uninstall.php.
		update_option( 'mcr_remove_data_on_uninstall', $settings['remove_data_on_uninstall'] ? '1' : '0' );

		return $settings;
	}

	/**
	 * Gera uma nova chave de API aleatória e segura.
	 *
	 * @return string
	 */
	public static function generate_api_key() {
		return wp_generate_password( 40, false, false );
	}

	/**
	 * Garante que uma chave de API exista, gerando uma automaticamente na
	 * primeira ativação do plugin, sem exigir configuração manual.
	 *
	 * @return string A chave de API atual.
	 */
	public static function ensure_api_key() {
		$settings = self::get_all();

		if ( ! empty( $settings['api_key'] ) ) {
			return $settings['api_key'];
		}

		$settings['api_key'] = self::generate_api_key();
		update_option( self::OPTION_NAME, $settings );

		return $settings['api_key'];
	}

	/**
	 * Verifica se as credenciais do app Microsoft (Advanced > Microsoft
	 * Integration) já foram configuradas pelo desenvolvedor/administrador
	 * avançado - pré-requisito único e feito uma única vez antes que
	 * clientes finais possam clicar em "Connect Microsoft 365".
	 *
	 * @return bool
	 */
	public static function is_excel_app_configured() {
		$app = self::get( 'excel_app', array() );

		return ! empty( $app['client_id'] ) && ! empty( $app['client_secret'] );
	}

	/**
	 * Retorna a lista de formulários do Contact Form 7 disponíveis no site,
	 * para popular o seletor da tela de configurações.
	 *
	 * @return array<int,array{id:int,title:string}>
	 */
	public static function get_available_forms() {
		if ( ! class_exists( '\WPCF7_ContactForm' ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'      => 'wpcf7_contact_form',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post_status'    => 'publish',
			)
		);

		$forms = array();

		foreach ( $posts as $post ) {
			$forms[] = array(
				'id'    => $post->ID,
				'title' => $post->post_title,
			);
		}

		return $forms;
	}

	/**
	 * Retorna os "form-tags" (campos) do formulário atualmente configurado,
	 * para popular os seletores da tela de mapeamento de campos.
	 *
	 * @param int $form_id ID do formulário do CF7. Se omitido, usa o
	 *                      formulário atualmente monitorado.
	 * @return array<int,string> Lista de nomes de campos disponíveis.
	 */
	public static function get_form_field_names( $form_id = 0 ) {
		if ( ! class_exists( '\WPCF7_ContactForm' ) ) {
			return array();
		}

		$form_id = $form_id ? absint( $form_id ) : self::get_monitored_form_id();

		if ( ! $form_id ) {
			return array();
		}

		// Protegido com try/catch: uma incompatibilidade de versão do
		// Contact Form 7 (ou um formulário corrompido) nunca deve derrubar
		// a tela de configurações inteira com um erro fatal - nesse caso,
		// simplesmente não há campos para pré-selecionar.
		try {
			$contact_form = \WPCF7_ContactForm::get_instance( $form_id );

			if ( ! $contact_form ) {
				return array();
			}

			$tags  = $contact_form->scan_form_tags();
			$names = array();

			foreach ( $tags as $tag ) {
				if ( ! empty( $tag->name ) ) {
					$names[ $tag->name ] = $tag->name;
				}
			}

			return array_values( $names );
		} catch ( \Throwable $exception ) {
			Logger::error( 'form', 'Failed to read Contact Form 7 fields: ' . $exception->getMessage() );

			return array();
		}
	}
}

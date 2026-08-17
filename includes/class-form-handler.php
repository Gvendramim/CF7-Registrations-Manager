<?php
/**
 * Classe responsável por capturar os envios do Contact Form 7 e persistir
 * os dados da inscrição, apenas quando o formulário enviado for o
 * formulário atualmente configurado na tela de Settings do plugin.
 *
 * Nenhum ID de formulário ou nome de campo fica fixo no código: tudo é
 * lido dinamicamente através da classe Settings, permitindo reutilizar o
 * plugin com qualquer formulário do Contact Form 7, em qualquer projeto,
 * sem alterar uma única linha de código.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Form_Handler
 *
 * Responsabilidade única: interceptar o envio do CF7, validar se é o
 * formulário configurado e converter os dados em uma inscrição persistida,
 * de acordo com o mapeamento de campos definido pelo administrador.
 */
class Form_Handler {

	/**
	 * Registra os hooks do Contact Form 7 utilizados por esta classe.
	 *
	 * @return void
	 */
	public function register_hooks() {
		// wpcf7_mail_sent é disparado apenas quando o e-mail do formulário
		// foi enviado com sucesso, ou seja, após toda a validação nativa do
		// CF7 já ter sido concluída. Isso evita persistir envios inválidos.
		add_action( 'wpcf7_mail_sent', array( $this, 'handle_submission' ) );
	}

	/**
	 * Manipula o envio de um formulário do Contact Form 7.
	 *
	 * Interrompe imediatamente o processamento caso:
	 * - Nenhum formulário tenha sido configurado ainda na tela de Settings; ou
	 * - O formulário enviado não seja o formulário atualmente configurado.
	 *
	 * Isso garante que nenhum outro formulário do site seja afetado por
	 * este plugin, e que o plugin funcione com qualquer formulário que o
	 * administrador escolher monitorar.
	 *
	 * @param \WPCF7_ContactForm $contact_form Instância do formulário enviado.
	 * @return void
	 */
	public function handle_submission( $contact_form ) {
		if ( ! $contact_form instanceof \WPCF7_ContactForm ) {
			return;
		}

		$monitored_form_id = Settings::get_monitored_form_id();

		// Nenhum formulário configurado ainda: o plugin permanece inerte até
		// que o administrador selecione um formulário na tela de Settings.
		if ( ! $monitored_form_id ) {
			return;
		}

		// Apenas o formulário configurado é processado. Qualquer outro ID
		// interrompe imediatamente a execução, sem efeitos colaterais para
		// os demais formulários do site.
		if ( (int) $contact_form->id() !== $monitored_form_id ) {
			return;
		}

		$submission = \WPCF7_Submission::get_instance();

		if ( ! $submission ) {
			return;
		}

		$posted_data = $submission->get_posted_data();

		if ( empty( $posted_data ) || ! is_array( $posted_data ) ) {
			return;
		}

		try {
			$this->process_registration( $posted_data );
		} catch ( \Throwable $exception ) {
			Logger::error( 'form', 'Unexpected error while processing form submission: ' . $exception->getMessage() );
		}
	}

	/**
	 * Sanitiza, valida e persiste os dados de uma inscrição, de acordo com
	 * o mapeamento de campos configurado.
	 *
	 * @param array $posted_data Dados brutos enviados pelo formulário.
	 * @return void
	 */
	private function process_registration( array $posted_data ) {
		$sanitized = $this->sanitize_posted_data( $posted_data );

		if ( ! $this->validate_required_fields( $sanitized ) ) {
			// Campos obrigatórios ausentes (nome, responsável ou e-mail).
			// O CF7 já valida o preenchimento no front-end/servidor, mas
			// mantemos esta checagem como uma segunda camada de segurança,
			// especialmente relevante quando o mapeamento de campos foi
			// configurado incorretamente.
			Logger::warning( 'form', 'Submission ignored: required fields are missing after field mapping.' );
			return;
		}

		if ( Settings::get( 'duplicate_check_enabled', true ) &&
			Database::has_recent_duplicate( $sanitized['parent_email'], $sanitized['child_name'] )
		) {
			Logger::info( 'form', sprintf( 'Duplicate submission ignored for %s.', $sanitized['parent_email'] ) );
			return;
		}

		$result = Database::insert_registration( $sanitized );

		if ( is_wp_error( $result ) ) {
			Logger::error( 'form', 'Failed to persist registration: ' . $result->get_error_message() );
			return;
		}

		/**
		 * Disparado após uma nova inscrição ser gravada com sucesso.
		 *
		 * @param int   $registration_id ID da inscrição criada.
		 * @param array $sanitized       Dados sanitizados da inscrição.
		 */
		do_action( 'mcr_registration_created', $result, $sanitized );
	}

	/**
	 * Sanitiza todos os campos recebidos do formulário, resolvendo o nome
	 * real de cada campo através do mapeamento configurado em Settings.
	 *
	 * Nunca confia nos dados brutos vindos do envio - cada campo passa
	 * pela função de sanitização apropriada ao seu tipo.
	 *
	 * @param array $posted_data Dados brutos do CF7.
	 * @return array Dados sanitizados, prontos para persistência.
	 */
	private function sanitize_posted_data( array $posted_data ) {
		$map = Settings::get_field_map();

		$child_name          = $this->extract_text_field( $posted_data, $map['child_name'] );
		$child_age            = $this->extract_age_field( $posted_data, $map['child_age'] );
		$parent_name         = $this->extract_text_field( $posted_data, $map['parent_name'] );
		$second_parent_name  = $this->extract_text_field( $posted_data, $map['second_parent_name'] );
		$parent_email        = $this->extract_email_field( $posted_data, $map['parent_email'] );
		$phone               = $this->extract_phone_field( $posted_data, $map['phone'] );
		$second_parent_email = $this->extract_email_field( $posted_data, $map['second_parent_email'] );
		$second_parent_phone = $this->extract_phone_field( $posted_data, $map['second_parent_phone'] );
		$child_class         = $this->extract_text_field( $posted_data, $map['child_class'] );
		$interests           = $this->extract_multi_field( $posted_data, $map['interests'] );
		$total_amount        = $this->extract_text_field( $posted_data, $map['total_amount'] );
		$additional_message  = $this->extract_textarea_field( $posted_data, $map['additional_message'] );

		return array(
			'child_name'          => $child_name,
			'child_age'           => $child_age,
			'parent_name'         => $parent_name,
			'second_parent_name'  => $second_parent_name,
			'parent_email'        => $parent_email,
			'phone'               => $phone,
			'second_parent_email' => $second_parent_email,
			'second_parent_phone' => $second_parent_phone,
			'child_class'         => $child_class,
			'interests'           => $interests,
			'total_amount'        => $total_amount,
			'additional_message'  => $additional_message,
			'status'              => Settings::get( 'default_status', 'new' ),
			'internal_notes'      => '',
		);
	}

	/**
	 * Extrai e valida um campo de idade a partir do nome de campo mapeado.
	 *
	 * Aceita apenas números inteiros entre 3 e 13 (faixa etária do
	 * formulário). Qualquer valor ausente, vazio, não numérico ou fora da
	 * faixa é tratado como "não informado" (null), nunca gera erro fatal
	 * e nunca impede o restante da inscrição de ser salva.
	 *
	 * @param array  $posted_data Dados brutos do CF7.
	 * @param string $field_name  Nome do campo mapeado (pode estar vazio).
	 * @return int|null
	 */
	private function extract_age_field( array $posted_data, $field_name ) {
		if ( empty( $field_name ) || ! isset( $posted_data[ $field_name ] ) ) {
			return null;
		}

		$value = $posted_data[ $field_name ];

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		$value = trim( (string) $value );

		if ( '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		$age = (int) $value;

		if ( $age < 3 || $age > 13 ) {
			// Fora da faixa esperada pelo formulário: registramos de forma
			// informativa (não é um erro) e seguimos sem a idade, em vez
			// de rejeitar a inscrição inteira por causa de um único campo.
			Logger::info( 'form', sprintf( 'Child age "%s" is outside the expected 3-13 range and was not stored.', $value ) );

			return null;
		}

		return $age;
	}

	/**
	 * Extrai e sanitiza um campo de texto simples a partir do nome de
	 * campo mapeado.
	 *
	 * @param array  $posted_data Dados brutos do CF7.
	 * @param string $field_name  Nome do campo mapeado (pode estar vazio).
	 * @return string
	 */
	private function extract_text_field( array $posted_data, $field_name ) {
		if ( empty( $field_name ) || ! isset( $posted_data[ $field_name ] ) ) {
			return '';
		}

		$value = $posted_data[ $field_name ];

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Extrai e sanitiza um campo de e-mail a partir do nome de campo mapeado.
	 *
	 * @param array  $posted_data Dados brutos do CF7.
	 * @param string $field_name  Nome do campo mapeado.
	 * @return string
	 */
	private function extract_email_field( array $posted_data, $field_name ) {
		if ( empty( $field_name ) || ! isset( $posted_data[ $field_name ] ) ) {
			return '';
		}

		$value = $posted_data[ $field_name ];

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return sanitize_email( $value );
	}

	/**
	 * Extrai e sanitiza um campo de telefone a partir do nome de campo mapeado.
	 *
	 * @param array  $posted_data Dados brutos do CF7.
	 * @param string $field_name  Nome do campo mapeado.
	 * @return string
	 */
	private function extract_phone_field( array $posted_data, $field_name ) {
		if ( empty( $field_name ) || ! isset( $posted_data[ $field_name ] ) ) {
			return '';
		}

		$value = $posted_data[ $field_name ];

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return mcr_sanitize_phone( $value );
	}

	/**
	 * Extrai e sanitiza um campo de múltiplos valores (checkbox), a partir
	 * do nome de campo mapeado, convertendo para texto separado por vírgulas.
	 *
	 * @param array  $posted_data Dados brutos do CF7.
	 * @param string $field_name  Nome do campo mapeado.
	 * @return string
	 */
	private function extract_multi_field( array $posted_data, $field_name ) {
		if ( empty( $field_name ) || ! isset( $posted_data[ $field_name ] ) ) {
			return '';
		}

		return mcr_interests_to_string( $posted_data[ $field_name ] );
	}

	/**
	 * Extrai e sanitiza um campo de texto longo (textarea) a partir do
	 * nome de campo mapeado.
	 *
	 * @param array  $posted_data Dados brutos do CF7.
	 * @param string $field_name  Nome do campo mapeado.
	 * @return string
	 */
	private function extract_textarea_field( array $posted_data, $field_name ) {
		if ( empty( $field_name ) || ! isset( $posted_data[ $field_name ] ) ) {
			return '';
		}

		$value = $posted_data[ $field_name ];

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
		}

		return sanitize_textarea_field( $value );
	}

	/**
	 * Valida se os campos obrigatórios da inscrição foram preenchidos
	 * corretamente após a resolução do mapeamento de campos.
	 *
	 * @param array $data Dados já sanitizados.
	 * @return bool
	 */
	private function validate_required_fields( array $data ) {
		// child_name e parent_email são os únicos campos verdadeiramente
		// universais entre formulários antigos e novos (são inclusive a
		// base da detecção de duplicidade), então são sempre exigidos.
		if ( empty( $data['child_name'] ) ) {
			return false;
		}

		if ( empty( $data['parent_email'] ) || ! is_email( $data['parent_email'] ) ) {
			return false;
		}

		// parent_name (nome do responsável) só é exigido quando o
		// administrador efetivamente mapeou esse campo para o formulário
		// monitorado - formulários mais novos podem não possuir esse
		// campo (ex: apenas "Primary Email"/"Primary Phone"), e isso não
		// deve impedir o restante da inscrição de ser salvo.
		$map = Settings::get_field_map();

		if ( ! empty( $map['parent_name'] ) && empty( $data['parent_name'] ) ) {
			return false;
		}

		return true;
	}
}

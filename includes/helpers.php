<?php
/**
 * Funções auxiliares globais do plugin Music Club Registrations.
 *
 * @package Music_Club_Registrations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retorna a lista de status válidos para uma inscrição, com seus rótulos
 * traduzidos.
 *
 * @return array<string,string> Mapa slug => rótulo.
 */
function mcr_get_statuses() {
	return array(
		'new'       => __( 'New', 'music-club-registrations' ),
		'contacted' => __( 'Contacted', 'music-club-registrations' ),
		'confirmed' => __( 'Confirmed', 'music-club-registrations' ),
		'cancelled' => __( 'Cancelled', 'music-club-registrations' ),
	);
}

/**
 * Verifica se um slug de status é válido.
 *
 * @param string $status Slug do status.
 * @return bool
 */
function mcr_is_valid_status( $status ) {
	return array_key_exists( $status, mcr_get_statuses() );
}

/**
 * Retorna o rótulo de exibição de um status, com fallback seguro.
 *
 * @param string $status Slug do status.
 * @return string
 */
function mcr_get_status_label( $status ) {
	$statuses = mcr_get_statuses();

	return $statuses[ $status ] ?? ucfirst( $status );
}

/**
 * Retorna a classe CSS usada para colorir o "badge" de status na listagem.
 *
 * @param string $status Slug do status.
 * @return string
 */
function mcr_get_status_css_class( $status ) {
	$map = array(
		'new'       => 'mcr-status-new',
		'contacted' => 'mcr-status-contacted',
		'confirmed' => 'mcr-status-confirmed',
		'cancelled' => 'mcr-status-cancelled',
	);

	return $map[ $status ] ?? 'mcr-status-new';
}

/**
 * Converte um array de interesses selecionados (checkbox do CF7) em uma
 * string separada por vírgulas, pronta para persistência.
 *
 * @param mixed $interests Valor bruto vindo do formulário (array ou string).
 * @return string
 */
function mcr_interests_to_string( $interests ) {
	if ( is_array( $interests ) ) {
		$clean = array_map( 'sanitize_text_field', $interests );
		$clean = array_filter( $clean );

		return implode( ', ', $clean );
	}

	return sanitize_text_field( (string) $interests );
}

/**
 * Formata uma string de interesses (armazenada com vírgulas) para exibição
 * em formato de lista amigável.
 *
 * @param string $interests String de interesses separada por vírgulas.
 * @return array<int,string>
 */
function mcr_interests_to_array( $interests ) {
	if ( empty( $interests ) ) {
		return array();
	}

	$parts = array_map( 'trim', explode( ',', $interests ) );

	return array_filter( $parts );
}

/**
 * Gera o número de inscrição formatado a partir do ID interno do registro.
 *
 * Utilizamos o ID auto-incremento da tabela como base numérica: o MySQL
 * garante que esse valor nunca é reutilizado (mesmo após exclusões), o que
 * satisfaz o requisito de numeração sequencial sem colisões, sem a
 * necessidade de um contador externo sujeito a condições de corrida.
 *
 * @param int $id ID interno do registro (coluna `id`).
 * @return string Ex: MC-000001
 */
function mcr_format_registration_number( $id ) {
	return sprintf( 'MC-%06d', absint( $id ) );
}

/**
 * Verifica se o usuário atual pode gerenciar as inscrições do Music Club.
 *
 * @return bool
 */
function mcr_current_user_can_manage() {
	/**
	 * Filtra a capability exigida para gerenciar inscrições.
	 *
	 * @param string $capability Capability padrão.
	 */
	$capability = apply_filters( 'mcr_manage_capability', 'manage_options' );

	return current_user_can( $capability );
}

/**
 * Sanitiza um campo de telefone, preservando apenas dígitos, espaços,
 * parênteses, hífen e sinal de mais.
 *
 * @param string $phone Telefone bruto.
 * @return string
 */
function mcr_sanitize_phone( $phone ) {
	$phone = sanitize_text_field( $phone );

	return preg_replace( '/[^0-9+\-\(\)\s]/', '', $phone );
}

/**
 * Retorna o rótulo e o ícone Dashicons apropriados para um status de
 * sincronização com o Excel Online, usado na listagem e na tela de
 * detalhes de uma inscrição.
 *
 * @param string $status Slug do status ('synced', 'pending', 'syncing', 'failed', 'not_configured').
 * @return array{label:string,icon:string,css_class:string}
 */
function mcr_get_excel_sync_status_meta( $status ) {
	$map = array(
		'synced'         => array(
			'label' => __( 'Synced', 'music-club-registrations' ),
			'icon'  => 'dashicons-yes-alt',
		),
		'pending'        => array(
			'label' => __( 'Pending', 'music-club-registrations' ),
			'icon'  => 'dashicons-clock',
		),
		'syncing'        => array(
			'label' => __( 'Syncing…', 'music-club-registrations' ),
			'icon'  => 'dashicons-update',
		),
		'failed'         => array(
			'label' => __( 'Failed', 'music-club-registrations' ),
			'icon'  => 'dashicons-warning',
		),
		'not_configured' => array(
			'label' => __( 'Not configured', 'music-club-registrations' ),
			'icon'  => 'dashicons-minus',
		),
	);

	$meta = $map[ $status ] ?? $map['not_configured'];

	$meta['css_class'] = 'mcr-excel-sync-' . sanitize_html_class( $status );

	return $meta;
}

/**
 * Renderiza o "badge" (ícone + rótulo) de status de sincronização com o
 * Excel Online, para uso na listagem e na tela de detalhes.
 *
 * @param string $status Slug do status.
 * @return string Marcação HTML já escapada.
 */
function mcr_render_excel_sync_badge( $status ) {
	$meta = mcr_get_excel_sync_status_meta( $status );

	return sprintf(
		'<span class="mcr-excel-sync-badge %1$s"><span class="dashicons %2$s"></span> %3$s</span>',
		esc_attr( $meta['css_class'] ),
		esc_attr( $meta['icon'] ),
		esc_html( $meta['label'] )
	);
}

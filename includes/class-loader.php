<?php
/**
 * Classe responsável por orquestrar a inicialização de todos os
 * componentes do plugin, mantendo o bootstrap central em um único lugar.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Loader
 *
 * Implementa um singleton simples que instancia e conecta as demais
 * classes do plugin aos hooks do WordPress, mantendo o arquivo principal
 * do plugin enxuto.
 */
class Loader {

	/**
	 * Instância única da classe.
	 *
	 * @var Loader|null
	 */
	private static $instance = null;

	/**
	 * Retorna a instância única do Loader.
	 *
	 * @return Loader
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Construtor privado (padrão singleton).
	 */
	private function __construct() {}

	/**
	 * Inicializa todos os componentes do plugin.
	 *
	 * Executado apenas no front-end/admin em runtime (após plugins_loaded),
	 * nunca durante a ativação/desinstalação, que possuem seu próprio fluxo.
	 *
	 * @return void
	 */
	public function run() {
		// Garante que uma chave de API sempre exista, mesmo em instalações
		// que tenham sido atualizadas a partir de uma versão anterior sem
		// passar pelo hook de ativação novamente.
		Settings::ensure_api_key();

		// Captura de envios do Contact Form 7 (formulário definido em Settings).
		( new Form_Handler() )->register_hooks();

		// API REST protegida.
		( new REST_API() )->register_hooks();

		// Área administrativa: só é necessário carregar no contexto de admin,
		// mas os hooks internos (admin_menu, admin_init etc.) já garantem
		// isso nativamente. Registrar sempre mantém o código mais simples
		// e sem custo perceptível fora do admin.
		if ( is_admin() ) {
			( new Admin() )->register_hooks();
			( new Export() )->register_hooks();
		}
	}
}

<?php
/**
 * Sistema de versionamento e migrações do banco de dados do plugin.
 *
 * Garante que, em atualizações futuras, a estrutura do banco seja
 * ajustada automaticamente e de forma segura (nunca destrutiva),
 * comparando a versão instalada com a versão do código.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Migrations
 *
 * Responsabilidade única: decidir se uma migração é necessária e
 * executá-la de forma idempotente.
 */
class Migrations {

	/**
	 * Nome da opção que guarda a versão do banco de dados instalada.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'mcr_db_version';

	/**
	 * Registra os hooks responsáveis por verificar a versão do banco em
	 * cada carregamento do admin, garantindo que atualizações do plugin
	 * (via upload de nova versão, sem reativação) também disparem a
	 * migração automaticamente.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'plugins_loaded', array( $this, 'maybe_migrate' ), 5 );
	}

	/**
	 * Compara a versão instalada com a versão atual do plugin e executa a
	 * migração, se necessário.
	 *
	 * @return void
	 */
	public function maybe_migrate() {
		$installed_version = get_option( self::OPTION_NAME, '' );

		if ( $installed_version === MCR_DB_VERSION ) {
			return;
		}

		Database::install();

		Logger::info(
			'migration',
			sprintf(
				'Database migrated from version "%s" to "%s".',
				$installed_version ? $installed_version : 'none',
				MCR_DB_VERSION
			)
		);

		update_option( self::OPTION_NAME, MCR_DB_VERSION );
	}
}

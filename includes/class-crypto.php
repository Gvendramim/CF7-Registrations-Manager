<?php
/**
 * Criptografia de dados sensíveis armazenados pelo plugin (Client Secret
 * do app Microsoft, access_token e refresh_token do OAuth).
 *
 * Antes desta classe, esses valores ficavam em texto plano na tabela de
 * opções do WordPress - protegidos apenas pelo mesmo nível de segurança
 * do banco de dados em si. Agora eles são cifrados com AES-256-GCM
 * (cifra autenticada, também protege contra adulteração) antes de serem
 * salvos, usando uma chave derivada dos "salts" únicos já existentes na
 * instalação do WordPress (wp-config.php) - que nunca ficam no banco de
 * dados, apenas no sistema de arquivos do servidor.
 *
 * Isso não é uma criptografia "perfeita" (quem tiver acesso ao
 * wp-config.php e ao banco de dados consegue decifrar), mas eleva
 * significativamente a proteção contra o cenário mais comum: vazamento
 * apenas do banco de dados (ex: backup exposto, SQL injection em outro
 * ponto do site, acesso indevido ao phpMyAdmin).
 *
 * Totalmente retrocompatível: instalações que já tinham o Client
 * Secret/tokens salvos em texto plano continuam funcionando
 * normalmente - o valor antigo é reconhecido como "não criptografado" e
 * usado como está, sendo automaticamente cifrado na próxima vez que for
 * salvo.
 *
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Crypto
 *
 * Responsabilidade única: cifrar e decifrar strings sensíveis, de forma
 * segura e sempre retrocompatível com valores antigos em texto plano.
 */
class Crypto {

	/**
	 * Prefixo usado para identificar um valor como cifrado por esta
	 * classe (versão do formato), distinguindo-o de valores antigos em
	 * texto plano.
	 *
	 * @var string
	 */
	const PREFIX = 'mcr_enc_v1:';

	/**
	 * Algoritmo de cifra utilizado. AES-256-GCM é uma cifra autenticada
	 * (AEAD): além de confidencialidade, detecta qualquer adulteração do
	 * valor cifrado.
	 *
	 * @var string
	 */
	const CIPHER = 'aes-256-gcm';

	/**
	 * Cifra um valor em texto plano. Retorna string vazia para entrada
	 * vazia (nunca cifra "nada"). Em caso de falha inesperada da extensão
	 * OpenSSL (ambiente muito incomum), retorna o valor original em texto
	 * plano em vez de lançar um erro fatal ou perder o dado - a
	 * disponibilidade do plugin nunca deve depender de criptografia
	 * funcionar.
	 *
	 * @param string $plaintext Valor original.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;

		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! self::is_supported() ) {
			return $plaintext;
		}

		$key = self::get_key();
		$iv  = random_bytes( 12 ); // Nonce de 12 bytes, padrão para GCM.
		$tag = '';

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		if ( false === $ciphertext ) {
			return $plaintext;
		}

		return self::PREFIX . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decifra um valor previamente cifrado por encrypt(). Se o valor não
	 * estiver no formato cifrado esperado (ex: um Client Secret salvo em
	 * texto plano por uma versão anterior do plugin), retorna o próprio
	 * valor sem modificação - garantindo que instalações existentes
	 * continuem funcionando sem qualquer ação manual.
	 *
	 * @param string $value Valor armazenado (cifrado ou texto plano legado).
	 * @return string Valor em texto plano. String vazia se a decifragem falhar.
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			// Não está no formato cifrado desta classe - trata como valor
			// legado em texto plano (retrocompatibilidade).
			return $value;
		}

		if ( ! self::is_supported() ) {
			// Não deveria acontecer (o mesmo servidor cifrou), mas por
			// segurança nunca fatal - apenas indica falha de leitura.
			return '';
		}

		$raw = base64_decode( substr( $value, strlen( self::PREFIX ) ), true );

		if ( false === $raw || strlen( $raw ) < 28 ) {
			return '';
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$key       = self::get_key();
		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? '' : $plaintext;
	}

	/**
	 * Verifica se o ambiente PHP possui suporte à cifra utilizada.
	 *
	 * @return bool
	 */
	public static function is_supported() {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Deriva uma chave de 256 bits a partir dos "salts" únicos da
	 * instalação do WordPress (definidos em wp-config.php, nunca
	 * armazenados no banco de dados). Isso garante uma chave estável,
	 * exclusiva de cada instalação, sem exigir nenhuma configuração
	 * manual adicional do administrador.
	 *
	 * @return string 32 bytes binários.
	 */
	private static function get_key() {
		$material = 'mcr-crypto';

		if ( function_exists( 'wp_salt' ) ) {
			$material .= wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		} elseif ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$material .= AUTH_KEY . SECURE_AUTH_KEY;
		} else {
			// Ambiente muito incomum sem nenhum salt configurado - usa um
			// valor persistido como último recurso, para nunca impedir o
			// funcionamento do plugin.
			$fallback = get_option( 'mcr_crypto_fallback_key' );

			if ( ! $fallback ) {
				$fallback = wp_generate_password( 64, true, true );
				update_option( 'mcr_crypto_fallback_key', $fallback );
			}

			$material .= $fallback;
		}

		return hash( 'sha256', $material, true );
	}
}

<?php
/**
 * @package Music_Club_Registrations
 */

namespace Music_Club_Registrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crypto {

	/**
	 * @var string
	 */
	const PREFIX = 'mcr_enc_v1:';

	/**
	 * @var string
	 */
	const CIPHER = 'aes-256-gcm';

	/**
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
	 * @param string $value Valor armazenado (cifrado ou texto plano legado).
	 * @return string Valor em texto plano. String vazia se a decifragem falhar.
	 */
	public static function decrypt( $value ) {
		$value = (string) $value;

		if ( '' === $value ) {
			return '';
		}

		if ( 0 !== strpos( $value, self::PREFIX ) ) {
			return $value;
		}

		if ( ! self::is_supported() ) {
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
	 * @return string 32 bytes binários.
	 */
	private static function get_key() {
		$material = 'mcr-crypto';

		if ( function_exists( 'wp_salt' ) ) {
			$material .= wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
		} elseif ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			$material .= AUTH_KEY . SECURE_AUTH_KEY;
		} else {
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

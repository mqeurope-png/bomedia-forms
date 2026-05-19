<?php
/**
 * AES-256-CBC encryption helpers.
 *
 * Used to store sensitive form configuration (AgileCRM API keys, captcha
 * secrets) encrypted at rest. The encryption key is derived from the
 * site's AUTH_KEY constant defined in wp-config.php, so encrypted values
 * are not portable between installations — which is the intended behaviour
 * for a multi-install plugin.
 *
 * @package BomediaForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class BF_Encryption
 */
class BF_Encryption {

	const CIPHER = 'aes-256-cbc';

	/**
	 * Derive a stable 32-byte key from AUTH_KEY.
	 *
	 * @return string Binary key (32 bytes).
	 */
	private static function key() {
		$secret = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'bomedia-forms-insecure-fallback';
		return hash( 'sha256', $secret, true );
	}

	/**
	 * Whether a real AUTH_KEY is available for secure encryption.
	 *
	 * @return bool
	 */
	public static function auth_key_available() {
		return defined( 'AUTH_KEY' ) && AUTH_KEY && 'put your unique phrase here' !== AUTH_KEY;
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * Returns a base64 string of "iv:ciphertext". Empty input returns an
	 * empty string so callers can store blanks transparently.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Encrypted, base64-encoded payload (or '' on empty input).
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || null === $plaintext ) {
			return '';
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// No OpenSSL: store as-is but flagged so we never try to decrypt it.
			return 'plain:' . base64_encode( $plaintext );
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$cipher = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		return 'enc:' . base64_encode( $iv . $cipher );
	}

	/**
	 * Decrypt a payload produced by encrypt().
	 *
	 * @param string $payload Encrypted payload.
	 * @return string Decrypted plaintext (or '' on failure / empty input).
	 */
	public static function decrypt( $payload ) {
		if ( '' === $payload || null === $payload ) {
			return '';
		}

		if ( 0 === strpos( $payload, 'plain:' ) ) {
			return (string) base64_decode( substr( $payload, 6 ), true );
		}

		if ( 0 === strpos( $payload, 'enc:' ) ) {
			$payload = substr( $payload, 4 );
		}

		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( $payload, true );
		if ( false === $raw ) {
			return '';
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( strlen( $raw ) <= $iv_len ) {
			return '';
		}

		$iv     = substr( $raw, 0, $iv_len );
		$cipher = substr( $raw, $iv_len );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether a stored value looks like an encrypted payload.
	 *
	 * @param string $value Stored value.
	 * @return bool
	 */
	public static function is_encrypted( $value ) {
		return is_string( $value ) && ( 0 === strpos( $value, 'enc:' ) || 0 === strpos( $value, 'plain:' ) );
	}
}

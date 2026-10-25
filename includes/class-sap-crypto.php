<?php
/**
 * At-rest protection for OAuth tokens.
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts secrets with a key derived from the site's own WordPress salts.
 *
 * This is not a vault: anyone who can read wp-config.php and the database can
 * recover the tokens. What it does buy is that a leaked database dump, a
 * careless backup, or a plugin that dumps the options table does not hand over
 * a working X token. That is the realistic threat, so it is the one defended.
 */
class SAP_Crypto {

	const CIPHER = 'aes-256-gcm';
	const PREFIX = 'sap1:';
	const PLAIN  = 'sap0:';

	/**
	 * Whether real encryption is available on this host.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	/**
	 * Derive the encryption key from WordPress salts.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function key() {
		$material = '';
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT' ) as $constant ) {
			if ( defined( $constant ) ) {
				$material .= constant( $constant );
			}
		}
		if ( '' === $material ) {
			// A site with no salts defined is unusual but must not fatal.
			$material = get_option( 'siteurl' ) . ABSPATH;
		}
		return hash( 'sha256', 'sap|' . $material, true );
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plaintext Secret.
	 * @return string Storable string.
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}
		if ( ! self::available() ) {
			return self::PLAIN . base64_encode( $plaintext );
		}

		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $ct ) {
			return self::PLAIN . base64_encode( $plaintext );
		}
		return self::PREFIX . base64_encode( $iv . $tag . $ct );
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Value produced by encrypt().
	 * @return string Plaintext, or '' if it cannot be recovered.
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}

		if ( 0 === strpos( $stored, self::PLAIN ) ) {
			return (string) base64_decode( substr( $stored, strlen( self::PLAIN ) ), true );
		}

		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			// Written before encryption existed, or by hand. Take it as-is.
			return $stored;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}

		$iv  = substr( $raw, 0, 12 );
		$tag = substr( $raw, 12, 16 );
		$ct  = substr( $raw, 28 );

		$plain = openssl_decrypt( $ct, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return ( false === $plain ) ? '' : $plain;
	}
}

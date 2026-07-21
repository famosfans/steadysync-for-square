<?php
/**
 * Token-at-Rest-Verschlüsselung. AES-256-GCM (AEAD) — authentifiziert, d. h. Manipulation am
 * Ciphertext wird beim Entschlüsseln erkannt (Auth-Tag), nicht nur vertraulich. Schlüsselmaterial
 * aus AUTH_KEY/AUTH_SALT der WP-Installation.
 *
 * Rückwärtskompatibel: alte AES-256-CBC-Blobs (ohne Magic-Prefix) werden weiterhin entschlüsselt;
 * neu geschrieben wird immer GCM. So gehen bestehende gespeicherte Tokens beim Update nicht verloren.
 */
namespace Steadysync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Crypto {

	/** Kennung für das neue GCM-Format (unterscheidet von Legacy-CBC-Blobs). */
	private const MAGIC = "SSG1\x00";

	private static function key(): string {
		return hash( 'sha256', ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'steadysync' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ), true );
	}

	public static function encrypt( string $plain ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv  = random_bytes( 12 ); // GCM-Standard-Nonce.
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $ct ) {
			return base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return base64_encode( self::MAGIC . $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	public static function decrypt( string $enc ): string {
		$raw = base64_decode( $enc, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return $raw;
		}

		$magic = self::MAGIC;
		$mlen  = strlen( $magic );
		if ( 0 === strncmp( $raw, $magic, $mlen ) ) {
			// Neues GCM-Format: MAGIC | iv(12) | tag(16) | ciphertext.
			$body = substr( $raw, $mlen );
			if ( strlen( $body ) <= 28 ) {
				return '';
			}
			$iv  = substr( $body, 0, 12 );
			$tag = substr( $body, 12, 16 );
			$ct  = substr( $body, 28 );
			$pt  = openssl_decrypt( $ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $pt ? '' : $pt;
		}

		// Legacy AES-256-CBC: iv(16) | ciphertext.
		if ( strlen( $raw ) <= 16 ) {
			return $raw;
		}
		$iv = substr( $raw, 0, 16 );
		$ct = substr( $raw, 16 );
		$pt = openssl_decrypt( $ct, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $pt ? '' : $pt;
	}
}

<?php
/**
 * The HostelMate key. Either a wp-config.php constant (never in the database), or
 * stored encrypted with a key derived from the wp-config.php salts, so a database
 * leak alone doesn't reveal it. It is never shown again after saving.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;

defined( 'ABSPATH' ) || exit;

final class Secret {

	private const FORMAT  = 'v1:';
	private const CONTEXT = 'hostelmate-hbook|api-key|v1|';

	/** True when the key lives in wp-config.php. */
	public static function from_constant(): bool {
		return defined( Config::API_KEY_CONSTANT );
	}

	/** True when a key is available (constant or stored). */
	public static function has(): bool {
		return null !== self::get();
	}

	/**
	 * The key, or null when none is set or it can't be decrypted (salts changed).
	 * Only Http calls this.
	 */
	public static function get(): ?string {
		if ( self::from_constant() ) {
			$value = constant( Config::API_KEY_CONSTANT );
			return self::is_valid( $value ) ? $value : null;
		}
		$stored = get_option( Config::OPT_KEY, '' );
		if ( ! is_string( $stored ) || ! str_starts_with( $stored, self::FORMAT ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::FORMAT ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- stored ciphertext.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, self::box_key() );
		return ( is_string( $plain ) && self::is_valid( $plain ) ) ? $plain : null;
	}

	/**
	 * Encrypts and stores a key typed on the Settings screen.
	 *
	 * @param string $plain The key as pasted.
	 */
	public static function store( string $plain ): bool {
		$plain = trim( $plain );
		if ( ! self::is_valid( $plain ) || ! self::salts_strong() ) {
			return false;
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::box_key() );
		update_option( Config::OPT_KEY, self::FORMAT . base64_encode( $nonce . $box ), false ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- stored ciphertext.
		return true;
	}

	/**
	 * True when wp-config.php defines the two salts the encryption key comes from, and they aren't the
	 * sample phrase. Otherwise WordPress keeps salts in the database, and a database copy alone would
	 * decrypt the key.
	 */
	public static function salts_strong(): bool {
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY' ) as $name ) {
			$v = defined( $name ) ? constant( $name ) : '';
			if ( ! is_string( $v ) || strlen( $v ) < 32 || str_contains( $v, 'put your unique phrase here' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Forgets the stored key. */
	public static function clear(): void {
		delete_option( Config::OPT_KEY );
	}

	/** The last 4 characters, the only part ever shown. Empty when there is no key. */
	public static function hint(): string {
		$key = self::get();
		return null === $key ? '' : substr( $key, -4 );
	}

	/**
	 * Shape check only: printable, no spaces, sane length.
	 *
	 * @param mixed $value Anything.
	 */
	private static function is_valid( $value ): bool {
		return Validate::is_token( $value, Config::API_KEY_MIN_LEN, Config::API_KEY_MAX_LEN );
	}

	/** 32-byte encryption key derived from two wp-config.php salts. */
	private static function box_key(): string {
		return sodium_crypto_generichash( self::CONTEXT . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}

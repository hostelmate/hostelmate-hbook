<?php
/**
 * Identifiers, declared once.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

defined( 'ABSPATH' ) || exit;

final class Ids {

	/** A random version-4 UUID from the system's secure random source. */
	public static function uuid4(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		$hex  = bin2hex( $b );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * The same parts always give the same key, so a retry of the same change replays safely.
	 *
	 * @param string ...$parts What identifies the change (booking id, operation, body hash).
	 */
	public static function idempotency_key( string ...$parts ): string {
		return hash( 'sha256', implode( "\x1f", $parts ) );
	}

	/**
	 * A change marker for data that may describe a person: keyed with the site's secret salt, so it
	 * can't be checked against guessed values without wp-config.php.
	 *
	 * @param mixed $data Anything JSON-encodable.
	 */
	public static function marker( $data ): string {
		return hash_hmac( 'sha256', (string) wp_json_encode( $data ), wp_salt( 'auth' ) . '|hostelmate-hbook-marker' );
	}

	/** A random token for claims (32 hex characters). */
	public static function token(): string {
		return bin2hex( random_bytes( 16 ) );
	}
}

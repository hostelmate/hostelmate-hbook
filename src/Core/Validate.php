<?php
/**
 * Input checks, declared once. Pure functions: no WordPress calls.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

defined( 'ABSPATH' ) || exit;

final class Validate {

	/**
	 * A canonical UUID (any version, any case).
	 *
	 * @param mixed $value Anything.
	 */
	public static function is_uuid( $value ): bool {
		return is_string( $value )
			&& 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $value );
	}

	/**
	 * A real calendar date written exactly as YYYY-MM-DD.
	 *
	 * @param mixed $value Anything.
	 */
	public static function is_date( $value ): bool {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})\z/', $value, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * An integer (not a numeric string, not a float) within [$min, $max].
	 *
	 * @param mixed $value Anything.
	 * @param int   $min   Smallest allowed.
	 * @param int   $max   Largest allowed.
	 */
	public static function is_int_between( $value, int $min, int $max ): bool {
		return is_int( $value ) && $value >= $min && $value <= $max;
	}

	/**
	 * A string of 1..$max characters with no control characters.
	 *
	 * @param mixed $value Anything.
	 * @param int   $max   Longest allowed, in characters.
	 */
	public static function is_text( $value, int $max ): bool {
		return is_string( $value )
			&& '' !== $value
			&& mb_strlen( $value ) <= $max
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/u', $value )
			&& false !== mb_detect_encoding( $value, 'UTF-8', true );
	}

	/**
	 * An email address.
	 *
	 * @param mixed $value Anything.
	 */
	public static function is_email( $value ): bool {
		return is_string( $value ) && strlen( $value ) <= 254 && false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * One of a fixed list (strict comparison).
	 *
	 * @param mixed        $value   Anything.
	 * @param list<string> $allowed Allowed values.
	 */
	public static function is_one_of( $value, array $allowed ): bool {
		return is_string( $value ) && in_array( $value, $allowed, true );
	}

	/**
	 * Printable ASCII with no spaces, of the given length range (API keys, tokens).
	 *
	 * @param mixed $value Anything.
	 * @param int   $min   Shortest allowed.
	 * @param int   $max   Longest allowed.
	 */
	public static function is_token( $value, int $min, int $max ): bool {
		return is_string( $value )
			&& strlen( $value ) >= $min
			&& strlen( $value ) <= $max
			&& 1 === preg_match( '/^[\x21-\x7E]+\z/', $value );
	}
}

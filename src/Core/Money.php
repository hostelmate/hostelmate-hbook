<?php
/**
 * Money, declared once. Amounts move around as integers in minor units
 * (HostelMate sends every currency ×100, API/utils.py:36-44). Decimal strings are
 * parsed by hand, so no float ever touches a price.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

defined( 'ABSPATH' ) || exit;

final class Money {

	/** Minor units per major unit, the same for every currency in HostelMate's API. */
	public const SCALE = 100;

	/**
	 * "1250.5" / "1250.50" / "1250" / "-3.25" → minor units. More than 2 decimals is refused.
	 *
	 * @param string $decimal A decimal number as text (HBook stores decimal(14,2)).
	 */
	public static function to_minor( string $decimal ): int {
		if ( 1 !== preg_match( '/^(-?)(\d{1,12})(?:\.(\d{1,2}))?\z/', trim( $decimal ), $m ) ) {
			throw new \InvalidArgumentException( 'Not a decimal amount.' );
		}
		$minor = (int) $m[2] * self::SCALE + (int) str_pad( $m[3] ?? '', 2, '0' );
		return '-' === $m[1] ? -$minor : $minor;
	}

	/**
	 * Minor units → "1250.50" (always two decimals: HBook's decimal format and HostelMate's payment format).
	 *
	 * @param int $minor Amount in minor units.
	 */
	public static function to_decimal( int $minor ): string {
		$sign = $minor < 0 ? '-' : '';
		$abs  = abs( $minor );
		return $sign . intdiv( $abs, self::SCALE ) . '.' . str_pad( (string) ( $abs % self::SCALE ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Splits $total into $parts equal integers; the remainder goes on the last one.
	 *
	 * @param int $total Amount in minor units (0 or more).
	 * @param int $parts How many pieces (1 or more).
	 * @return list<int>
	 */
	public static function split( int $total, int $parts ): array {
		return self::split_by_weights( $total, array_fill( 0, max( 1, $parts ), 1 ) );
	}

	/**
	 * Splits $total in proportion to $weights; the remainder goes on the last piece,
	 * so the pieces always add up to exactly $total.
	 *
	 * @param int       $total   Amount in minor units (0 or more).
	 * @param list<int> $weights Non-negative weights, at least one.
	 * @return list<int>
	 */
	public static function split_by_weights( int $total, array $weights ): array {
		if ( $total < 0 || array() === $weights ) {
			throw new \InvalidArgumentException( 'Bad split.' );
		}
		$sum = 0;
		foreach ( $weights as $w ) {
			if ( ! is_int( $w ) || $w < 0 ) {
				throw new \InvalidArgumentException( 'Bad weight.' );
			}
			if ( $w > PHP_INT_MAX - $sum ) {
				throw new \OverflowException( 'Weights too large to split.' );
			}
			$sum += $w;
		}
		$count = count( $weights );
		$out   = array();
		$given = 0;
		foreach ( $weights as $i => $w ) {
			if ( $i === $count - 1 ) {
				$out[] = $total - $given;
				break;
			}
			// Equal weights when they are all zero; otherwise proportional, rounded down.
			$piece  = 0 === $sum ? intdiv( $total, $count ) : self::mul_div( $total, $w, $sum );
			$out[]  = $piece;
			$given += $piece;
		}
		return $out;
	}

	/**
	 * floor($a * $b / $c) for non-negative integers, exact, refusing instead of overflowing.
	 *
	 * @param int $a Non-negative.
	 * @param int $b Non-negative.
	 * @param int $c Positive.
	 */
	private static function mul_div( int $a, int $b, int $c ): int {
		$whole = intdiv( $a, $c );
		$rest  = $a % $c;
		if ( ( 0 !== $b && $whole > intdiv( PHP_INT_MAX, $b ) ) || ( 0 !== $b && $rest > intdiv( PHP_INT_MAX, $b ) ) ) {
			throw new \OverflowException( 'Amount too large to split.' );
		}
		return $whole * $b + intdiv( $rest * $b, $c );
	}
}

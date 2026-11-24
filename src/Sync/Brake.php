<?php
/**
 * The big-change brake (plan 6.7). Pure: is this plan too big to apply without a person?
 * Guards against a HostelMate bug or breach writing nonsense onto the website.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Money;

defined( 'ABSPATH' ) || exit;

final class Brake {

	public const OK        = 'ok';
	public const TOO_BIG   = 'too_big';
	public const PRICE_LOW = 'price_too_low';

	/**
	 * @param list<string>                     $window      The first Config::BRAKE_WINDOW_DAYS nights.
	 * @param list<int>                        $matched     Matched accommodation ids.
	 * @param array<int, list<string>>         $units_of    Accommodation → unit keys.
	 * @param array<int, array<string, string>> $price_now   Accommodation → night → price HBook charges now.
	 * @param array<int, array<string, string>> $price_next  Accommodation → night → planned price.
	 * @param array<string, list<string>>      $blocked_now  Unit → nights the plugin blocks now.
	 * @param array<string, list<string>>      $blocked_next Unit → nights it would block.
	 * @return array{verdict: string, changed: int, total: int}
	 */
	public static function check( array $window, array $matched, array $units_of, array $price_now, array $price_next, array $blocked_now, array $blocked_next ): array {
		$changed = 0;
		$total   = 0;
		foreach ( $matched as $accom ) {
			foreach ( $window as $night ) {
				$next = $price_next[ $accom ][ $night ] ?? null;
				if ( null !== $next && Money::to_minor( $next ) < Config::MIN_SANE_PRICE_MINOR ) {
					return array(
						'verdict' => self::PRICE_LOW,
						'changed' => 0,
						'total'   => 0,
					);
				}
				$now        = $price_now[ $accom ][ $night ] ?? null;
				$price_jump = null !== $now && null !== $next && self::jumped( $now, $next );
				foreach ( $units_of[ $accom ] ?? array() as $unit ) {
					++$total;
					$newly_closed = in_array( $night, $blocked_next[ $unit ] ?? array(), true ) && ! in_array( $night, $blocked_now[ $unit ] ?? array(), true );
					if ( $newly_closed || $price_jump ) {
						++$changed;
					}
				}
			}
		}
		$too_big = $changed > Config::BRAKE_MIN_ROOM_NIGHTS && $changed * 100 > $total * Config::BRAKE_SHARE_PCT;
		return array(
			'verdict' => $too_big ? self::TOO_BIG : self::OK,
			'changed' => $changed,
			'total'   => $total,
		);
	}

	/**
	 * True when the price moves by more than Config::BRAKE_PRICE_JUMP_PCT, either way.
	 *
	 * @param string $now  Current price.
	 * @param string $next Planned price.
	 */
	private static function jumped( string $now, string $next ): bool {
		$a = Money::to_minor( $now );
		$b = Money::to_minor( $next );
		if ( 0 === $a ) {
			return 0 !== $b;
		}
		return abs( $b - $a ) * 100 > abs( $a ) * Config::BRAKE_PRICE_JUMP_PCT;
	}
}

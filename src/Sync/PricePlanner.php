<?php
/**
 * Wanted HBook seasons and rates for the horizon (plan 6.2). Pure: data in, plan out.
 *
 * Each night gets one "HostelMate auto" season (high priority), grouped with every other
 * night that has the same normal season and the same price for every accommodation.
 * Every published accommodation gets a rate in every season, because one missing rate
 * fails HBook's whole search (available-accom.php:692-697).
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Money;
use HostelMate\HBook\HBook\Prices;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Seasons from \HostelMate\HBook\Types
 * @phpstan-import-type Rate from \HostelMate\HBook\Types
 * @phpstan-import-type HmRates from \HostelMate\HBook\Types
 * @phpstan-import-type PricePlan from \HostelMate\HBook\Types
 */
final class PricePlanner {

	/**
	 * @param list<string>                                   $nights      Horizon nights, in order.
	 * @param Seasons                                        $seasons     HBook\Prices::seasons().
	 * @param list<int>                                      $own_seasons The plugin's own season ids (skipped as base).
	 * @param list<Rate>                                     $rates       HBook\Prices::rates().
	 * @param list<int>                                      $accoms      Published accommodation ids.
	 * @param array<int, string>                             $accom_room  Accommodation id → HostelMate room id (matched only).
	 * @param HmRates                                        $hm          HostelMate room id → night → answer.
	 * @param array<string, int>                             $last_price  HostelMate room id → last known price (minor units).
	 * @return PricePlan
	 */
	public static function plan( array $nights, array $seasons, array $own_seasons, array $rates, array $accoms, array $accom_room, array $hm, array $last_price ): array {
		$groups       = array();
		$foreign_high = array();
		$no_season    = array();
		$prices       = array();

		foreach ( $nights as $night ) {
			if ( 0 !== Prices::season_for( $seasons, $night, $own_seasons, array( 'high' ) ) ) {
				$foreign_high[] = $night;
			}
			$base = Prices::season_for( $seasons, $night, $own_seasons, array( '', 'low' ) );
			if ( 0 === $base ) {
				$no_season[] = $night;
			}
			$row = array();
			foreach ( $accoms as $accom ) {
				$amount = self::price_for( $accom, $night, $base, $rates, $accom_room, $hm, $last_price );
				if ( null !== $amount ) {
					$row[ $accom ]              = $amount;
					$prices[ $accom ][ $night ] = $amount;
				}
			}
			ksort( $row );
			$key = hash( 'sha256', $base . '|' . wp_json_encode( $row ) );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'key'    => $key,
					'base'   => $base,
					'nights' => array(),
					'row'    => $row,
				);
			}
			$groups[ $key ]['nights'][] = $night;
		}

		$out = array();
		foreach ( $groups as $group ) {
			$out[] = array(
				'key'    => $group['key'],
				'base'   => $group['base'],
				'ranges' => self::ranges( $group['nights'] ),
				'rates'  => self::rate_rows( $group['row'] ),
			);
		}
		return array(
			'seasons'      => $out,
			'foreign_high' => $foreign_high,
			'no_season'    => $no_season,
			'last_price'   => self::new_last_prices( $nights, $hm, $last_price ),
			'prices'       => $prices,
		);
	}

	/**
	 * One accommodation's price for one night, as an HBook decimal string (null = no rate, as today).
	 *
	 * @param int                                                  $accom      Accommodation id.
	 * @param string                                               $night      Night.
	 * @param int                                                  $base       Its normal season (0 = none).
	 * @param list<Rate>                                           $rates      HBook rates.
	 * @param array<int, string>                                   $accom_room Matching.
	 * @param HmRates                                              $hm         HostelMate answers.
	 * @param array<string, int>                                   $last_price Last known prices.
	 */
	private static function price_for( int $accom, string $night, int $base, array $rates, array $accom_room, array $hm, array $last_price ): ?string {
		$own_rate = 0 === $base ? null : Prices::night_rate( $rates, $accom, $base );
		$room     = $accom_room[ $accom ] ?? null;
		if ( null === $room ) {
			return $own_rate; // Not matched: keeps HBook's own price, unchanged.
		}
		$hm_price = $hm[ $room ][ $night ]['price'] ?? null;
		if ( null !== $hm_price ) {
			return Money::to_decimal( $hm_price );
		}
		// No HostelMate price: the unit is blocked anyway; a price only keeps search working.
		if ( isset( $last_price[ $room ] ) ) {
			return Money::to_decimal( $last_price[ $room ] );
		}
		return $own_rate;
	}

	/**
	 * Consecutive nights merged into inclusive [start, end] ranges (HBook season dates include their end).
	 *
	 * @param list<string> $nights Sorted nights.
	 * @return list<array{0: string, 1: string}>
	 */
	public static function ranges( array $nights ): array {
		$out   = array();
		$start = '';
		$end   = '';
		foreach ( $nights as $night ) {
			if ( '' !== $end && Clock::add_days( $end, 1 ) === $night ) {
				$end = $night;
				continue;
			}
			if ( '' !== $start ) {
				$out[] = array( $start, $end );
			}
			$start = $night;
			$end   = $night;
		}
		if ( '' !== $start ) {
			$out[] = array( $start, $end );
		}
		return $out;
	}

	/**
	 * Rooms with the same price share one rate row, at most Config::MAX_ACCOMS_PER_RATE per row.
	 *
	 * @param array<int, string> $row Accommodation id → amount.
	 * @return list<array{amount: string, accoms: list<int>}>
	 */
	private static function rate_rows( array $row ): array {
		$by_amount = array();
		foreach ( $row as $accom => $amount ) {
			$by_amount[ $amount ][] = $accom;
		}
		$out = array();
		foreach ( $by_amount as $amount => $list ) {
			foreach ( array_chunk( $list, Config::MAX_ACCOMS_PER_RATE ) as $chunk ) {
				$out[] = array(
					'amount' => (string) $amount,
					'accoms' => $chunk,
				);
			}
		}
		return $out;
	}

	/**
	 * Each room's latest price in the horizon (kept for nights HostelMate gives no price).
	 *
	 * @param list<string>                                         $nights Horizon nights.
	 * @param HmRates                                              $hm     HostelMate answers.
	 * @param array<string, int>                                   $last   Last known prices.
	 * @return array<string, int>
	 */
	public static function new_last_prices( array $nights, array $hm, array $last ): array {
		foreach ( $hm as $room => $by_night ) {
			foreach ( $nights as $night ) {
				$price = $by_night[ $night ]['price'] ?? null;
				if ( null !== $price ) {
					$last[ $room ] = $price;
					break;
				}
			}
		}
		return $last;
	}
}

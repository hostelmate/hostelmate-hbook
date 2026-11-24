<?php
/**
 * A short, readable account of a plan for the Overview: what would change on the website.
 * Room names and ids only, never guest data.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\HBook\Occupancy;
use HostelMate\HBook\HBook\Prices;

defined( 'ABSPATH' ) || exit;

final class Summary {

	private const MAX_LINES = 200;

	/**
	 * What HBook charges today, per accommodation and night (its own seasons, the plugin's included).
	 *
	 * @param array<string, mixed> $ctx    SyncRunner::context().
	 * @param list<string>         $nights Nights to look at.
	 * @return array<int, array<string, string>>
	 */
	public static function prices_now( array $ctx, array $nights ): array {
		$out       = array();
		$season_of = Prices::season_per_night( $ctx['seasons'], $nights );
		$rate_of   = Prices::night_rates( $ctx['rates'] );
		foreach ( $ctx['accoms'] as $accom ) {
			foreach ( $nights as $night ) {
				$rate = $rate_of[ $accom ][ $season_of[ $night ] ] ?? null;
				if ( null !== $rate ) {
					$out[ $accom ][ $night ] = $rate;
				}
			}
		}
		return $out;
	}

	/**
	 * The summary stored for the Overview.
	 *
	 * @param array<string, mixed> $ctx    SyncRunner::context().
	 * @param array<string, mixed> $prices PricePlanner result.
	 * @param array<string, mixed> $blocks BlockPlanner result.
	 * @param array<string, mixed> $brake  Brake result.
	 * @return array<string, mixed>
	 */
	public static function build( array $ctx, array $prices, array $blocks, array $brake ): array {
		$now     = self::prices_now( $ctx, $ctx['nights'] );
		$changes = array();
		foreach ( $ctx['accom_room'] as $accom => $room ) {
			foreach ( $ctx['nights'] as $night ) {
				$old = $now[ $accom ][ $night ] ?? '';
				$new = $prices['prices'][ $accom ][ $night ] ?? '';
				if ( $old !== $new ) {
					$key                         = $accom . '|' . $old . '|' . $new;
					$changes[ $key ]['accom']    = $accom;
					$changes[ $key ]['old']      = $old;
					$changes[ $key ]['new']      = $new;
					$changes[ $key ]['nights'][] = $night;
				}
			}
		}
		$price_lines = array();
		foreach ( $changes as $c ) {
			$price_lines[] = array(
				'room'   => self::name( $ctx, (int) $c['accom'] ),
				'old'    => $c['old'],
				'new'    => $c['new'],
				'nights' => count( $c['nights'] ),
				'first'  => $c['nights'][0],
			);
		}

		$own_now = array();
		foreach ( $ctx['rows']['blocks'] as $b ) {
			if ( Occupancy::is_own_block( $b ) ) {
				foreach ( Occupancy::block_nights( $b, $ctx['today'], Clock::add_days( $ctx['today'], count( $ctx['nights'] ) ) ) as $n ) {
					$own_now[ $b['accom_id'] . ':' . $b['accom_num'] ][ $n ] = true;
				}
			}
		}
		$close_lines = array();
		$opened      = 0;
		foreach ( $blocks['wanted'] as $unit => $list ) {
			$new = array_values( array_filter( $list, static fn( string $n ): bool => ! isset( $own_now[ $unit ][ $n ] ) ) );
			if ( array() !== $new ) {
				$close_lines[] = array(
					'room'   => self::unit_name( $ctx, $unit ),
					'nights' => count( $new ),
					'first'  => $new[0],
				);
			}
		}
		foreach ( $own_now as $unit => $set ) {
			$opened += count( array_diff( array_keys( $set ), $blocks['wanted'][ $unit ] ?? array() ) );
		}

		$mismatch_lines = array();
		foreach ( $blocks['mismatches'] as $m ) {
			$mismatch_lines[] = array(
				'night'      => $m['night'],
				'room'       => (string) $m['room'],
				'missing'    => $m['missing'],
				'candidates' => $m['candidates'],
			);
		}

		return array(
			'at'             => time(),
			'from'           => $ctx['nights'][0] ?? '',
			'to'             => $ctx['nights'][ count( $ctx['nights'] ) - 1 ] ?? '',
			'nights'         => count( $ctx['nights'] ),
			'seasons'        => count( $prices['seasons'] ),
			'rate_rows'      => array_sum( array_map( static fn( array $s ): int => count( $s['rates'] ), $prices['seasons'] ) ),
			'prices'         => array_slice( $price_lines, 0, self::MAX_LINES ),
			'prices_total'   => count( $price_lines ),
			'closes'         => array_slice( $close_lines, 0, self::MAX_LINES ),
			'opens'          => $opened,
			'mismatches'     => array_slice( $mismatch_lines, 0, self::MAX_LINES ),
			'mismatch_total' => count( $mismatch_lines ),
			'foreign_high'   => $prices['foreign_high'],
			'no_season'      => count( $prices['no_season'] ),
			'brake'          => $brake,
		);
	}

	/**
	 * An accommodation's short name.
	 *
	 * @param array<string, mixed> $ctx   context().
	 * @param int                  $accom Accommodation id.
	 */
	private static function name( array $ctx, int $accom ): string {
		return (string) ( $ctx['units'][ $accom ]['name'] ?? ( '#' . $accom ) );
	}

	/**
	 * "Name" or "Name · unit" for a unit key.
	 *
	 * @param array<string, mixed> $ctx  context().
	 * @param string               $unit "accom:num".
	 */
	private static function unit_name( array $ctx, string $unit ): string {
		list( $accom, $num ) = array_map( 'intval', explode( ':', $unit ) );
		$units               = $ctx['units'][ $accom ]['units'] ?? array();
		return self::name( $ctx, $accom ) . ( count( $units ) > 1 ? ' · ' . $num : '' );
	}
}

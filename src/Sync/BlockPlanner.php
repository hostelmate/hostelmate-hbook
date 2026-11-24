<?php
/**
 * Wanted plugin blocks, linked-room blocks and mismatches (plan 6.3, 6.4). Pure.
 *
 * For each HostelMate room and night: HostelMate says how many of its units may be sold;
 * HBook says which of the matched units are already taken by something else; the plugin
 * blocks the difference, keeping the units it already blocks so nothing jumps around.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\HBook\Occupancy;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type HmRates from \HostelMate\HBook\Types
 * @phpstan-import-type Rows from \HostelMate\HBook\Types
 * @phpstan-import-type BlockPlan from \HostelMate\HBook\Types
 */
final class BlockPlanner {

	/**
	 * @param list<string>                                                      $nights      Horizon nights.
	 * @param array<string, list<string>>                                       $room_units  HostelMate room id → matched unit keys "accom:num", in mapping order.
	 * @param HmRates                                                           $hm          HostelMate room id → night → answer.
	 * @param Rows                                                              $rows        HBook\Occupancy::read().
	 * @param bool                                                              $paid_needs  HBook's hb_resa_paid_has_confirmation.
	 * @param array<string, list<string>>                                       $hybrids     Parent room id → child room ids.
	 * @return BlockPlan
	 */
	public static function plan( array $nights, array $room_units, array $hm, array $rows, bool $paid_needs, array $hybrids ): array {
		$from                          = $nights[0] ?? '';
		$to                            = array() === $nights ? '' : \HostelMate\HBook\Core\Clock::add_days( $nights[ count( $nights ) - 1 ], 1 );
		list( $taken, $counted, $own ) = self::read_rows( $rows, $from, $to, $paid_needs );

		$wanted     = array();
		$mismatches = array();
		foreach ( $room_units as $room => $units ) {
			foreach ( $nights as $night ) {
				$answer = $hm[ $room ][ $night ] ?? array(
					'available' => 0,
					'closed'    => true,
				);
				$target = $answer['closed'] ? 0 : max( 0, min( count( $units ), $answer['available'] ) );
				$free   = array_values( array_filter( $units, static fn( string $u ): bool => ! isset( $taken[ $u ][ $night ] ) ) );
				$need   = max( 0, count( $free ) - $target );

				// Keep units already blocked by the plugin first, then the last units in mapping order.
				$order = $free;
				usort(
					$order,
					static function ( string $a, string $b ) use ( $own, $night, $free ): int {
						$a_own = isset( $own[ $a ][ $night ] ) ? 0 : 1;
						$b_own = isset( $own[ $b ][ $night ] ) ? 0 : 1;
						if ( $a_own !== $b_own ) {
							return $a_own <=> $b_own;
						}
						return array_search( $b, $free, true ) <=> array_search( $a, $free, true );
					}
				);
				foreach ( array_slice( $order, 0, $need ) as $unit ) {
					$wanted[ $unit ][ $night ] = true;
				}

				$missing = $target - count( $free );
				if ( $missing > 0 ) {
					$candidates = array();
					foreach ( $units as $unit ) {
						foreach ( $counted[ $unit ][ $night ] ?? array() as $ref ) {
							$candidates[] = $ref;
						}
					}
					if ( array() !== $candidates ) {
						$mismatches[] = array(
							'room'       => $room,
							'night'      => $night,
							'missing'    => $missing,
							'candidates' => array_values( array_unique( $candidates ) ),
						);
					}
				}
			}
		}

		foreach ( self::linked( $nights, $room_units, $hybrids, $taken ) as $unit => $by_night ) {
			foreach ( $by_night as $night => $true ) {
				$wanted[ $unit ][ $night ] = true;
			}
		}

		$out = array();
		foreach ( $wanted as $unit => $by_night ) {
			$list = array_keys( $by_night );
			sort( $list );
			$out[ $unit ] = $list;
		}
		ksort( $out );
		return array(
			'wanted'     => $out,
			'mismatches' => $mismatches,
		);
	}

	/**
	 * A whole room and its beds block each other inside HBook: anything on a child takes the
	 * parent; anything on the parent takes the children.
	 *
	 * @param list<string>                         $nights     Nights.
	 * @param array<string, list<string>>          $room_units Room → unit keys.
	 * @param array<string, list<string>>          $hybrids    Parent room → child rooms.
	 * @param array<string, array<string, true>>   $taken      Unit → night → taken by something else.
	 * @return array<string, array<string, true>>
	 */
	private static function linked( array $nights, array $room_units, array $hybrids, array $taken ): array {
		$out = array();
		foreach ( $hybrids as $parent => $children ) {
			$parent_units = $room_units[ $parent ] ?? array();
			$child_units  = array();
			foreach ( $children as $child ) {
				$child_units = array_merge( $child_units, $room_units[ $child ] ?? array() );
			}
			foreach ( $nights as $night ) {
				$child_taken  = array() !== array_filter( $child_units, static fn( string $u ): bool => isset( $taken[ $u ][ $night ] ) );
				$parent_taken = array() !== array_filter( $parent_units, static fn( string $u ): bool => isset( $taken[ $u ][ $night ] ) );
				foreach ( $child_taken ? $parent_units : array() as $u ) {
					if ( ! isset( $taken[ $u ][ $night ] ) ) {
						$out[ $u ][ $night ] = true;
					}
				}
				foreach ( $parent_taken ? $child_units : array() as $u ) {
					if ( ! isset( $taken[ $u ][ $night ] ) ) {
						$out[ $u ][ $night ] = true;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Splits HBook's rows into: taken by anything but the plugin; the subset that HostelMate should
	 * know about (reservations and manual blocks, as references "resa:id" / "block:id"); and the
	 * plugin's own current blocks.
	 *
	 * @param Rows   $rows       Rows.
	 * @param string $from       First night.
	 * @param string $to         Day after the last night.
	 * @param bool   $paid_needs HBook option.
	 * @return array{0: array<string, array<string, true>>, 1: array<string, array<string, list<string>>>, 2: array<string, array<string, true>>}
	 */
	private static function read_rows( array $rows, string $from, string $to, bool $paid_needs ): array {
		$taken   = array();
		$counted = array();
		$own     = array();
		if ( '' === $from ) {
			return array( $taken, $counted, $own );
		}
		foreach ( $rows['reservations'] as $r ) {
			if ( ! Occupancy::holds_unit( (string) $r['status'], $paid_needs ) ) {
				continue;
			}
			$unit = $r['accom_id'] . ':' . $r['accom_num'];
			foreach ( Occupancy::resa_nights( $r, $from, $to ) as $night ) {
				$taken[ $unit ][ $night ]     = true;
				$counted[ $unit ][ $night ][] = 'resa:' . $r['id'];
			}
		}
		foreach ( $rows['blocks'] as $b ) {
			$unit = $b['accom_id'] . ':' . $b['accom_num'];
			$mine = Occupancy::is_own_block( $b );
			foreach ( Occupancy::block_nights( $b, $from, $to ) as $night ) {
				if ( $mine ) {
					$own[ $unit ][ $night ] = true;
					continue;
				}
				$taken[ $unit ][ $night ] = true;
				// Preparation time and HBook's own linked-room blocks are HBook-only rules: not mismatches.
				if ( ! $b['is_prepa_time'] && 0 === (int) $b['linked_resa_id'] ) {
					$counted[ $unit ][ $night ][] = 'block:' . $b['id'];
				}
			}
		}
		return array( $taken, $counted, $own );
	}
}

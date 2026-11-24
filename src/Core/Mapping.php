<?php
/**
 * Which HBook unit is which HostelMate room/bed. Stored as
 *   rooms: { "<HostelMate room id>": { units: [ {accom, num, bed}, ... ] } }
 * and checked against the last /property answer and HBook's units on save and on every read.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Mapping {

	public const LEVEL_ERROR = 'error';
	public const LEVEL_WARN  = 'warning';

	/**
	 * The stored mapping, shape-checked (wrong entries are dropped, never guessed).
	 *
	 * @return array<string, list<array{accom: int, num: int, bed: string}>> HostelMate room id → units.
	 */
	public static function get(): array {
		$stored = get_option( Config::OPT_MAPPING, array() );
		return self::normalise( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Saves a mapping only when it has no errors (warnings are allowed).
	 *
	 * @param array<string, list<array{accom: int, num: int, bed: string}>> $mapping  HostelMate room id → units.
	 * @param array<string, mixed>                                          $property Normalised /property answer.
	 * @param array<int, array{units: list<int>}>                           $units    HBook\Units::all().
	 * @return list<array{level: string, code: string, ref: string}> Problems found (nothing saved if any is an error).
	 */
	public static function save( array $mapping, array $property, array $units ): array {
		$mapping  = self::normalise( array( 'rooms' => $mapping ) );
		$problems = self::problems( $mapping, $property, $units );
		foreach ( $problems as $p ) {
			if ( self::LEVEL_ERROR === $p['level'] ) {
				return $problems;
			}
		}
		update_option( Config::OPT_MAPPING, array( 'rooms' => $mapping ), false );
		return $problems;
	}

	/**
	 * Every way a mapping breaks the rules (plan 5.4) or HostelMate's setup rules (plan 12).
	 * Pure: works on the given data only.
	 *
	 * @param array<string, list<array{accom: int, num: int, bed: string}>> $mapping  HostelMate room id → units.
	 * @param array<string, mixed>                                          $property Normalised /property answer.
	 * @param array<int, array{units: list<int>}>                           $units    HBook\Units::all().
	 * @return list<array{level: string, code: string, ref: string}>
	 */
	public static function problems( array $mapping, array $property, array $units ): array {
		$rooms = array();
		foreach ( (array) ( $property['rooms'] ?? array() ) as $room ) {
			if ( is_array( $room ) && is_string( $room['id'] ?? null ) ) {
				$rooms[ $room['id'] ] = $room;
			}
		}
		$out        = array();
		$beds_used  = array();
		$units_used = array();
		$accom_room = array();

		foreach ( $mapping as $room_id => $list ) {
			$room = $rooms[ $room_id ] ?? null;
			if ( null === $room ) {
				$out[] = self::issue( self::LEVEL_ERROR, Messages::MAP_UNKNOWN_ROOM, $room_id );
				continue;
			}
			$bed_ids = array_column( (array) $room['beds'], 'id' );
			foreach ( $list as $unit ) {
				$key = $unit['accom'] . ':' . $unit['num'];
				if ( ! isset( $units[ $unit['accom'] ] ) || ! in_array( $unit['num'], $units[ $unit['accom'] ]['units'], true ) ) {
					$out[] = self::issue( self::LEVEL_ERROR, Messages::MAP_UNKNOWN_UNIT, $key );
					continue;
				}
				if ( isset( $units_used[ $key ] ) ) {
					$out[] = self::issue( self::LEVEL_ERROR, Messages::MAP_UNIT_TWICE, $key );
				}
				$units_used[ $key ] = true;

				if ( isset( $accom_room[ $unit['accom'] ] ) && $accom_room[ $unit['accom'] ] !== $room_id ) {
					$out[] = self::issue( self::LEVEL_ERROR, Messages::MAP_SPLIT_ACCOM, (string) $unit['accom'] );
				}
				$accom_room[ $unit['accom'] ] = $room_id;

				if ( 'hybrid' === $room['sell_mode'] ) {
					if ( '' !== $unit['bed'] ) {
						$out[] = self::issue( self::LEVEL_ERROR, Messages::MAP_WHOLE_ROOM_NO_BED, $key );
					}
					continue;
				}
				if ( '' === $unit['bed'] ) {
					$out[] = self::issue( self::LEVEL_ERROR, Messages::MAP_BED_REQUIRED, $key );
				} elseif ( ! in_array( $unit['bed'], $bed_ids, true ) ) {
					$out[] = self::issue( self::LEVEL_ERROR, Messages::MAP_UNKNOWN_BED, $key );
				} elseif ( isset( $beds_used[ $unit['bed'] ] ) ) {
					$out[] = self::issue( self::LEVEL_ERROR, Messages::MAP_BED_TWICE, $key );
				} else {
					$beds_used[ $unit['bed'] ] = true;
				}
			}
			if ( array() !== $list ) {
				$out = array_merge( $out, self::room_warnings( $room ) );
			}
		}
		return $out;
	}

	/**
	 * HostelMate setup rules a matched room breaks (plan section 12): warnings, not errors.
	 *
	 * @param array<string, mixed> $room One normalised /property room.
	 * @return list<array{level: string, code: string, ref: string}>
	 */
	private static function room_warnings( array $room ): array {
		$out  = array();
		$name = (string) $room['name'];
		if ( '' === (string) $room['channel_id'] ) {
			$out[] = self::issue( self::LEVEL_WARN, Messages::MAP_NO_CHANNEL_ID, $name );
		}
		// HostelMate stores 0 for "not set" and applies neither number then (backend/api/helpers/availability_calc.py:159-163).
		if ( ! in_array( $room['fake_capacity'], array( null, 0 ), true ) || ! in_array( $room['fake_max_available'], array( null, 0 ), true ) ) {
			$out[] = self::issue( self::LEVEL_WARN, Messages::MAP_FAKE_NUMBERS, $name );
		}
		if ( true !== $room['active'] ) {
			$out[] = self::issue( self::LEVEL_WARN, Messages::MAP_ROOM_OFF, $name );
		}
		return $out;
	}

	/**
	 * The Rooms form → a mapping. Field name "accom:num", value "roomId|bedId" (bed empty for a
	 * whole room) or "" (not matched). Anything malformed is dropped.
	 *
	 * @param mixed $form $_POST['mapping'] (unslashed).
	 * @return array<string, list<array{accom: int, num: int, bed: string}>>
	 */
	public static function from_form( $form ): array {
		$out = array();
		if ( ! is_array( $form ) ) {
			return $out;
		}
		foreach ( $form as $unit_key => $value ) {
			if ( ! is_string( $unit_key ) || ! is_string( $value ) || '' === $value
				|| 1 !== preg_match( '/^(\d{1,18}):(\d{1,6})\z/', $unit_key, $u )
				|| 1 !== preg_match( '/^([0-9a-f-]{36})\|([0-9a-f-]{36})?\z/i', $value, $v )
				|| ! Validate::is_uuid( $v[1] ) || ( isset( $v[2] ) && ! Validate::is_uuid( $v[2] ) ) ) {
				continue;
			}
			$out[ strtolower( $v[1] ) ][] = array(
				'accom' => (int) $u[1],
				'num'   => (int) $u[2],
				'bed'   => strtolower( $v[2] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * A first guess by name, for an empty mapping: a unit whose name (or one of its words)
	 * equals a bed name goes to that bed; otherwise one whose name or word equals a room name goes
	 * to that room's first free bed (or the whole room for a hybrid parent). Only unique matches
	 * are used, and whole words only ("N°62" never matches bed "N°6").
	 * Pure.
	 *
	 * @param array<string, mixed>                                  $property Normalised /property answer.
	 * @param array<int, array{name: string, units: list<int>}>     $units    HBook\Units::all().
	 * @return array<string, string> "accom:num" → "roomId|bedId".
	 */
	public static function suggest( array $property, array $units ): array {
		$by_bed  = array();
		$by_room = array();
		foreach ( (array) ( $property['rooms'] ?? array() ) as $room ) {
			$room_key = self::fold( (string) $room['name'] );
			if ( 'hybrid' === $room['sell_mode'] ) {
				$by_room[ $room_key ][] = $room['id'] . '|';
				continue;
			}
			foreach ( (array) $room['beds'] as $bed ) {
				$by_room[ $room_key ][] = $room['id'] . '|' . $bed['id'];
				if ( '' !== $bed['name'] ) {
					$by_bed[ self::fold( (string) $bed['name'] ) ][] = $room['id'] . '|' . $bed['id'];
				}
			}
		}
		$out   = array();
		$taken = array();
		foreach ( $units as $accom_id => $accom ) {
			$words = self::words( $accom['name'] );
			foreach ( $accom['units'] as $num ) {
				$choice = null;
				foreach ( $words as $word ) {
					$targets = $by_bed[ $word ] ?? array();
					if ( 1 === count( $targets ) && ! isset( $taken[ $targets[0] ] ) ) {
						$choice = ( null === $choice || $choice === $targets[0] ) ? $targets[0] : false; // Two different beds fit: no guess.
					}
				}
				if ( null === $choice ) {
					foreach ( $words as $word ) {
						foreach ( $by_room[ $word ] ?? array() as $target ) {
							if ( ! isset( $taken[ $target ] ) ) {
								$choice = $target;
								break 2;
							}
						}
					}
				}
				if ( is_string( $choice ) ) {
					$out[ $accom_id . ':' . $num ] = $choice;
					$taken[ $choice ]              = true;
				}
			}
		}
		return $out;
	}

	/**
	 * The folded whole name, then its words with a number, then the others ("Garden, N°65" → gardenn65, n65, garden).
	 *
	 * @param string $name Any name.
	 * @return list<string>
	 */
	private static function words( string $name ): array {
		$split   = preg_split( '/[\s\p{Z},;|()\/]+/u', $name );
		$parts   = false === $split ? array() : $split;
		$numbers = array();
		$others  = array();
		foreach ( $parts as $part ) {
			$word = self::fold( $part );
			if ( 1 === preg_match( '/\d/', $word ) ) {
				$numbers[] = $word;
			} else {
				$others[] = $word;
			}
		}
		// Whole name first, then words with a number (they usually name a bed), then the rest.
		$words = array_merge( array( self::fold( $name ) ), $numbers, $others );
		return array_values( array_unique( array_filter( $words, static fn( string $w ): bool => '' !== $w ) ) );
	}

	/**
	 * Lowercase, no accents, letters and digits only ("N°74 " → "n74", "Café" → "cafe").
	 *
	 * @param string $text Any name.
	 */
	private static function fold( string $text ): string {
		$plain = remove_accents( $text );
		return preg_replace( '/[^a-z0-9]+/', '', strtolower( $plain ) ) ?? '';
	}

	/**
	 * Keeps only well-formed entries: room ids are UUIDs, units are positive integers, beds are '' or UUIDs.
	 *
	 * @param array<mixed> $stored Raw option value.
	 * @return array<string, list<array{accom: int, num: int, bed: string}>>
	 */
	private static function normalise( array $stored ): array {
		$out = array();
		foreach ( (array) ( $stored['rooms'] ?? array() ) as $room_id => $list ) {
			if ( ! Validate::is_uuid( $room_id ) || ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $unit ) {
				if ( ! is_array( $unit ) || ! Validate::is_int_between( $unit['accom'] ?? null, 1, PHP_INT_MAX )
					|| ! Validate::is_int_between( $unit['num'] ?? null, 0, 100000 )
					|| ! ( '' === ( $unit['bed'] ?? null ) || Validate::is_uuid( $unit['bed'] ?? null ) ) ) {
					continue;
				}
				$out[ strtolower( $room_id ) ][] = array(
					'accom' => $unit['accom'],
					'num'   => $unit['num'],
					'bed'   => strtolower( $unit['bed'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * One problem.
	 *
	 * @param string $level LEVEL_*.
	 * @param string $code  Messages::MAP_*.
	 * @param string $ref   What it is about (unit "accom:num", accommodation id, room name).
	 * @return array{level: string, code: string, ref: string}
	 */
	private static function issue( string $level, string $code, string $ref ): array {
		return array(
			'level' => $level,
			'code'  => $code,
			'ref'   => $ref,
		);
	}
}

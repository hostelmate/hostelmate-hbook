<?php
/**
 * The exact shape of every HostelMate answer the plugin uses, checked field by field.
 * Anything unexpected makes the whole answer invalid (null): the plugin never acts
 * on half-understood data. Shapes come from the code:
 *   GET /property  → backend/api/client/views/property.py:20-83
 *   GET /rates     → backend/api/client/services/rates.py:61-96
 *   errors         → backend/api/client/exceptions.py ({"code", "message"})
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HostelMate;

use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Validate;

defined( 'ABSPATH' ) || exit;

final class Schemas {

	public const SELL_MODES = array( 'per_person', 'per_room', 'hybrid' );

	private const MAX_ROOMS     = 500;
	private const MAX_BEDS      = 1000;
	private const MAX_NAME      = 200;
	private const MAX_PRICE     = 999999999;
	private const MAX_AVAILABLE = 10000;
	private const MAX_STAY      = 10000;

	/**
	 * HostelMate's error code from an error body; 'invalid_<field>' for a field refusal
	 * ({"channel": ["…"]}, the client API serializers' own shape); 'unknown' otherwise.
	 *
	 * @param array<mixed> $data Decoded body.
	 */
	public static function error_code( array $data ): string {
		$code = $data['code'] ?? null;
		if ( is_string( $code ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}\z/', $code ) ) {
			return $code;
		}
		$field = array_key_first( $data );
		if ( null === $code && is_string( $field ) && 1 === preg_match( '/^[a-z][a-z0-9_]{0,55}\z/', $field ) && is_array( $data[ $field ] ) ) {
			return 'invalid_' . $field;
		}
		return 'unknown';
	}

	/**
	 * GET /property, normalised to what the plugin uses.
	 *
	 * @param array<mixed> $data Decoded body.
	 * @return array{id: string, name: string, currency: string, timezone: string, rooms: list<array{id: string, name: string, active: bool, sell_mode: string, sell_count: int|null, hybrid_parent_id: string|null, channel_id: string, fake_capacity: int|null, fake_max_available: int|null, beds: list<array{id: string, name: string}>}>}|null
	 */
	public static function property( array $data ): ?array {
		$p     = $data['property'] ?? null;
		$rooms = $data['rooms'] ?? null;
		if ( ! is_array( $p ) || ! is_array( $rooms ) || ! array_is_list( $rooms ) || count( $rooms ) > self::MAX_ROOMS ) {
			return null;
		}
		if ( ! Validate::is_uuid( $p['id'] ?? null ) || ! Validate::is_text( $p['name'] ?? null, self::MAX_NAME )
			|| ! self::is_currency( $p['currency'] ?? null ) || ! self::is_zone_or_empty( $p['timezone'] ?? null ) ) {
			return null;
		}
		$out_rooms = array();
		foreach ( $rooms as $room ) {
			$normal = is_array( $room ) ? self::property_room( $room ) : null;
			if ( null === $normal ) {
				return null;
			}
			$out_rooms[] = $normal;
		}
		return array(
			'id'       => strtolower( $p['id'] ),
			'name'     => $p['name'],
			'currency' => strtoupper( $p['currency'] ),
			'timezone' => $p['timezone'],
			'rooms'    => $out_rooms,
		);
	}

	/**
	 * GET /rates for [$start, $end] (both included), normalised. Every room must list
	 * exactly those nights, in order.
	 *
	 * @param array<mixed> $data  Decoded body.
	 * @param string       $start First night asked for.
	 * @param string       $end   Last night asked for.
	 * @return array{property_id: string, currency: string, rooms: array<string, array{name: string, sell_mode: string, nights: array<string, array{price: int|null, closed: bool, available: int, min_stay: int|null, max_stay: int|null, cta: bool, ctd: bool}>}>}|null
	 */
	public static function rates( array $data, string $start, string $end ): ?array {
		$rooms = $data['rooms'] ?? null;
		if ( ! Validate::is_uuid( $data['property_id'] ?? null ) || ! self::is_currency( $data['currency'] ?? null )
			|| ( $data['start_date'] ?? null ) !== $start || ( $data['end_date'] ?? null ) !== $end
			|| ! is_array( $rooms ) || ! array_is_list( $rooms ) || count( $rooms ) > self::MAX_ROOMS ) {
			return null;
		}
		$expected  = Clock::nights( $start, Clock::add_days( $end, 1 ) );
		$out_rooms = array();
		foreach ( $rooms as $room ) {
			if ( ! is_array( $room ) || ! Validate::is_uuid( $room['room_id'] ?? null ) || ! Validate::is_text( $room['name'] ?? null, self::MAX_NAME )
				|| ! Validate::is_one_of( $room['sell_mode'] ?? null, self::SELL_MODES )
				|| ! is_array( $room['nights'] ?? null ) || count( $room['nights'] ) !== count( $expected ) ) {
				return null;
			}
			$nights = array();
			foreach ( array_values( $room['nights'] ) as $i => $night ) {
				$normal = is_array( $night ) ? self::rate_night( $night, $expected[ $i ] ) : null;
				if ( null === $normal ) {
					return null;
				}
				$nights[ $expected[ $i ] ] = $normal;
			}
			$id = strtolower( $room['room_id'] );
			if ( isset( $out_rooms[ $id ] ) ) {
				return null; // The same room twice: not an answer we understand.
			}
			$out_rooms[ $id ] = array(
				'name'      => $room['name'],
				'sell_mode' => $room['sell_mode'],
				'nights'    => $nights,
			);
		}
		return array(
			'property_id' => strtolower( $data['property_id'] ),
			'currency'    => strtoupper( $data['currency'] ),
			'rooms'       => $out_rooms,
		);
	}

	/**
	 * One room of GET /property.
	 *
	 * @param array<mixed> $room Raw room.
	 * @return array{id: string, name: string, active: bool, sell_mode: string, sell_count: int|null, hybrid_parent_id: string|null, channel_id: string, fake_capacity: int|null, fake_max_available: int|null, beds: list<array{id: string, name: string}>}|null
	 */
	private static function property_room( array $room ): ?array {
		$beds = $room['beds'] ?? null;
		if ( ! Validate::is_uuid( $room['id'] ?? null ) || ! Validate::is_text( $room['name'] ?? null, self::MAX_NAME )
			|| ! is_bool( $room['status'] ?? null ) || ! Validate::is_one_of( $room['room_sell'] ?? null, self::SELL_MODES )
			|| ! self::is_int_or_null( $room['room_sell_count'] ?? null, 0, 1000 )
			|| ! ( null === ( $room['hybrid_parent_id'] ?? null ) || Validate::is_uuid( $room['hybrid_parent_id'] ) )
			|| ! is_string( $room['channel_manager_id'] ?? null ) || strlen( $room['channel_manager_id'] ) > 64
			|| ! self::is_int_or_null( $room['capacity'] ?? null, -100000, 100000 )
			|| ! self::is_int_or_null( $room['max_available_beds'] ?? null, -100000, 100000 )
			|| ! is_array( $beds ) || ! array_is_list( $beds ) || count( $beds ) > self::MAX_BEDS ) {
			return null;
		}
		$out_beds = array();
		foreach ( $beds as $bed ) {
			$name = is_array( $bed ) ? ( $bed['name'] ?? '' ) : null;
			if ( ! is_array( $bed ) || ! Validate::is_uuid( $bed['id'] ?? null ) || ! ( '' === $name || null === $name || Validate::is_text( $name, self::MAX_NAME ) ) ) {
				return null;
			}
			$out_beds[] = array(
				'id'   => strtolower( $bed['id'] ),
				'name' => (string) $name,
			);
		}
		return array(
			'id'                 => strtolower( $room['id'] ),
			'name'               => $room['name'],
			'active'             => $room['status'],
			'sell_mode'          => $room['room_sell'],
			'sell_count'         => $room['room_sell_count'] ?? null,
			'hybrid_parent_id'   => null === ( $room['hybrid_parent_id'] ?? null ) ? null : strtolower( $room['hybrid_parent_id'] ),
			'channel_id'         => $room['channel_manager_id'],
			'fake_capacity'      => $room['capacity'] ?? null,
			'fake_max_available' => $room['max_available_beds'] ?? null,
			'beds'               => $out_beds,
		);
	}

	/**
	 * One night of GET /rates.
	 *
	 * @param array<mixed> $night    Raw night.
	 * @param string       $expected The date this position must hold.
	 * @return array{price: int|null, closed: bool, available: int, min_stay: int|null, max_stay: int|null, cta: bool, ctd: bool}|null
	 */
	private static function rate_night( array $night, string $expected ): ?array {
		if ( ( $night['date'] ?? null ) !== $expected
			|| ! self::is_int_or_null( $night['price'] ?? null, 0, self::MAX_PRICE )
			|| ! is_bool( $night['closed'] ?? null )
			|| ! Validate::is_int_between( $night['available'] ?? null, 0, self::MAX_AVAILABLE )
			|| ! self::is_int_or_null( $night['min_stay'] ?? null, 0, self::MAX_STAY )
			|| ! self::is_int_or_null( $night['max_stay'] ?? null, 0, self::MAX_STAY )
			|| ! self::is_bool_or_null( $night['closed_to_arrival'] ?? null )
			|| ! self::is_bool_or_null( $night['closed_to_departure'] ?? null ) ) {
			return null;
		}
		return array(
			'price'     => $night['price'] ?? null,
			'closed'    => $night['closed'],
			'available' => $night['available'],
			'min_stay'  => $night['min_stay'] ?? null,
			'max_stay'  => $night['max_stay'] ?? null,
			'cta'       => true === ( $night['closed_to_arrival'] ?? null ),
			'ctd'       => true === ( $night['closed_to_departure'] ?? null ),
		);
	}

	/**
	 * Three letters (HostelMate stores codes in either case).
	 *
	 * @param mixed $value Anything.
	 */
	private static function is_currency( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[A-Za-z]{3}\z/', $value );
	}

	/**
	 * '' (not set) or an IANA zone name.
	 *
	 * @param mixed $value Anything.
	 */
	private static function is_zone_or_empty( $value ): bool {
		return '' === $value || ( is_string( $value ) && in_array( $value, \DateTimeZone::listIdentifiers(), true ) );
	}

	/**
	 * Null, or an integer in range.
	 *
	 * @param mixed $value Anything.
	 * @param int   $min   Smallest allowed.
	 * @param int   $max   Largest allowed.
	 */
	private static function is_int_or_null( $value, int $min, int $max ): bool {
		return null === $value || Validate::is_int_between( $value, $min, $max );
	}

	/**
	 * Null or a boolean.
	 *
	 * @param mixed $value Anything.
	 */
	private static function is_bool_or_null( $value ): bool {
		return null === $value || is_bool( $value );
	}

	/**
	 * POST /bookings → {bookingId, guest.id}. (backend/api/client/services/bookings.py:410-424)
	 *
	 * @param array<mixed> $data Decoded body.
	 * @return array{booking_id: string, guest_id: string}|null
	 */
	public static function booking_created( array $data ): ?array {
		$guest = $data['guest'] ?? null;
		$gid   = is_array( $guest ) ? ( $guest['id'] ?? '' ) : '';
		if ( ! Validate::is_uuid( $data['bookingId'] ?? null ) || ! ( '' === $gid || Validate::is_uuid( $gid ) ) ) {
			return null;
		}
		return array(
			'booking_id' => strtolower( $data['bookingId'] ),
			'guest_id'   => strtolower( (string) $gid ),
		);
	}

	/**
	 * GET /bookings/{id} → id, status, guest id. (serializers/booking.py BookingDetailResponseSerializer)
	 *
	 * @param array<mixed> $data Decoded body.
	 * @return array{booking_id: string, status: string, guest_id: string}|null
	 */
	public static function booking( array $data ): ?array {
		$guest = $data['guest'] ?? null;
		$gid   = is_array( $guest ) ? ( $guest['id'] ?? '' ) : '';
		if ( ! Validate::is_uuid( $data['id'] ?? null ) || ! is_string( $data['status'] ?? null ) || ! ( '' === $gid || Validate::is_uuid( $gid ) ) ) {
			return null;
		}
		return array(
			'booking_id' => strtolower( $data['id'] ),
			'status'     => $data['status'],
			'guest_id'   => strtolower( (string) $gid ),
		);
	}

	/**
	 * GET /payments?booking_id= → list of {id, name}. (serializers/payment.py PaymentSerializer)
	 *
	 * @param array<mixed> $data Decoded body (a JSON list).
	 * @return list<array{id: string, name: string}>|null
	 */
	public static function payments( array $data ): ?array {
		if ( ! array_is_list( $data ) || count( $data ) > 10000 ) {
			return null;
		}
		$out = array();
		foreach ( $data as $p ) {
			if ( ! is_array( $p ) || ! Validate::is_uuid( $p['id'] ?? null ) ) {
				return null;
			}
			$out[] = array(
				'id'   => strtolower( $p['id'] ),
				'name' => is_string( $p['name'] ?? null ) ? $p['name'] : '',
			);
		}
		return $out;
	}

	/**
	 * POST /payments → {id}.
	 *
	 * @param array<mixed> $data Decoded body.
	 * @return array{id: string}|null
	 */
	public static function payment_created( array $data ): ?array {
		return Validate::is_uuid( $data['id'] ?? null ) ? array( 'id' => strtolower( $data['id'] ) ) : null;
	}

	/**
	 * Any JSON object (answers the plugin only needs to be 2xx).
	 *
	 * @param array<mixed> $data Decoded body.
	 * @return array<mixed>
	 */
	public static function any( array $data ): array {
		return $data;
	}

	/**
	 * GET /availability → per room: bookable, restricted, fewest free beds over the stay.
	 * (backend/api/client/services/availability.py:111-131)
	 *
	 * @param array<mixed> $data   Decoded body.
	 * @param int          $nights Nights in the stay asked about (every room must list them all).
	 * @return array<string, array{bookable: bool, restricted: bool, min_free: int}>|null
	 */
	public static function availability( array $data, int $nights ): ?array {
		$rooms = $data['rooms'] ?? null;
		if ( ! is_array( $rooms ) || ! array_is_list( $rooms ) || count( $rooms ) > self::MAX_ROOMS ) {
			return null;
		}
		$out = array();
		foreach ( $rooms as $room ) {
			if ( ! is_array( $room ) || ! Validate::is_uuid( $room['room_id'] ?? null ) || ! is_bool( $room['bookable'] ?? null )
				|| ! is_array( $room['nights'] ?? null ) || count( $room['nights'] ) > $nights
				|| ! ( null === ( $room['restriction'] ?? null ) || is_array( $room['restriction'] ) )
				|| isset( $out[ strtolower( $room['room_id'] ) ] ) ) {
				return null;
			}
			$free = PHP_INT_MAX;
			foreach ( $room['nights'] as $night ) {
				if ( ! is_array( $night ) || ! Validate::is_int_between( $night['available_beds'] ?? null, 0, self::MAX_AVAILABLE ) ) {
					return null;
				}
				$free = min( $free, $night['available_beds'] );
			}
			$out[ strtolower( $room['room_id'] ) ] = array(
				'bookable'   => $room['bookable'],
				'restricted' => null !== ( $room['restriction'] ?? null ),
				'min_free'   => PHP_INT_MAX === $free ? 0 : $free,
			);
		}
		return $out;
	}
}

<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Core\HttpResponse;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\HostelMate\Client;
use HostelMate\HBook\HostelMate\Result;
use HostelMate\HBook\HostelMate\Schemas;
use PHPUnit\Framework\Attributes\DataProvider;

final class HostelMateClientTest extends TestCase {

	private const ROOM = '8a3d2c19-6e5f-4b70-a1d2-93c4e5f60718';

	protected function setUp(): void {
		parent::setUp();
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
	}

	/** @return array<string, mixed> */
	private static function property_fixture(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/property.json' ), true );
	}

	/** @return array<string, mixed> */
	private static function rates_fixture( string $start = '2026-12-03', int $nights = 3 ): array {
		$list = array();
		for ( $i = 0; $i < $nights; $i++ ) {
			$list[] = array(
				'date'                => gmdate( 'Y-m-d', strtotime( $start . ' +' . $i . ' day' ) ),
				'price'               => 30000 + $i,
				'closed'              => false,
				'available'           => 2,
				'min_stay'            => null,
				'max_stay'            => null,
				'closed_to_arrival'   => false,
				'closed_to_departure' => null,
			);
		}
		return array(
			'property_id' => '5f0c7a52-3b1e-4d8a-9c61-2e4b7d90a1f3',
			'currency'    => 'MXN',
			'start_date'  => $start,
			'end_date'    => gmdate( 'Y-m-d', strtotime( $start . ' +' . ( $nights - 1 ) . ' day' ) ),
			'rooms'       => array(
				array(
					'room_id'    => self::ROOM,
					'name'       => 'Mixed dorm',
					'sell_mode'  => 'per_person',
					'sell_count' => null,
					'nights'     => $list,
				),
			),
		);
	}

	/** @param array<string, mixed> $body */
	private static function answer( int $status, array $body ): void {
		\WpStub::$next_response = array(
			'response' => array( 'code' => $status ),
			'body'     => (string) json_encode( $body ),
		);
	}

	public function test_property_is_normalised(): void {
		$p = Schemas::property( self::property_fixture() );
		$this->assertNotNull( $p );
		$this->assertSame( '5f0c7a52-3b1e-4d8a-9c61-2e4b7d90a1f3', $p['id'] );
		$this->assertSame( 'MXN', $p['currency'] );
		$this->assertCount( 2, $p['rooms'] );
		$this->assertSame( 'N°74', $p['rooms'][0]['beds'][0]['name'] );
		$this->assertSame( '', $p['rooms'][0]['beds'][1]['name'] );
		$this->assertSame( '', $p['rooms'][1]['channel_id'] );
		$this->assertSame( 0, $p['rooms'][1]['fake_capacity'] );
		$this->assertFalse( $p['rooms'][1]['active'] );
	}

	/** @return array<string, array{callable(array<string, mixed>): array<string, mixed>}> */
	public static function broken_properties(): array {
		return array(
			'no rooms'       => array( static fn( array $d ): array => array_diff_key( $d, array( 'rooms' => 1 ) ) ),
			'bad id'         => array( static function ( array $d ): array { $d['property']['id'] = '1 OR 1=1'; return $d; } ),
			'bad currency'   => array( static function ( array $d ): array { $d['property']['currency'] = 'PESOS'; return $d; } ),
			'bad zone'       => array( static function ( array $d ): array { $d['property']['timezone'] = 'Mars/Base'; return $d; } ),
			'room html name' => array( static function ( array $d ): array { $d['rooms'][0]['name'] = "Dorm\n<script>"; return $d; } ),
			'string status'  => array( static function ( array $d ): array { $d['rooms'][0]['status'] = 'true'; return $d; } ),
			'unknown sell'   => array( static function ( array $d ): array { $d['rooms'][0]['room_sell'] = 'per_bed'; return $d; } ),
			'bad bed id'     => array( static function ( array $d ): array { $d['rooms'][0]['beds'][0]['id'] = 'x'; return $d; } ),
			'rooms object'   => array( static function ( array $d ): array { $d['rooms'] = array( 'a' => $d['rooms'][0] ); return $d; } ),
		);
	}

	/** @param callable(array<string, mixed>): array<string, mixed> $break */
	#[DataProvider( 'broken_properties' )]
	public function test_any_wrong_property_field_rejects_the_whole_answer( callable $break ): void {
		$this->assertNull( Schemas::property( $break( self::property_fixture() ) ) );
	}

	public function test_rates_are_keyed_by_room_and_night(): void {
		$r = Schemas::rates( self::rates_fixture(), '2026-12-03', '2026-12-05' );
		$this->assertNotNull( $r );
		$this->assertSame( array( '2026-12-03', '2026-12-04', '2026-12-05' ), array_keys( $r['rooms'][ self::ROOM ]['nights'] ) );
		$this->assertSame( 30001, $r['rooms'][ self::ROOM ]['nights']['2026-12-04']['price'] );
		$this->assertFalse( $r['rooms'][ self::ROOM ]['nights']['2026-12-05']['ctd'] );
	}

	/** @return array<string, array{callable(array<string, mixed>): array<string, mixed>}> */
	public static function broken_rates(): array {
		$night = static function ( callable $edit ): callable {
			return static function ( array $d ) use ( $edit ): array {
				$d['rooms'][0]['nights'][1] = $edit( $d['rooms'][0]['nights'][1] );
				return $d;
			};
		};
		return array(
			'missing night'   => array( static function ( array $d ): array { array_pop( $d['rooms'][0]['nights'] ); return $d; } ),
			'extra night'     => array( static function ( array $d ): array { $d['rooms'][0]['nights'][] = $d['rooms'][0]['nights'][0]; return $d; } ),
			'nights shuffled' => array( static function ( array $d ): array { $d['rooms'][0]['nights'] = array_reverse( $d['rooms'][0]['nights'] ); return $d; } ),
			'other range'     => array( static function ( array $d ): array { $d['start_date'] = '2026-12-01'; return $d; } ),
			'price string'    => array( $night( static fn( array $n ): array => array( 'price' => '300.00' ) + $n ) ),
			'price negative'  => array( $night( static fn( array $n ): array => array( 'price' => -1 ) + $n ) ),
			'price huge'      => array( $night( static fn( array $n ): array => array( 'price' => 1000000000 ) + $n ) ),
			'available float' => array( $night( static fn( array $n ): array => array( 'available' => 2.0 ) + $n ) ),
			'available minus' => array( $night( static fn( array $n ): array => array( 'available' => -3 ) + $n ) ),
			'closed missing'  => array( $night( static fn( array $n ): array => array_diff_key( $n, array( 'closed' => 1 ) ) ) ),
			'same room twice' => array( static function ( array $d ): array { $d['rooms'][] = $d['rooms'][0]; return $d; } ),
			'bad currency'    => array( static function ( array $d ): array { $d['currency'] = 'M$N'; return $d; } ),
		);
	}

	/** @param callable(array<string, mixed>): array<string, mixed> $break */
	#[DataProvider( 'broken_rates' )]
	public function test_any_wrong_rates_field_rejects_the_whole_answer( callable $break ): void {
		$this->assertNull( Schemas::rates( $break( self::rates_fixture() ), '2026-12-03', '2026-12-05' ) );
	}

	public function test_client_rates_calls_the_right_range(): void {
		self::answer( 200, self::rates_fixture() );
		$r = Client::rates( '2026-12-03', '2026-12-05' );
		$this->assertTrue( $r->is_ok() );
		$this->assertStringEndsWith( '/rates?start_date=2026-12-03&end_date=2026-12-05', \WpStub::$requests[0]['url'] );
	}

	public function test_client_refuses_ranges_hostelmate_would_refuse(): void {
		$this->assertSame( 'bad_range', Client::rates( '2026-12-05', '2026-12-03' )->code );
		$this->assertSame( 'bad_range', Client::rates( '2026-01-01', '2027-01-02' )->code );
		$this->assertSame( 'bad_dates', Client::rates( '2026-12-5', '2026-12-06' )->code );
		$this->assertCount( 0, \WpStub::$requests );
	}

	/** @return list<array{int, string, string}> */
	public static function statuses(): array {
		return array(
			array( 401, Result::UNAUTHORIZED, 'unauthorized' ),
			array( 403, Result::FORBIDDEN, 'origin_not_allowed' ),
			array( 404, Result::NOT_FOUND, 'not_found' ),
			array( 409, Result::CONFLICT, 'availability_conflict' ),
			array( 429, Result::RATE_LIMITED, 'rate_limited' ),
			array( 400, Result::INVALID, 'bad_request' ),
			array( 503, Result::SERVER, 'messaging_unavailable' ),
		);
	}

	#[DataProvider( 'statuses' )]
	public function test_error_statuses_map_to_kinds( int $status, string $kind, string $code ): void {
		self::answer( $status, array( 'code' => $code, 'message' => 'whatever' ) );
		$r = Client::property();
		$this->assertSame( $kind, $r->kind );
		$this->assertSame( $code, $r->code );
		$this->assertNull( $r->data );
	}

	public function test_odd_error_codes_are_not_trusted(): void {
		self::answer( 400, array( 'code' => "<b>x</b>\n", 'message' => 'x' ) );
		$this->assertSame( 'unknown', Client::property()->code );
	}

	public function test_field_refusals_name_the_field(): void {
		self::answer( 400, array( 'channel' => array( 'not one of this property\'s sources' ) ) );
		$this->assertSame( 'invalid_channel', Client::property()->code );
		self::answer( 400, array( 'Bad Field' => array( 'x' ) ) );
		$this->assertSame( 'unknown', Client::property()->code );
		self::answer( 400, array( 'channel' => 'not a list' ) );
		$this->assertSame( 'unknown', Client::property()->code );
		self::answer( 400, array( 'code' => 'availability_conflict', 'channel' => array( 'x' ) ) );
		$this->assertSame( 'availability_conflict', Client::property()->code );
	}

	public function test_ok_status_with_a_wrong_body_is_invalid(): void {
		self::answer( 200, array( 'hello' => 'world' ) );
		$r = Client::property();
		$this->assertSame( Result::INVALID, $r->kind );
		$this->assertSame( 'bad_answer', $r->code );
	}

	public function test_no_key_and_network_errors(): void {
		Secret::clear();
		$this->assertSame( Result::UNAUTHORIZED, Client::property()->kind );
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		\WpStub::$next_response = new \WP_Error( 'x', 'y' );
		$r = Client::property();
		$this->assertSame( Result::NETWORK, $r->kind );
		$this->assertSame( HttpResponse::ERR_NETWORK, $r->code );
	}
}

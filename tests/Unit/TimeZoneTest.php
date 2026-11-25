<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\SyncRunner;

/** HostelMate's zone is read as HostelMate reads it (blank = UTC), and /rates is asked from HostelMate's own today. */
final class TimeZoneTest extends TestCase {

	private const PROPERTY = '0b5e2c4a-1f3d-4e6b-8a7c-000000000001';
	private const ROOM     = '8a3d2c19-6e5f-4b70-a1d2-93c4e5f60718';

	private function property( string $zone ): void {
		State::set(
			'property',
			array(
				'id'       => self::PROPERTY,
				'currency' => 'USD',
				'timezone' => $zone,
				'rooms'    => array(),
			)
		);
	}

	public function test_blank_hostelmate_zone_is_utc(): void {
		$this->property( '' );
		\WpStub::$zone = 'UTC';
		$this->assertTrue( SyncRunner::zones_match() );
		\WpStub::$zone = 'America/Mexico_City';
		$this->assertFalse( SyncRunner::zones_match(), 'blank (UTC) against UTC−6 differs' );
		$this->assertSame( 'UTC', SyncRunner::hm_zone()->getName() );
	}

	public function test_same_and_different_zones(): void {
		\WpStub::$zone = 'America/Mexico_City';
		$this->property( 'America/Mexico_City' );
		$this->assertTrue( SyncRunner::zones_match() );
		$this->property( 'Asia/Dubai' );
		$this->assertFalse( SyncRunner::zones_match() );
		$this->property( 'Mars/Olympus' ); // Not a zone PHP knows: may differ, so warn.
		$this->assertFalse( SyncRunner::zones_match() );
		$this->assertSame( 'UTC', SyncRunner::hm_zone()->getName(), 'HostelMate falls back to UTC too' );
	}

	public function test_not_connected_is_not_a_mismatch(): void {
		\WpStub::$zone = 'America/Mexico_City';
		$this->assertTrue( SyncRunner::zones_match() );
	}

	public function test_the_warning_names_both_zones_and_stays_one_alert(): void {
		\WpStub::$zone = 'America/Mexico_City';
		$this->property( '' );
		$m = new \ReflectionMethod( SyncRunner::class, 'zone_alert' );
		$m->invoke( null );
		$m->invoke( null );
		$open = array_values( array_filter( Alerts::list(), static fn( array $a ): bool => Messages::SYNC_TIMEZONE === $a['code'] ) );
		$this->assertCount( 1, $open );
		$this->assertSame( Config::ALERT_LEVEL_AMBER, $open[0]['level'] );
		$this->assertSame( 'HostelMate: not set, so UTC · WordPress: America/Mexico_City', $open[0]['ref'] );
		// The zones change: the alert is replaced, not added to.
		$this->property( 'Asia/Dubai' );
		$m->invoke( null );
		$open = array_values( array_filter( Alerts::list(), static fn( array $a ): bool => Messages::SYNC_TIMEZONE === $a['code'] ) );
		$this->assertCount( 1, $open );
		$this->assertSame( 'HostelMate: Asia/Dubai · WordPress: America/Mexico_City', $open[0]['ref'] );
		// Matching again closes it.
		$this->property( 'America/Mexico_City' );
		$m->invoke( null );
		$this->assertSame( array(), array_values( array_filter( Alerts::list(), static fn( array $a ): bool => Messages::SYNC_TIMEZONE === $a['code'] ) ) );
	}

	/** HostelMate answers /rates for exactly the nights asked. */
	private function rates_answer( string $start, string $end ): void {
		$nights = array();
		foreach ( Clock::nights( $start, Clock::add_days( $end, 1 ) ) as $d ) {
			$nights[] = array( 'date' => $d, 'price' => 100, 'closed' => false, 'available' => 1, 'min_stay' => null, 'max_stay' => null, 'closed_to_arrival' => false, 'closed_to_departure' => false );
		}
		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode(
				array(
					'property_id' => self::PROPERTY,
					'currency'    => 'USD',
					'start_date'  => $start,
					'end_date'    => $end,
					'rooms'       => array(
						array(
							'room_id'   => self::ROOM,
							'name'      => 'Room',
							'sell_mode' => 'per_room',
							'nights'    => $nights,
						),
					),
				)
			),
		);
	}

	/** @return array<string, mixed>|string */
	private function fetch( array $nights ) {
		$m = new \ReflectionMethod( SyncRunner::class, 'fetch' );
		return $m->invoke( null, $nights, 'USD' );
	}

	public function test_rates_are_asked_from_hostelmates_today_when_it_is_ahead(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		\WpStub::$zone = 'Etc/GMT+12';      // UTC−12.
		$this->property( 'Etc/GMT-12' );    // UTC+12: always exactly one day ahead.
		$site_today = Clock::today( new \DateTimeZone( 'Etc/GMT+12' ) );
		$nights     = Clock::nights( $site_today, Clock::add_days( $site_today, 5 ) );
		$this->rates_answer( $nights[1], $nights[4] );
		$out = $this->fetch( $nights );
		$this->assertIsArray( $out );
		$this->assertStringContainsString( 'start_date=' . $nights[1] . '&end_date=' . $nights[4], \WpStub::$requests[0]['url'], 'asked from HostelMate\'s today' );
		$this->assertArrayNotHasKey( $nights[0], $out[ self::ROOM ], 'the website\'s tonight is not asked for' );
		$this->assertCount( 4, $out[ self::ROOM ] );
	}

	public function test_same_zone_asks_from_the_websites_today(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		\WpStub::$zone = 'America/Mexico_City';
		$this->property( 'America/Mexico_City' );
		$today  = Clock::today( new \DateTimeZone( 'America/Mexico_City' ) );
		$nights = Clock::nights( $today, Clock::add_days( $today, 3 ) );
		$this->rates_answer( $nights[0], $nights[2] );
		$this->assertIsArray( $this->fetch( $nights ) );
		$this->assertStringContainsString( 'start_date=' . $nights[0] . '&', \WpStub::$requests[0]['url'] );
	}

	public function test_hostelmate_behind_the_website_changes_nothing(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		\WpStub::$zone = 'Etc/GMT-12';      // The website is ahead.
		$this->property( 'Etc/GMT+12' );
		$today  = Clock::today( new \DateTimeZone( 'Etc/GMT-12' ) );
		$nights = Clock::nights( $today, Clock::add_days( $today, 3 ) );
		$this->rates_answer( $nights[0], $nights[2] );
		$this->assertIsArray( $this->fetch( $nights ) );
		$this->assertStringContainsString( 'start_date=' . $nights[0] . '&', \WpStub::$requests[0]['url'] );
	}
}

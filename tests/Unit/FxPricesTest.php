<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\SyncRunner;

/** HostelMate's /rates answer in the website's currency: prices converted, everything else exactly as it was. */
final class FxPricesTest extends TestCase {

	/** @return array<string, mixed> */
	private static function night( ?int $price, bool $closed = false ): array {
		return array(
			'price'     => $price,
			'closed'    => $closed,
			'available' => 3,
			'min_stay'  => 2,
			'max_stay'  => null,
			'cta'       => true,
			'ctd'       => false,
		);
	}

	/** @return array<string, mixed>|string */
	private static function convert( array $hm, string $web_rate, string $hm_rate = '1' ) {
		$m = new \ReflectionMethod( SyncRunner::class, 'converted' );
		return $m->invoke(
			null,
			$hm,
			array(
				'hm'        => 'USD',
				'web'       => 'MXN',
				'hm_rate'   => $hm_rate,
				'web_rate'  => $web_rate,
				'refreshed' => 1,
			)
		);
	}

	public function test_prices_converted_rest_unchanged(): void {
		$hm  = array(
			'room-a' => array(
				'2026-12-01' => self::night( 8725 ),
				'2026-12-02' => self::night( 0 ),
				'2026-12-03' => self::night( null, true ),
				'2026-12-04' => self::night( 13157, true ),
			),
		);
		$out = self::convert( $hm, '19.17796700' );
		$this->assertIsArray( $out );
		$this->assertSame( 167328, $out['room-a']['2026-12-01']['price'] );   // 87.25 USD = 1,673.28 MXN.
		$this->assertSame( 0, $out['room-a']['2026-12-02']['price'] );        // 0 stays 0.
		$this->assertNull( $out['room-a']['2026-12-03']['price'] );           // No price stays no price.
		$this->assertTrue( $out['room-a']['2026-12-03']['closed'] );          // Closed stays closed.
		$this->assertSame( 252325, $out['room-a']['2026-12-04']['price'] );
		$this->assertTrue( $out['room-a']['2026-12-04']['closed'] );
		foreach ( array( 'available', 'min_stay', 'max_stay', 'cta', 'ctd' ) as $k ) {
			$this->assertSame( $hm['room-a']['2026-12-01'][ $k ], $out['room-a']['2026-12-01'][ $k ], $k );
		}
	}

	public function test_a_price_hbook_cannot_store_stops_the_run(): void {
		// HostelMate's top price, 9,999,999.99 USD, in IRR: more than HBook's 999,999,999,999.99.
		$out = self::convert( array( 'r' => array( '2026-12-01' => self::night( 999999999 ) ) ), '1948959.99968300' );
		$this->assertSame( Messages::FX_TOO_BIG, $out );
		$this->assertContains( Messages::FX_TOO_BIG, array_column( Alerts::list(), 'code' ) );
		// The next good conversion closes it.
		$this->assertIsArray( self::convert( array( 'r' => array( '2026-12-01' => self::night( 100 ) ) ), '20' ) );
		$this->assertNotContains( Messages::FX_TOO_BIG, array_column( Alerts::list(), 'code' ) );
	}
}

<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Sync\Fx;
use PHPUnit\Framework\Attributes\DataProvider;

/** The conversion maths: HostelMate's formula and rounding, exactly, and the rules for a newer rate. */
final class FxTest extends TestCase {

	/**
	 * A pair.
	 *
	 * @return array{hm: string, web: string, hm_rate: string, web_rate: string, refreshed: int}
	 */
	private static function pair( string $hm_rate, string $web_rate, string $hm = 'USD', string $web = 'MXN' ): array {
		return array(
			'hm'        => $hm,
			'web'       => $web,
			'hm_rate'   => $hm_rate,
			'web_rate'  => $web_rate,
			'refreshed' => 1,
		);
	}

	public function test_needed_only_when_both_known_and_different(): void {
		$this->assertFalse( Fx::needed( 'USD', 'USD' ) );
		$this->assertFalse( Fx::needed( 'usd', ' USD ' ) );
		$this->assertFalse( Fx::needed( '', 'MXN' ) );
		$this->assertFalse( Fx::needed( 'USD', '' ) );
		$this->assertTrue( Fx::needed( 'USD', 'MXN' ) );
	}

	/** Every case computed by the backend's own fx_rate and rounding (fixtures/fx-hostelmate.json), 13 pairs × 1,000 amounts. */
	public function test_matches_hostelmate_exactly(): void {
		$data = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/fx-hostelmate.json' ), true );
		$this->assertIsArray( $data );
		$this->assertCount( 13000, $data['cases'] );
		$wrong   = array();
		$refused = 0;
		foreach ( $data['cases'] as list( $from, $to, $from_rate, $to_rate, $amount, $expected ) ) {
			try {
				$got = Fx::convert( $amount, $from_rate, $to_rate );
			} catch ( \OverflowException $e ) {
				$got = 'refused';
			}
			if ( ! is_int( $expected ) || $expected > 999999999999999999 ) {
				// Larger than any amount field (e.g. 9,999,999.99 BTC in IRR): it must be refused, never wrapped round.
				$expected = 'refused';
				++$refused;
			}
			if ( $got !== $expected ) {
				$wrong[] = "$from→$to $amount: $got, HostelMate $expected";
			}
		}
		$this->assertSame( array(), array_slice( $wrong, 0, 10 ), count( $wrong ) . ' differ' );
		$this->assertLessThan( 1000, $refused, 'only the absurd cases are refused' );
	}

	public function test_rounds_half_up_at_the_boundary(): void {
		// 1 → ×0.5 = 0.5 cent → 1; 3 × 0.5 = 1.5 → 2; and the same away from zero for a refund.
		$this->assertSame( 1, Fx::convert( 1, '2', '1' ) );
		$this->assertSame( 2, Fx::convert( 3, '2', '1' ) );
		$this->assertSame( -2, Fx::convert( -3, '2', '1' ) );
		$this->assertSame( 1, Fx::convert( 3, '4', '1' ) ); // 0.75 → 1.
		$this->assertSame( 0, Fx::convert( 1, '4', '1' ) ); // 0.25 → 0.
		$this->assertSame( 0, Fx::convert( 0, '19.177967', '1' ) );
	}

	public function test_both_directions(): void {
		$pair = self::pair( '1', '19.17796700' );
		$this->assertSame( 167328, Fx::to_website( 8725, $pair ) );     // USD 87.25 → MXN 1,673.28.
		$this->assertSame( 8725, Fx::to_hostelmate( 167328, $pair ) );  // And back.
	}

	public function test_large_amounts_do_not_overflow(): void {
		// HBook's largest amount into the currency with the largest rate.
		$this->assertSame( 194895999968280510, Fx::convert( 9999999999999, '1', '19489.59999683' ) );
		$this->expectException( \OverflowException::class );
		Fx::convert( 99999999999999, '0.00001635', '1948959.99968300' );
	}

	/** @return list<array{mixed}> */
	public static function bad_rates(): array {
		return array( array( '' ), array( '0' ), array( '0.00000000' ), array( '-1' ), array( '1e5' ), array( '1.123456789' ), array( '1234567890' ), array( 'abc' ), array( ' 1' ), array( 1.5 ), array( null ), array( '.5' ) );
	}

	#[DataProvider( 'bad_rates' )]
	public function test_unusable_rates_are_refused( $rate ): void {
		$this->assertNull( Fx::rate_e8( $rate ) );
	}

	public function test_a_bad_rate_never_converts(): void {
		$this->expectException( \InvalidArgumentException::class );
		Fx::convert( 100, '0', '1' );
	}

	public function test_usable_rates(): void {
		$this->assertSame( 100000000, Fx::rate_e8( '1' ) );
		$this->assertSame( 1917796700, Fx::rate_e8( '19.17796700' ) );
		$this->assertSame( 1635, Fx::rate_e8( '0.00001635' ) );
		$this->assertSame( 194895999968300, Fx::rate_e8( '1948959.99968300' ) );
	}

	public function test_a_newer_rate_moves_in_steps(): void {
		$then = self::pair( '1', '20' );
		$this->assertSame( Fx::IGNORE, Fx::step( $then, self::pair( '1', '20' ) ) );
		$this->assertSame( Fx::IGNORE, Fx::step( $then, self::pair( '1', '20.19999999' ) ) ); // +0.99999%.
		$this->assertSame( Fx::IGNORE, Fx::step( $then, self::pair( '1', '19.80000001' ) ) );
		$this->assertSame( Fx::USE, Fx::step( $then, self::pair( '1', '20.2' ) ) );          // Exactly +1%.
		$this->assertSame( Fx::USE, Fx::step( $then, self::pair( '1', '19.8' ) ) );          // Exactly −1%.
		$this->assertSame( Fx::USE, Fx::step( $then, self::pair( '1', '22' ) ) );            // Exactly +10%.
		$this->assertSame( Fx::USE, Fx::step( $then, self::pair( '1', '18' ) ) );            // Exactly −10%.
		$this->assertSame( Fx::HOLD, Fx::step( $then, self::pair( '1', '22.00000001' ) ) );
		$this->assertSame( Fx::HOLD, Fx::step( $then, self::pair( '1', '17.99999999' ) ) );
	}

	public function test_steps_compare_the_cross_rate_not_one_leg(): void {
		// EUR ↔ MXN: both legs moved 5% the same way, so 1 EUR in MXN didn't move.
		$then = self::pair( '0.9', '18', 'EUR', 'MXN' );
		$this->assertSame( Fx::IGNORE, Fx::step( $then, self::pair( '0.945', '18.9', 'EUR', 'MXN' ) ) );
		// Only the HostelMate leg moved 12%: 1 EUR buys 12% fewer pesos... held.
		$this->assertSame( Fx::HOLD, Fx::step( $then, self::pair( '1.008', '18', 'EUR', 'MXN' ) ) );
	}

	public function test_words_for_a_rate(): void {
		$this->assertSame( '19.177967', Fx::shown_rate( self::pair( '1', '19.17796700' ) ) );
		$this->assertSame( '19.18', Fx::shown_rate( self::pair( '1', '19.17796700' ), 2 ) );
		$this->assertSame( '0.052143', Fx::shown_rate( self::pair( '19.17796700', '1', 'MXN', 'USD' ) ) );
		$this->assertSame( '+1.8%', Fx::change_pct( self::pair( '1', '19.18' ), self::pair( '1', '19.52' ) ) );
		$this->assertSame( '-10.0%', Fx::change_pct( self::pair( '1', '20' ), self::pair( '1', '18' ) ) );
	}

	public function test_age_rules(): void {
		$now = 1000000000;
		$this->assertFalse( Fx::too_old( $now - Config::FX_MAX_AGE, $now ) );
		$this->assertTrue( Fx::too_old( $now - Config::FX_MAX_AGE - 1, $now ) );
		$this->assertTrue( Fx::too_old( 0, $now ) );
		$this->assertFalse( Fx::refresh_due( $now - 3600, 0, $now ) );                                  // Fresh list.
		$this->assertTrue( Fx::refresh_due( $now - Config::FX_REFRESH_AFTER - 1, 0, $now ) );            // A day old, never tried.
		$this->assertFalse( Fx::refresh_due( $now - Config::FX_REFRESH_AFTER - 1, $now - 600, $now ) );  // Tried 10 minutes ago.
		$this->assertTrue( Fx::refresh_due( $now - Config::FX_REFRESH_AFTER - 1, $now - 3601, $now ) );
	}
}

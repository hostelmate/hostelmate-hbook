<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\HostelMate\Client;
use HostelMate\HBook\HostelMate\Result;
use HostelMate\HBook\HostelMate\Schemas;
use PHPUnit\Framework\Attributes\DataProvider;

/** HostelMate's public rates list: called without the key, on a fixed path, and read strictly. */
final class CurrencyRatesListTest extends TestCase {

	private const KEY = 'hmk_live_0123456789abcdefABCDEF';

	/** @return list<array<string, mixed>> The real answer of 2026-11-25. */
	private static function live(): array {
		return json_decode( (string) file_get_contents( __DIR__ . '/fixtures/currency-rates-list.json' ), true );
	}

	/** @param list<array<string, mixed>> $rows */
	private static function answer( array $rows ): void {
		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( $rows ),
		);
	}

	public function test_the_call_is_fixed_and_never_carries_the_key(): void {
		Secret::store( self::KEY );
		self::answer( self::live() );
		$this->assertTrue( Client::currency_rates()->is_ok() );
		$req = \WpStub::$requests[0];
		$this->assertSame( 'https://api.hostelmate.co/api/v1/currency-rates/list', $req['url'] );
		$this->assertSame( 'GET', $req['args']['method'] );
		$this->assertArrayNotHasKey( 'X-API-Key', $req['args']['headers'] );
		$this->assertStringNotContainsString( self::KEY, (string) json_encode( $req ) );
		$this->assertSame( 0, $req['args']['redirection'] );
		$this->assertTrue( $req['args']['sslverify'] );
		$this->assertTrue( $req['args']['reject_unsafe_urls'] );
		$this->assertSame( Config::HTTP_MAX_BYTES, $req['args']['limit_response_size'] );
	}

	public function test_works_without_a_key(): void {
		self::answer( self::live() );
		$this->assertTrue( Client::currency_rates()->is_ok() );
	}

	public function test_the_live_answer_is_read(): void {
		$out = Schemas::currency_rates( self::live() );
		$this->assertNotNull( $out );
		$this->assertSame( '19.17796700', $out['rates']['MXN'] );
		$this->assertSame( '0.91677000', $out['rates']['EUR'] );
		$this->assertArrayNotHasKey( 'USD', $out['rates'] ); // USD is the base: 1.
		$this->assertCount( 173, $out['rates'] );
		// HostelMate's last refresh is the newest date in the list, not one currency's: BMD (pegged) is dated 2025-12-08.
		$this->assertSame( strtotime( '2026-11-24T00:00:09Z' ), $out['refreshed'] );
		$this->assertSame( '1.00000000', $out['rates']['BMD'] );
	}

	public function test_the_newest_row_of_a_pair_wins(): void {
		$rows = array(
			array( 'id' => 1, 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '18.00000000', 'fetched_at' => '2026-11-20T00:00:09Z' ),
			array( 'id' => 2, 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '19.00000000', 'fetched_at' => '2026-11-24T00:00:09.123456Z' ),
			array( 'id' => 3, 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '17.00000000', 'fetched_at' => '2026-11-22T04:00:00+04:00' ),
		);
		$out = Schemas::currency_rates( $rows );
		$this->assertNotNull( $out );
		$this->assertSame( '19.00000000', $out['rates']['MXN'] );
		$this->assertSame( strtotime( '2026-11-24T00:00:09Z' ), $out['refreshed'] );
	}

	/** @return array<string, array{callable}> */
	public static function broken(): array {
		$set = static fn( string $k, $v ): callable => static function ( array $rows ) use ( $k, $v ): array {
			$rows[0][ $k ] = $v;
			return $rows;
		};
		return array(
			'another base'      => array( $set( 'base_currency', 'EUR' ) ),
			'lowercase code'    => array( $set( 'quote_currency', 'mxn' ) ),
			'zero rate'         => array( $set( 'rate', '0.00000000' ) ),
			'negative rate'     => array( $set( 'rate', '-1.5' ) ),
			'number not text'   => array( $set( 'rate', 19.18 ) ),
			'no rate'           => array( $set( 'rate', null ) ),
			'no date'           => array( $set( 'fetched_at', null ) ),
			'date without zone' => array( $set( 'fetched_at', '2026-11-24T00:00:09' ) ),
			'not a date'        => array( $set( 'fetched_at', 'yesterday' ) ),
			'usd not one'       => array(
				static function ( array $rows ): array {
					$rows[0]['quote_currency'] = 'USD';
					$rows[0]['rate']           = '2.00000000';
					return $rows;
				},
			),
			'row not an object' => array(
				static function ( array $rows ): array {
					$rows[0] = 'MXN';
					return $rows;
				},
			),
		);
	}

	#[DataProvider( 'broken' )]
	public function test_anything_unexpected_rejects_the_whole_answer( callable $break ): void {
		$this->assertNull( Schemas::currency_rates( $break( self::live() ) ) );
	}

	public function test_other_shapes_are_rejected(): void {
		$this->assertNull( Schemas::currency_rates( array() ) );
		$this->assertNull( Schemas::currency_rates( array( 'results' => self::live() ) ) ); // A paginated shape.
		self::answer( array() );
		$this->assertSame( Result::INVALID, Client::currency_rates()->kind );
	}

	public function test_usd_against_itself_is_skipped(): void {
		$rows   = self::live();
		$rows[] = array( 'id' => 9, 'base_currency' => 'USD', 'quote_currency' => 'USD', 'rate' => '1.00000000', 'fetched_at' => '2026-11-24T00:00:09Z' );
		$out    = Schemas::currency_rates( $rows );
		$this->assertNotNull( $out );
		$this->assertArrayNotHasKey( 'USD', $out['rates'] );
	}

	public function test_network_failure_is_reported(): void {
		\WpStub::$next_response = new \WP_Error( 'http_request_failed', 'timeout' );
		$this->assertSame( Result::NETWORK, Client::currency_rates()->kind );
	}
}

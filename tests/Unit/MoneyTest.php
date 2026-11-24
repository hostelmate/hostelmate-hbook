<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Core\Money;
use PHPUnit\Framework\Attributes\DataProvider;

final class MoneyTest extends TestCase {

	/** @return list<array{string, int}> */
	public static function decimals(): array {
		return array(
			array( '1250', 125000 ),
			array( '1250.5', 125050 ),
			array( '1250.50', 125050 ),
			array( '0.05', 5 ),
			array( '-3.25', -325 ),
			array( ' 99.99 ', 9999 ),
			array( '999999999999.99', 99999999999999 ),
		);
	}

	#[DataProvider( 'decimals' )]
	public function test_to_minor( string $decimal, int $minor ): void {
		$this->assertSame( $minor, Money::to_minor( $decimal ) );
	}

	/** @return list<array{string}> */
	public static function bad_decimals(): array {
		return array( array( '' ), array( "1\n2" ), array( '1,250.00' ), array( '1.234' ), array( 'abc' ), array( '1e5' ), array( '--1' ), array( '.5' ), array( '1.' ) );
	}

	#[DataProvider( 'bad_decimals' )]
	public function test_to_minor_refuses_anything_else( string $decimal ): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::to_minor( $decimal );
	}

	public function test_to_decimal(): void {
		$this->assertSame( '1250.50', Money::to_decimal( 125050 ) );
		$this->assertSame( '0.05', Money::to_decimal( 5 ) );
		$this->assertSame( '-0.05', Money::to_decimal( -5 ) );
		$this->assertSame( '0.00', Money::to_decimal( 0 ) );
	}

	public function test_round_trip(): void {
		foreach ( array( 0, 1, 99, 100, 125050, -77, 999999999 ) as $minor ) {
			$this->assertSame( $minor, Money::to_minor( Money::to_decimal( $minor ) ) );
		}
	}

	public function test_split_puts_remainder_last(): void {
		$this->assertSame( array( 33, 33, 34 ), Money::split( 100, 3 ) );
		$this->assertSame( array( 0, 0, 1 ), Money::split( 1, 3 ) );
		$this->assertSame( array( 500 ), Money::split( 500, 1 ) );
	}

	public function test_split_by_weights(): void {
		$this->assertSame( array( 250, 250, 500 ), Money::split_by_weights( 1000, array( 1, 1, 2 ) ) );
		$this->assertSame( array( 333, 333, 334 ), Money::split_by_weights( 1000, array( 0, 0, 0 ) ) );
		$this->assertSame( array( 0, 1000 ), Money::split_by_weights( 1000, array( 0, 5 ) ) );
	}

	public function test_split_always_adds_up(): void {
		mt_srand( 42 );
		for ( $i = 0; $i < 500; $i++ ) {
			$total   = mt_rand( 0, 50000000 );
			$weights = array();
			for ( $w = mt_rand( 1, 8 ); $w > 0; $w-- ) {
				$weights[] = mt_rand( 0, 999999999 );
			}
			$pieces = Money::split_by_weights( $total, $weights );
			$this->assertCount( count( $weights ), $pieces );
			$this->assertSame( $total, array_sum( $pieces ) );
			foreach ( $pieces as $piece ) {
				$this->assertGreaterThanOrEqual( 0, $piece );
			}
		}
	}

	public function test_split_refuses_bad_input(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::split_by_weights( -1, array( 1 ) );
	}

	public function test_split_refuses_negative_weight(): void {
		$this->expectException( \InvalidArgumentException::class );
		Money::split_by_weights( 10, array( 1, -1 ) );
	}

	public function test_split_refuses_instead_of_overflowing(): void {
		$this->expectException( \OverflowException::class );
		Money::split_by_weights( PHP_INT_MAX, array( PHP_INT_MAX, 1 ) );
	}
}

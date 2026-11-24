<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Core\Ids;
use HostelMate\HBook\Core\Validate;

final class ValidateAndIdsTest extends TestCase {

	public function test_uuid(): void {
		$this->assertTrue( Validate::is_uuid( '7c2e9a41-5d3b-4f60-8a17-e4b9c06d2f50' ) );
		$this->assertFalse( Validate::is_uuid( '7c2e9a41-5d3b-4f60-8a17-e4b9c06d2f5' ) );
		$this->assertFalse( Validate::is_uuid( "7c2e9a41-5d3b-4f60-8a17-e4b9c06d2f50\n" ) );
		$this->assertFalse( Validate::is_uuid( 12 ) );
	}

	public function test_date(): void {
		$this->assertTrue( Validate::is_date( '2028-02-29' ) );
		$this->assertFalse( Validate::is_date( '2026-02-29' ) );
		$this->assertFalse( Validate::is_date( '2026-1-05' ) );
		$this->assertFalse( Validate::is_date( '2026-01-05 00:00' ) );
		$this->assertFalse( Validate::is_date( null ) );
	}

	public function test_int_between_is_strict(): void {
		$this->assertTrue( Validate::is_int_between( 5, 0, 10 ) );
		$this->assertFalse( Validate::is_int_between( '5', 0, 10 ) );
		$this->assertFalse( Validate::is_int_between( 5.0, 0, 10 ) );
		$this->assertFalse( Validate::is_int_between( 11, 0, 10 ) );
	}

	public function test_text(): void {
		$this->assertTrue( Validate::is_text( 'Café', 10 ) );
		$this->assertFalse( Validate::is_text( '', 10 ) );
		$this->assertFalse( Validate::is_text( "two\nlines", 20 ) );
		$this->assertFalse( Validate::is_text( str_repeat( 'a', 11 ), 10 ) );
		$this->assertFalse( Validate::is_text( "\xC3\x28", 10 ) );
	}

	public function test_email_and_token(): void {
		$this->assertTrue( Validate::is_email( 'owner@example.com' ) );
		$this->assertFalse( Validate::is_email( "owner@example.com\r\nBcc: x@y.z" ) );
		$this->assertTrue( Validate::is_token( str_repeat( 'k', 32 ), 20, 200 ) );
		$this->assertFalse( Validate::is_token( 'short', 20, 200 ) );
		$this->assertFalse( Validate::is_token( str_repeat( 'k', 30 ) . ' x', 20, 200 ) );
	}

	public function test_a_trailing_newline_never_passes(): void {
		// PCRE's "$" also matches before a final newline; every check must use \z.
		$this->assertFalse( Validate::is_date( "2026-12-03\n" ) );
		$this->assertFalse( Validate::is_token( str_repeat( 'k', 32 ) . "\n", 20, 200 ) );
		$this->assertFalse( \HostelMate\HBook\Core\Http::call( 'GET', "rates\n" )->has_answer() );
	}

	public function test_uuid4(): void {
		$seen = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$id = Ids::uuid4();
			$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
			$seen[ $id ] = true;
		}
		$this->assertCount( 200, $seen );
	}

	public function test_idempotency_key_is_stable(): void {
		$this->assertSame( Ids::idempotency_key( 'a', 'b' ), Ids::idempotency_key( 'a', 'b' ) );
		$this->assertNotSame( Ids::idempotency_key( 'ab', '' ), Ids::idempotency_key( 'a', 'b' ) );
		$this->assertSame( 64, strlen( Ids::idempotency_key( 'x' ) ) );
	}
}

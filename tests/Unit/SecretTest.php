<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Secret;

final class SecretTest extends TestCase {

	private const KEY = 'hmk_live_0123456789abcdefABCDEF';

	public function test_round_trip_and_hint(): void {
		$this->assertTrue( Secret::store( '  ' . self::KEY . "\n" ) );
		$this->assertSame( self::KEY, Secret::get() );
		$this->assertSame( 'CDEF', Secret::hint() );
		$this->assertTrue( Secret::has() );
	}

	public function test_database_never_holds_the_key_in_clear(): void {
		Secret::store( self::KEY );
		$stored = \WpStub::$options[ Config::OPT_KEY ];
		$this->assertIsString( $stored );
		$this->assertStringStartsWith( 'v1:', $stored );
		$this->assertStringNotContainsString( self::KEY, $stored );
		$this->assertStringNotContainsString( substr( self::KEY, 4, 12 ), base64_decode( substr( $stored, 3 ) ) );
	}

	public function test_two_stores_use_different_nonces(): void {
		Secret::store( self::KEY );
		$first = \WpStub::$options[ Config::OPT_KEY ];
		Secret::store( self::KEY );
		$this->assertNotSame( $first, \WpStub::$options[ Config::OPT_KEY ] );
	}

	public function test_database_alone_is_not_enough(): void {
		Secret::store( self::KEY );
		\WpStub::$salts['auth'] = 'a-different-site';
		$this->assertNull( Secret::get() );
		$this->assertFalse( Secret::has() );
	}

	public function test_tampered_ciphertext_is_refused(): void {
		Secret::store( self::KEY );
		$stored                                   = \WpStub::$options[ Config::OPT_KEY ];
		$raw                                      = base64_decode( substr( $stored, 3 ) );
		$raw[30]                                  = chr( ord( $raw[30] ) ^ 1 );
		\WpStub::$options[ Config::OPT_KEY ] = 'v1:' . base64_encode( $raw );
		$this->assertNull( Secret::get() );
		\WpStub::$options[ Config::OPT_KEY ] = 'not even close';
		$this->assertNull( Secret::get() );
	}

	public function test_bad_keys_are_refused(): void {
		$this->assertFalse( Secret::store( 'short' ) );
		$this->assertFalse( Secret::store( str_repeat( 'a', 10 ) . ' ' . str_repeat( 'b', 20 ) ) );
		$this->assertArrayNotHasKey( Config::OPT_KEY, \WpStub::$options );
	}

	public function test_salts_must_be_strong_and_in_wp_config(): void {
		$this->assertTrue( Secret::salts_strong() ); // The test bootstrap defines strong ones.
	}

	public function test_clear(): void {
		Secret::store( self::KEY );
		Secret::clear();
		$this->assertNull( Secret::get() );
		$this->assertSame( '', Secret::hint() );
	}
}

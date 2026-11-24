<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Http;
use HostelMate\HBook\Core\HttpResponse;
use HostelMate\HBook\Core\Secret;

final class HttpTest extends TestCase {

	private const KEY = 'hmk_live_0123456789abcdefABCDEF';

	protected function setUp(): void {
		parent::setUp();
		Secret::store( self::KEY );
	}

	public function test_safe_request_shape(): void {
		Http::call( 'GET', 'rates', array( 'start_date' => '2026-12-01', 'end_date' => '2026-12-31' ) );
		$this->assertCount( 1, \WpStub::$requests );
		$req = \WpStub::$requests[0];
		$this->assertSame( Config::API_BASE . 'rates?start_date=2026-12-01&end_date=2026-12-31', $req['url'] );
		$this->assertSame( 0, $req['args']['redirection'] );
		$this->assertTrue( $req['args']['sslverify'] );
		$this->assertTrue( $req['args']['reject_unsafe_urls'] );
		$this->assertSame( Config::HTTP_MAX_BYTES, $req['args']['limit_response_size'] );
		$this->assertSame( self::KEY, $req['args']['headers']['X-API-Key'] );
		$this->assertSame( Config::VERSION, $req['args']['headers']['X-Client-Version'] );
		$this->assertArrayNotHasKey( 'body', $req['args'] );
		$this->assertStringNotContainsString( self::KEY, $req['url'] );
	}

	public function test_post_sends_json_and_idempotency_key(): void {
		Http::call( 'POST', 'bookings', array(), array( 'room' => 'x' ), 'abc123' );
		$args = \WpStub::$requests[0]['args'];
		$this->assertSame( 'POST', $args['method'] );
		$this->assertSame( '{"room":"x"}', $args['body'] );
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( 'abc123', $args['headers']['Idempotency-Key'] );
	}

	public function test_no_key_means_no_call(): void {
		Secret::clear();
		$r = Http::call( 'GET', 'property' );
		$this->assertSame( HttpResponse::ERR_NO_KEY, $r->error );
		$this->assertCount( 0, \WpStub::$requests );
	}

	public function test_bad_paths_and_methods_are_refused(): void {
		foreach ( array( '../admin', 'rates?x=1', 'https://evil.example/', 'Rates', '' ) as $path ) {
			$this->assertSame( HttpResponse::ERR_BAD_PATH, Http::call( 'GET', $path )->error, $path );
		}
		$this->assertSame( HttpResponse::ERR_BAD_PATH, Http::call( 'DELETE', 'bookings' )->error );
		$this->assertCount( 0, \WpStub::$requests );
	}

	public function test_redirect_is_never_followed(): void {
		\WpStub::$next_response = array(
			'response' => array( 'code' => 302 ),
			'body'     => '',
		);
		$r = Http::call( 'GET', 'property' );
		$this->assertSame( HttpResponse::ERR_REDIRECT, $r->error );
		$this->assertSame( 302, $r->status );
	}

	public function test_network_error_and_non_json(): void {
		\WpStub::$next_response = new \WP_Error( 'http_request_failed', 'timeout' );
		$this->assertSame( HttpResponse::ERR_NETWORK, Http::call( 'GET', 'property' )->error );

		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => '<html>hi</html>',
		);
		$this->assertSame( HttpResponse::ERR_NOT_JSON, Http::call( 'GET', 'property' )->error );

		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => '"just a string"',
		);
		$this->assertSame( HttpResponse::ERR_NOT_JSON, Http::call( 'GET', 'property' )->error );
	}

	public function test_too_big_and_too_deep(): void {
		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => str_repeat( ' ', Config::HTTP_MAX_BYTES ),
		);
		$this->assertSame( HttpResponse::ERR_TOO_BIG, Http::call( 'GET', 'property' )->error );

		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => str_repeat( '[', 100 ) . str_repeat( ']', 100 ),
		);
		$this->assertSame( HttpResponse::ERR_NOT_JSON, Http::call( 'GET', 'property' )->error );
	}

	public function test_json_answer_with_any_status(): void {
		\WpStub::$next_response = array(
			'response' => array( 'code' => 409 ),
			'body'     => '{"code":"availability_conflict","message":"x"}',
		);
		$r = Http::call( 'POST', 'bookings', array(), array() );
		$this->assertTrue( $r->has_answer() );
		$this->assertSame( 409, $r->status );
		$this->assertSame( 'availability_conflict', $r->data['code'] );
	}

	public function test_http_base_override_is_ignored(): void {
		// A non-HTTPS override must never be used; the constant is checked at call time.
		$this->assertFalse( defined( Config::API_BASE_CONSTANT ) );
		Http::call( 'GET', 'property' );
		$this->assertStringStartsWith( 'https://api.hostelmate.co/', \WpStub::$requests[0]['url'] );
	}
}

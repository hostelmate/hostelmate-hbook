<?php
/**
 * The only place that talks HTTP. Every rule for calling HostelMate lives here:
 * fixed HTTPS base, certificate checks on, no redirects, capped size, JSON only,
 * and the key added as a header just for the call.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;

defined( 'ABSPATH' ) || exit;

final class Http {

	private const METHODS = array( 'GET', 'POST', 'PATCH' );

	/**
	 * One call to the HostelMate client API.
	 *
	 * @param string                    $method          GET, POST or PATCH.
	 * @param string                    $path            Path under the API base, e.g. "rates" or "bookings/<uuid>/update".
	 * @param array<string, scalar>     $query           Query string values.
	 * @param array<string, mixed>|null $body            JSON body for POST/PATCH.
	 * @param string                    $idempotency_key Sent as Idempotency-Key when not empty.
	 * @param int                       $timeout         Seconds.
	 */
	public static function call( string $method, string $path, array $query = array(), ?array $body = null, string $idempotency_key = '', int $timeout = Config::HTTP_TIMEOUT ): HttpResponse {
		if ( ! in_array( $method, self::METHODS, true ) || 1 !== preg_match( '#^[a-z0-9_-]+(/[a-z0-9_-]+)*\z#', $path ) ) {
			return HttpResponse::failed( HttpResponse::ERR_BAD_PATH );
		}
		$key = Secret::get();
		if ( null === $key ) {
			return HttpResponse::failed( HttpResponse::ERR_NO_KEY );
		}

		$headers = array(
			'X-API-Key'        => $key,
			'Accept'           => 'application/json',
			'User-Agent'       => 'HostelMate-HBook/' . Config::VERSION,
			'X-Client-Version' => Config::VERSION,
		);
		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}
		$args = array(
			'method'              => $method,
			'timeout'             => max( 1, $timeout ),
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => Config::HTTP_MAX_BYTES,
			'headers'             => $headers,
		);
		if ( null !== $body ) {
			$json = wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json ) {
				return HttpResponse::failed( HttpResponse::ERR_BAD_BODY );
			}
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $json;
		}

		$url = self::base() . $path . ( array() === $query ? '' : '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ) );
		unset( $key );
		return self::send( $url, $args );
	}

	/**
	 * HostelMate's public exchange-rate list (backend api/req/currency_rates.py: no login). The only call outside the
	 * client API: a fixed path on the same host, GET only, and the key is never sent with it. Called only while the
	 * two currencies differ (Sync\Fx).
	 */
	public static function rates_list(): HttpResponse {
		$base = self::base();
		if ( ! str_ends_with( $base, Config::API_CLIENT_DIR ) ) {
			return HttpResponse::failed( HttpResponse::ERR_BAD_PATH );
		}
		return self::send(
			substr( $base, 0, -strlen( Config::API_CLIENT_DIR ) ) . Config::API_RATES_LIST_PATH,
			array(
				'method'              => 'GET',
				'timeout'             => Config::HTTP_TIMEOUT,
				'redirection'         => 0,
				'sslverify'           => true,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => Config::HTTP_MAX_BYTES,
				'headers'             => array(
					'Accept'           => 'application/json',
					'User-Agent'       => 'HostelMate-HBook/' . Config::VERSION,
					'X-Client-Version' => Config::VERSION,
				),
			)
		);
	}

	/**
	 * Sends one request and reads the answer under the rules above.
	 *
	 * @param string               $url  Full URL.
	 * @param array<string, mixed> $args wp_remote_request() arguments.
	 */
	private static function send( string $url, array $args ): HttpResponse {
		$response = wp_remote_request( $url, $args );
		unset( $args );

		if ( is_wp_error( $response ) ) {
			return HttpResponse::failed( HttpResponse::ERR_NETWORK );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 300 && $status < 400 ) {
			return HttpResponse::failed( HttpResponse::ERR_REDIRECT, $status );
		}
		$raw = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $raw ) >= Config::HTTP_MAX_BYTES ) {
			return HttpResponse::failed( HttpResponse::ERR_TOO_BIG, $status );
		}
		return new HttpResponse( $status, ...self::decode( $raw ) );
	}

	/** The API base: fixed in Config, or a wp-config.php constant for our own tests (HTTPS only). */
	private static function base(): string {
		if ( defined( Config::API_BASE_CONSTANT ) ) {
			$custom = constant( Config::API_BASE_CONSTANT );
			if ( is_string( $custom ) && str_starts_with( $custom, 'https://' ) && str_ends_with( $custom, '/' ) ) {
				return $custom;
			}
		}
		return Config::API_BASE;
	}

	/**
	 * JSON → [data, error]. An empty body gives null data; a JSON object or list gives an array;
	 * a bare string or number is not an answer. Shapes are checked later, in HostelMate\Schemas.
	 *
	 * @param string $raw Response body.
	 * @return array{0: array<mixed>|null, 1: string}
	 */
	private static function decode( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array( null, '' );
		}
		try {
			$data = json_decode( $raw, true, Config::HTTP_JSON_DEPTH, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING );
		} catch ( \JsonException $e ) {
			return array( null, HttpResponse::ERR_NOT_JSON );
		}
		return is_array( $data ) ? array( $data, '' ) : array( null, HttpResponse::ERR_NOT_JSON );
	}
}

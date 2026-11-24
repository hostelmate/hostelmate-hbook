<?php
/**
 * What a HostelMate call ended as. Every caller decides only on $kind.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HostelMate;

use HostelMate\HBook\Core\HttpResponse;

defined( 'ABSPATH' ) || exit;

final class Result {

	public const OK           = 'ok';
	public const CONFLICT     = 'conflict';     // 409, for example an availability or booking id conflict.
	public const UNAUTHORIZED = 'unauthorized'; // 401: key missing or not accepted.
	public const FORBIDDEN    = 'forbidden';    // 403: plan or origin.
	public const NOT_FOUND    = 'not_found';    // 404.
	public const RATE_LIMITED = 'rate_limited'; // 429.
	public const INVALID      = 'invalid';      // 400/422, or an answer that failed Schemas.
	public const SERVER       = 'server';       // 5xx.
	public const NETWORK      = 'network';      // No usable answer at all.

	/**
	 * @param string            $kind One of the constants above.
	 * @param int               $status HTTP status (0 when none).
	 * @param string            $code HostelMate's error code, or our own reason ('' when ok).
	 * @param array<mixed>|null $data The checked, normalised answer (ok only).
	 */
	private function __construct(
		public readonly string $kind,
		public readonly int $status,
		public readonly string $code,
		public readonly ?array $data
	) {}

	/**
	 * A successful call with its checked data.
	 *
	 * @param array<mixed> $data Normalised by Schemas.
	 * @param int          $status HTTP status.
	 */
	public static function ok( array $data, int $status = 200 ): self {
		return new self( self::OK, $status, '', $data );
	}

	/**
	 * A failure.
	 *
	 * @param string $kind   One of the constants above (not OK).
	 * @param string $code   Reason code.
	 * @param int    $status HTTP status.
	 */
	public static function failed( string $kind, string $code, int $status = 0 ): self {
		return new self( $kind, $status, $code, null );
	}

	/** True for ok. */
	public function is_ok(): bool {
		return self::OK === $this->kind;
	}

	/**
	 * Maps an HTTP answer to a Result, running $check on 2xx bodies. The one place this mapping exists.
	 *
	 * @param HttpResponse                     $response The raw answer.
	 * @param callable(array<mixed>): ?array<mixed> $check    Returns normalised data, or null when the shape is wrong.
	 */
	public static function from_http( HttpResponse $response, callable $check ): self {
		if ( ! $response->has_answer() ) {
			$kind = HttpResponse::ERR_NO_KEY === $response->error ? self::UNAUTHORIZED : self::NETWORK;
			return self::failed( $kind, $response->error, $response->status );
		}
		$status = $response->status;
		$data   = $response->data ?? array();
		if ( $status >= 200 && $status < 300 ) {
			$checked = $check( $data );
			return null === $checked ? self::failed( self::INVALID, 'bad_answer', $status ) : self::ok( $checked, $status );
		}
		$code = Schemas::error_code( $data );
		if ( 401 === $status ) {
			return self::failed( self::UNAUTHORIZED, $code, $status );
		}
		if ( 403 === $status ) {
			return self::failed( self::FORBIDDEN, $code, $status );
		}
		if ( 404 === $status ) {
			return self::failed( self::NOT_FOUND, $code, $status );
		}
		if ( 409 === $status ) {
			return self::failed( self::CONFLICT, $code, $status );
		}
		if ( 429 === $status ) {
			return self::failed( self::RATE_LIMITED, $code, $status );
		}
		if ( $status >= 500 ) {
			return self::failed( self::SERVER, $code, $status );
		}
		return self::failed( self::INVALID, $code, $status );
	}
}

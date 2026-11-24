<?php
/**
 * What came back from one HTTP call. Plain values only.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

defined( 'ABSPATH' ) || exit;

final class HttpResponse {

	// Failures that happen before there is a usable answer.
	public const ERR_NETWORK  = 'network';
	public const ERR_REDIRECT = 'redirect';
	public const ERR_TOO_BIG  = 'too_big';
	public const ERR_NOT_JSON = 'not_json';
	public const ERR_NO_KEY   = 'no_key';
	public const ERR_BAD_PATH = 'bad_path';
	public const ERR_BAD_BODY = 'bad_body';

	/**
	 * @param int                       $status HTTP status (0 when there was no answer).
	 * @param array<mixed>|null $data   Decoded JSON, when there was some.
	 * @param string                    $error  '' or one of the ERR_* codes.
	 */
	public function __construct(
		public readonly int $status,
		public readonly ?array $data,
		public readonly string $error = ''
	) {}

	/** An answer arrived and is JSON (any status). */
	public function has_answer(): bool {
		return '' === $this->error;
	}

	/**
	 * A failed call.
	 *
	 * @param string $error One of the ERR_* codes.
	 * @param int    $status HTTP status when known.
	 */
	public static function failed( string $error, int $status = 0 ): self {
		return new self( $status, null, $error );
	}
}

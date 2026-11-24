<?php
/**
 * Signed updates (plan 13, D11). Off until Config::UPDATE_PUBLIC_KEY and Config::UPDATE_MANIFEST_URL
 * are set. A release is offered only when its manifest carries a valid Ed25519 signature from the
 * offline release key, and the downloaded zip is installed only when its SHA-256 matches the signed
 * manifest. A hacked update server can't push code to client sites.
 *
 * Manifest: {"version","package","sha256","requires","requires_php","signature"}; the signature
 * (base64) covers the canonical JSON of every other field (sorted keys, no spaces).
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Integrations;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\Core\Validate;

defined( 'ABSPATH' ) || exit;

final class Updates {

	/** True once the release key and manifest address are set in Config (empty in development builds). */
	public static function enabled(): bool {
		return '' !== self::setting( 'UPDATE_PUBLIC_KEY' ) && '' !== self::setting( 'UPDATE_MANIFEST_URL' );
	}

	/**
	 * A release setting from Config, read by name: it is filled in when a release is built, and
	 * deliberately not a filter, so no other plugin can swap the release key.
	 *
	 * @param string $name Config constant name.
	 */
	private static function setting( string $name ): string {
		$value = constant( Config::class . '::' . $name );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * The manifest's fields when the signature is valid and every field is well-formed; null otherwise. Pure.
	 *
	 * @param string $json       Manifest as downloaded.
	 * @param string $public_key Base64 Ed25519 public key.
	 * @return array{version: string, package: string, sha256: string, requires: string, requires_php: string}|null
	 */
	public static function verify_manifest( string $json, string $public_key ): ?array {
		$data = json_decode( $json, true );
		$key  = base64_decode( $public_key, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- public key.
		$sig  = is_array( $data ) && is_string( $data['signature'] ?? null ) ? base64_decode( $data['signature'], true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- signature.
		if ( ! is_array( $data ) || false === $key || false === $sig || SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== strlen( $key ) || SODIUM_CRYPTO_SIGN_BYTES !== strlen( $sig ) ) {
			return null;
		}
		unset( $data['signature'] );
		if ( ! sodium_crypto_sign_verify_detached( $sig, self::canonical( $data ), $key ) ) {
			return null;
		}
		$ok = is_string( $data['version'] ?? null ) && 1 === preg_match( '/^\d+\.\d+\.\d+\z/', $data['version'] )
			&& is_string( $data['package'] ?? null ) && str_starts_with( $data['package'], 'https://' ) && false === strpbrk( $data['package'], " \r\n" )
			&& is_string( $data['sha256'] ?? null ) && 1 === preg_match( '/^[0-9a-f]{64}\z/', $data['sha256'] )
			&& is_string( $data['requires'] ?? null ) && is_string( $data['requires_php'] ?? null );
		return $ok ? array(
			'version'      => $data['version'],
			'package'      => $data['package'],
			'sha256'       => $data['sha256'],
			'requires'     => $data['requires'],
			'requires_php' => $data['requires_php'],
		) : null;
	}

	/**
	 * The exact bytes the release key signs. Pure.
	 *
	 * @param array<string, mixed> $fields Manifest without its signature.
	 */
	public static function canonical( array $fields ): string {
		ksort( $fields );
		return (string) wp_json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * True when the file's SHA-256 is the signed one. Pure (reads the file only).
	 *
	 * @param string $file   Downloaded zip.
	 * @param string $sha256 From the verified manifest.
	 */
	public static function package_matches( string $file, string $sha256 ): bool {
		$hash = is_file( $file ) ? hash_file( 'sha256', $file ) : false;
		return is_string( $hash ) && hash_equals( $sha256, $hash );
	}

	/**
	 * pre_set_site_transient_update_plugins: offers a newer signed release.
	 *
	 * @param mixed $transient WordPress's update data.
	 * @return mixed
	 */
	public static function offer( $transient ) {
		if ( ! self::enabled() || ! is_object( $transient ) ) {
			return $transient;
		}
		$manifest = self::manifest();
		if ( null === $manifest || version_compare( $manifest['version'], Config::VERSION, '<=' ) ) {
			return $transient;
		}
		$file                = plugin_basename( HOSTELMATE_HBOOK_FILE );
		$response            = property_exists( $transient, 'response' ) && is_array( $transient->response ) ? $transient->response : array();
		$response[ $file ]   = (object) array(
			'slug'         => Config::SLUG,
			'plugin'       => $file,
			'new_version'  => $manifest['version'],
			'package'      => $manifest['package'],
			'requires'     => $manifest['requires'],
			'requires_php' => $manifest['requires_php'],
		);
		$transient->response = $response; // @phpstan-ignore property.notFound (WordPress's update transient is a stdClass)
		return $transient;
	}

	/**
	 * upgrader_pre_download: our package is downloaded here and installed only if its hash is the signed one.
	 *
	 * @param mixed  $reply   False to let WordPress download.
	 * @param string $package Package URL.
	 * @return mixed
	 */
	public static function download( $reply, $package ) {
		$manifest = self::enabled() ? self::manifest() : null;
		if ( null === $manifest || ! is_string( $package ) || $package !== $manifest['package'] ) {
			return $reply;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php'; // @phpstan-ignore requireOnce.fileNotFound (WordPress core file)
		$file = download_url( $package, 120 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! self::package_matches( $file, $manifest['sha256'] ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'hostelmate_hbook_bad_package', __( 'The update did not match its signed checksum and was not installed.', 'hostelmate-hbook' ) );
		}
		return $file;
	}

	/**
	 * The verified manifest, fetched at most every 12 hours.
	 *
	 * @return array{version: string, package: string, sha256: string, requires: string, requires_php: string}|null
	 */
	private static function manifest(): ?array {
		$cached = State::get( 'update_manifest' );
		if ( is_array( $cached ) && time() - (int) ( $cached['at'] ?? 0 ) < 12 * HOUR_IN_SECONDS ) {
			return is_string( $cached['json'] ?? null ) ? self::verify_manifest( $cached['json'], self::setting( 'UPDATE_PUBLIC_KEY' ) ) : null;
		}
		$response = wp_remote_get(
			self::setting( 'UPDATE_MANIFEST_URL' ),
			array(
				'timeout'             => 10,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 65536,
			)
		);
		$json     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		State::set(
			'update_manifest',
			array(
				'at'   => time(),
				'json' => $json,
			)
		);
		return '' === $json ? null : self::verify_manifest( $json, self::setting( 'UPDATE_PUBLIC_KEY' ) );
	}
}

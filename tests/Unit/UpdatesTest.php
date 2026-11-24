<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Integrations\Updates;

/** S12: a tampered manifest or zip is refused. */
final class UpdatesTest extends TestCase {

	/** @return array{0: string, 1: string, 2: string} [public key, manifest json, zip path] */
	private static function release( array $override = array() ): array {
		$pair   = sodium_crypto_sign_keypair();
		$zip    = tempnam( sys_get_temp_dir(), 'hmzip' );
		file_put_contents( $zip, 'release bytes' );
		$fields = $override + array( 'package' => 'https://downloads.example/hostelmate-hbook-0.2.0.zip', 'requires' => '6.5', 'requires_php' => '8.2', 'sha256' => hash_file( 'sha256', $zip ), 'version' => '0.2.0' );
		$fields['signature'] = base64_encode( sodium_crypto_sign_detached( Updates::canonical( $fields ), sodium_crypto_sign_secretkey( $pair ) ) );
		return array( base64_encode( sodium_crypto_sign_publickey( $pair ) ), (string) json_encode( $fields ), $zip );
	}

	public function test_a_signed_release_is_accepted(): void {
		list( $key, $json, $zip ) = self::release();
		$m = Updates::verify_manifest( $json, $key );
		$this->assertNotNull( $m );
		$this->assertSame( '0.2.0', $m['version'] );
		$this->assertTrue( Updates::package_matches( $zip, $m['sha256'] ) );
	}

	public function test_any_change_to_the_manifest_breaks_the_signature(): void {
		list( $key, $json ) = self::release();
		$data            = json_decode( $json, true );
		$data['package'] = 'https://evil.example/x.zip';
		$this->assertNull( Updates::verify_manifest( (string) json_encode( $data ), $key ) );
		$data            = json_decode( $json, true );
		$data['version'] = '9.9.9';
		$this->assertNull( Updates::verify_manifest( (string) json_encode( $data ), $key ) );
	}

	public function test_another_key_is_refused(): void {
		list( , $json ) = self::release();
		list( $other_key ) = self::release();
		$this->assertNull( Updates::verify_manifest( $json, $other_key ) );
	}

	public function test_a_changed_zip_is_refused(): void {
		list( $key, $json, $zip ) = self::release();
		file_put_contents( $zip, 'release bytes plus a backdoor' );
		$this->assertFalse( Updates::package_matches( $zip, Updates::verify_manifest( $json, $key )['sha256'] ) );
	}

	public function test_signed_but_unsafe_fields_are_refused(): void {
		list( $key, $json ) = self::release( array( 'package' => 'http://downloads.example/x.zip' ) );
		$this->assertNull( Updates::verify_manifest( $json, $key ) );
		list( $key, $json ) = self::release( array( 'version' => '1.0' ) );
		$this->assertNull( Updates::verify_manifest( $json, $key ) );
		$this->assertNull( Updates::verify_manifest( 'not json', $key ) );
	}

	public function test_off_until_configured(): void {
		$this->assertFalse( Updates::enabled() );
		$t = (object) array( 'response' => array() );
		$this->assertSame( $t, Updates::offer( $t ) );
	}
}

<?php
/**
 * Unit-test bootstrap: tiny in-memory stand-ins for the WordPress functions the
 * core classes call, so tests can see exactly what the code sends and stores.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOSTELMATE_HBOOK_FILE', dirname( __DIR__ ) . '/hostelmate-hbook.php' );
define( 'DB_NAME', 'test' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'AUTH_KEY', str_repeat( 'a1B2', 16 ) );
define( 'SECURE_AUTH_KEY', str_repeat( 'c3D4', 16 ) );

if ( ! extension_loaded( 'sodium' ) ) {
	require dirname( __DIR__ ) . '/tools/vendor/sodium/autoload.php';
}
require dirname( __DIR__ ) . '/src/autoload.php';
require __DIR__ . '/Unit/TestCase.php';

/** Everything the stand-ins record, reset before each test. */
final class WpStub {
	/** @var array<string, mixed> */
	public static array $options = array();
	/** @var array<string, string> */
	public static array $salts = array();
	/** @var list<array{url: string, args: array<string, mixed>}> */
	public static array $requests = array();
	/** @var mixed Next answer from wp_remote_request (array or WP_Error). */
	public static $next_response = null;
	/** @var list<mixed> Answers to give in order (used before $next_response). */
	public static array $queue = array();
	/** @var list<array{to: string, subject: string, body: string}> */
	public static array $mails = array();
	/** wp_doing_cron() answer. */
	public static bool $cron = false;

	public static function reset(): void {
		self::$options       = array( 'admin_email' => 'owner@example.com' );
		self::$salts         = array(
			'auth'        => 'salt-auth-1',
			'secure_auth' => 'salt-secure-1',
		);
		self::$requests      = array();
		self::$next_response = null;
		self::$queue         = array();
		self::$mails         = array();
		self::$cron          = false;
		$GLOBALS['wpdb']     = new FakeWpdb();
	}
}

/** Records inserts; can be told to fail. */
final class FakeWpdb {
	public string $prefix = 'wp_';
	public bool $fail     = false;
	/** @var list<array{table: string, row: array<string, mixed>}> */
	public array $inserted = array();

	/**
	 * @param array<string, mixed> $row
	 * @param list<string>         $formats
	 */
	public function insert( string $table, array $row, array $formats = array() ) {
		if ( $this->fail ) {
			return false;
		}
		$this->inserted[] = array(
			'table' => $table,
			'row'   => $row,
		);
		return 1;
	}
}

class WP_Error {
	public function __construct( public string $code = '', public string $message = '' ) {}
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function get_option( string $name, $fallback = false ) {
	return array_key_exists( $name, WpStub::$options ) ? WpStub::$options[ $name ] : $fallback;
}
function update_option( string $name, $value, $autoload = null ): bool {
	WpStub::$options[ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	unset( WpStub::$options[ $name ] );
	return true;
}
function wp_salt( string $scheme = 'auth' ): string {
	return WpStub::$salts[ $scheme ] ?? 'none';
}
function wp_json_encode( $data, int $flags = 0, int $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}
function wp_remote_request( string $url, array $args = array() ) {
	WpStub::$requests[] = array(
		'url'  => $url,
		'args' => $args,
	);
	if ( array() !== WpStub::$queue ) {
		return array_shift( WpStub::$queue );
	}
	return WpStub::$next_response ?? array(
		'response' => array( 'code' => 200 ),
		'body'     => '{}',
	);
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}
function wp_timezone(): DateTimeZone {
	return new DateTimeZone( 'UTC' );
}
function get_bloginfo( string $show = '' ): string {
	return 'version' === $show ? '7.1.5' : 'Test Hostel';
}
function admin_url( string $path = '' ): string {
	return 'https://example.com/wp-admin/' . $path;
}
function wp_strip_all_tags( string $text ): string {
	return strip_tags( $text );
}
function wp_doing_cron(): bool {
	return WpStub::$cron;
}
function wp_mail( $to, string $subject, string $body ): bool {
	WpStub::$mails[] = array(
		'to'      => (string) $to,
		'subject' => $subject,
		'body'    => $body,
	);
	return true;
}
function remove_accents( string $text ): string {
	$t = iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $text );
	return false === $t ? $text : $t;
}

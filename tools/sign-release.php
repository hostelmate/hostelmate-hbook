<?php
// Dev only, run OFFLINE by the release signer (D11). Needs ext-sodium or the sodium_compat polyfill.
//   php tools/sign-release.php keygen <secret-key-file>      → writes the secret key, prints the public key for Config::UPDATE_PUBLIC_KEY
//   php tools/sign-release.php sign <secret-key-file> <zip> <package-url> <version>   → prints manifest.json
if ( ! function_exists( 'sodium_crypto_sign_keypair' ) ) {
	require dirname( __DIR__ ) . '/tools/vendor/sodium/autoload.php';
}
$cmd = $argv[1] ?? '';
if ( 'keygen' === $cmd && isset( $argv[2] ) && ! file_exists( $argv[2] ) ) {
	$pair = sodium_crypto_sign_keypair();
	umask( 077 );
	file_put_contents( $argv[2], base64_encode( sodium_crypto_sign_secretkey( $pair ) ) );
	echo base64_encode( sodium_crypto_sign_publickey( $pair ) ), "\n";
	exit( 0 );
}
if ( 'sign' === $cmd && 6 === count( $argv ) ) {
	$secret = base64_decode( trim( (string) file_get_contents( $argv[2] ) ), true );
	$fields = array( 'package' => $argv[4], 'requires' => '6.5', 'requires_php' => '8.2', 'sha256' => hash_file( 'sha256', $argv[3] ), 'version' => $argv[5] );
	ksort( $fields );
	$canonical = json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$fields['signature'] = base64_encode( sodium_crypto_sign_detached( $canonical, $secret ) );
	echo json_encode( $fields, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ), "\n";
	exit( 0 );
}
fwrite( STDERR, "usage: keygen <new-secret-file> | sign <secret-file> <zip> <package-url> <version>\n" );
exit( 1 );

<?php
// Dev only. Usage: php tools/build-translations.php <path-to-wordpress>
// Builds languages/hostelmate-hbook-{es_MX,es_ES}.po/.mo from tools/i18n/es.php with WordPress's own gettext classes.
$wp = rtrim( $argv[1] ?? '', '/' );
if ( ! is_file( $wp . '/wp-includes/pomo/po.php' ) ) {
	fwrite( STDERR, "Give the path to a WordPress install.\n" );
	exit( 1 );
}
define( 'ABSPATH', $wp . '/' );
define( 'WPINC', 'wp-includes' );
require $wp . '/wp-includes/compat.php';
require $wp . '/wp-includes/pomo/po.php';
require $wp . '/wp-includes/pomo/mo.php';
$root    = dirname( __DIR__ );
$strings = require $root . '/tools/i18n/es.php';
foreach ( array( 'es_MX', 'es_ES' ) as $locale ) {
	foreach ( array( 'PO', 'MO' ) as $class ) {
		$file = new $class();
		$file->set_header( 'Content-Type', 'text/plain; charset=UTF-8' );
		$file->set_header( 'Language', $locale );
		$file->set_header( 'Plural-Forms', 'nplurals=2; plural=(n != 1);' );
		foreach ( $strings as $en => $es ) {
			$file->add_entry( new Translation_Entry( array( 'singular' => $en, 'translations' => array( $es ) ) ) );
		}
		$path = $root . '/languages/hostelmate-hbook-' . $locale . '.' . strtolower( $class );
		$file->export_to_file( $path ) or exit( "could not write $path\n" );
		echo "wrote $path\n";
	}
}

<?php
/**
 * The one autoloader, shared by the main file and uninstall.php.
 * HostelMate\HBook\Core\Log → src/Core/Log.php. Nothing else is ever loaded.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'HostelMate\\HBook\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		// Letters, digits and namespace separators only: no "..", no slashes.
		if ( 1 !== preg_match( '/^[A-Za-z0-9]+(\\\\[A-Za-z0-9]+)*\z/', $relative ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require_once $file;
		}
	}
);

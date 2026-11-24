<?php
/**
 * Removes everything the plugin added. Runs only from uninstall.php.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;

defined( 'ABSPATH' ) || exit;

final class Uninstaller {

	/** The plugin's HBook rows (when HBook is still there), cron events, own tables, own options. */
	public static function run(): void {
		Lifecycle::deactivate();
		try {
			if ( array() === array_filter( \HostelMate\HBook\HBook\Schema::problems(), static fn( string $p ): bool => str_starts_with( $p, 'missing_table:' ) ) ) {
				\HostelMate\HBook\HBook\Writer::remove_everything();
			}
		} catch ( \Throwable $e ) {
			// Keep the list of what the plugin wrote, so installing it again and pressing Remove can finish the job.
			Guard::report( $e );
			return;
		}
		Db::drop_all();
		foreach ( Config::OPTIONS as $option ) {
			delete_option( $option );
		}
	}
}

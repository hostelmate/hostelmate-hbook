<?php
/**
 * Open alerts: a short notice on every admin page (for admins), and the full list
 * on the Overview.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Notices {

	/** admin_notices: one line when red alerts are open (not on the plugin's own pages). */
	public static function render(): void {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compares the page name.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( str_starts_with( $page, Config::PAGE_OVERVIEW ) ) {
			return;
		}
		$red = count( array_filter( Alerts::list(), static fn( array $a ): bool => Config::ALERT_LEVEL_RED === $a['level'] ) );
		if ( 0 === $red ) {
			return;
		}
		/* translators: %d: number of open problems */
		$text = sprintf( _n( 'HostelMate: %d problem needs you.', 'HostelMate: %d problems need you.', $red, 'hostelmate-hbook' ), $red );
		echo '<div class="notice notice-error"><p>' . esc_html( $text ) . ' <a href="' . esc_url( View::page_url( Config::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Open HostelMate', 'hostelmate-hbook' ) . '</a></p></div>';
	}

	/** Every open alert, red first (Overview). */
	public static function render_list(): void {
		foreach ( Alerts::list() as $alert ) {
			$text = Messages::text( $alert['code'] ) . ( '' !== $alert['ref'] ? ' (' . $alert['ref'] . ')' : '' );
			View::notice( Config::ALERT_LEVEL_RED === $alert['level'] ? 'error' : 'warning', $text );
		}
	}
}

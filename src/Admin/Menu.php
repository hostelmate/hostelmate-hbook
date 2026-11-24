<?php
/**
 * The plugin's admin menu: Overview, Rooms, Activity, Settings.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Guard;

defined( 'ABSPATH' ) || exit;

final class Menu {

	/** The HostelMate logo as a one-colour SVG: WordPress paints it in the admin colour scheme, like its own icons. */
	private const ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik0xNi4zNjQgMy42MzZBOSA5IDAgMSAxIDYuOTIyIDEuNTQzTDcuNTM4IDMuMjM0QTcuMiA3LjIgMCAxIDAgMTUuMDkxIDQuOTA5WiIvPjxwYXRoIGZpbGw9ImJsYWNrIiBkPSJNNC4wOTEgOC45NThBNiA2IDAgMSAxIDguOTU4IDE1LjkwOUw5LjIzNiAxNC4zMzNBNC40IDQuNCAwIDEgMCA1LjY2NyA5LjIzNloiLz48cGF0aCBmaWxsPSJibGFjayIgZmlsbC1ydWxlPSJldmVub2RkIiBkPSJNMTAgNy4xQTIuOSAyLjkgMCAxIDEgMTAgMTIuOUEyLjkgMi45IDAgMSAxIDEwIDcuMVpNMTAgOC41QTEuNSAxLjUgMCAxIDAgMTAgMTEuNUExLjUgMS41IDAgMSAwIDEwIDguNVoiLz48L3N2Zz4K';

	/**
	 * Adds the menu (capability-checked by WordPress and again by each page). Until the
	 * website is live there is one page, the guided setup; the others appear after.
	 */
	public static function register(): void {
		add_menu_page(
			__( 'HB-HostelMate', 'hostelmate-hbook' ),
			__( 'HB-HostelMate', 'hostelmate-hbook' ),
			Config::CAPABILITY,
			Config::PAGE_OVERVIEW,
			Guard::wrap( array( OverviewPage::class, 'render' ) ),
			self::ICON,
			58
		);
		if ( ! Setup::done() ) {
			return;
		}
		// Same slug as the top-level page: no callback of its own, or the page would print twice.
		add_submenu_page( Config::PAGE_OVERVIEW, __( 'Overview', 'hostelmate-hbook' ), __( 'Overview', 'hostelmate-hbook' ), Config::CAPABILITY, Config::PAGE_OVERVIEW );
		add_submenu_page( Config::PAGE_OVERVIEW, __( 'Rooms', 'hostelmate-hbook' ), __( 'Rooms', 'hostelmate-hbook' ), Config::CAPABILITY, Config::PAGE_ROOMS, Guard::wrap( array( RoomsPage::class, 'render' ) ) );
		add_submenu_page( Config::PAGE_OVERVIEW, __( 'Activity', 'hostelmate-hbook' ), __( 'Activity', 'hostelmate-hbook' ), Config::CAPABILITY, Config::PAGE_ACTIVITY, Guard::wrap( array( ActivityPage::class, 'render' ) ) );
		add_submenu_page( Config::PAGE_OVERVIEW, __( 'Settings', 'hostelmate-hbook' ), __( 'Settings', 'hostelmate-hbook' ), Config::CAPABILITY, Config::PAGE_SETTINGS, Guard::wrap( array( SettingsPage::class, 'render' ) ) );
	}
}

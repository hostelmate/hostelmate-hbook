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

	/** Adds the menu and its pages (capability-checked by WordPress and again by each page). */
	public static function register(): void {
		add_menu_page(
			__( 'HostelMate', 'hostelmate-hbook' ),
			__( 'HostelMate', 'hostelmate-hbook' ),
			Config::CAPABILITY,
			Config::PAGE_OVERVIEW,
			Guard::wrap( array( OverviewPage::class, 'render' ) ),
			'dashicons-update',
			58
		);
		// Same slug as the top-level page: no callback of its own, or the page would print twice.
		add_submenu_page( Config::PAGE_OVERVIEW, __( 'Overview', 'hostelmate-hbook' ), __( 'Overview', 'hostelmate-hbook' ), Config::CAPABILITY, Config::PAGE_OVERVIEW );
		add_submenu_page( Config::PAGE_OVERVIEW, __( 'Rooms', 'hostelmate-hbook' ), __( 'Rooms', 'hostelmate-hbook' ), Config::CAPABILITY, Config::PAGE_ROOMS, Guard::wrap( array( RoomsPage::class, 'render' ) ) );
		add_submenu_page( Config::PAGE_OVERVIEW, __( 'Activity', 'hostelmate-hbook' ), __( 'Activity', 'hostelmate-hbook' ), Config::CAPABILITY, Config::PAGE_ACTIVITY, Guard::wrap( array( ActivityPage::class, 'render' ) ) );
		add_submenu_page( Config::PAGE_OVERVIEW, __( 'Settings', 'hostelmate-hbook' ), __( 'Settings', 'hostelmate-hbook' ), Config::CAPABILITY, Config::PAGE_SETTINGS, Guard::wrap( array( SettingsPage::class, 'render' ) ) );
	}
}

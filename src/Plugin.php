<?php
/**
 * The single list of every hook the plugin uses. Each callback runs inside Guard.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook;

use HostelMate\HBook\Admin\ActionRouter;
use HostelMate\HBook\Admin\Menu;
use HostelMate\HBook\Admin\Notices;
use HostelMate\HBook\Core\Guard;
use HostelMate\HBook\Core\Lifecycle;
use HostelMate\HBook\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Registers everything. Called once from the main plugin file. */
	public static function boot(): void {
		// Activation is not wrapped: a failed requirement must stop activation visibly.
		register_activation_hook( HOSTELMATE_HBOOK_FILE, array( Lifecycle::class, 'activate' ) );
		register_deactivation_hook( HOSTELMATE_HBOOK_FILE, Guard::wrap( array( Lifecycle::class, 'deactivate' ) ) );

		add_action( 'plugins_loaded', Guard::wrap( array( Lifecycle::class, 'upgrade' ) ) );

		// Timers.
		add_filter( 'cron_schedules', Guard::wrap( array( Scheduler::class, 'schedules' ) ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- 5 minutes, declared in Config::SYNC_EVERY.
		add_action( 'init', Guard::wrap( array( Scheduler::class, 'ensure' ) ) );
		add_action( Config::CRON_SYNC, Guard::wrap( array( Scheduler::class, 'sync' ) ) );
		add_action( Config::CRON_BOOKINGS, Guard::wrap( array( Scheduler::class, 'bookings' ) ) );
		add_action( Config::CRON_DAILY, Guard::wrap( array( Scheduler::class, 'daily' ) ) );

		// HBook booking changes → a booking run at the end of the request.
		\HostelMate\HBook\HBook\Hooks::register();

		// The check before a website booking is saved (in front of HBook's own handler).
		\HostelMate\HBook\HBook\Precheck::register();

		add_action( 'init', Guard::wrap( array( self::class, 'load_translations' ) ) );

		// Signed updates (off until the release key and manifest address are set).
		add_filter( 'pre_set_site_transient_update_plugins', Guard::wrap( array( \HostelMate\HBook\Integrations\Updates::class, 'offer' ) ) );
		add_filter( 'upgrader_pre_download', Guard::wrap( array( \HostelMate\HBook\Integrations\Updates::class, 'download' ) ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'admin_init', Guard::wrap( array( self::class, 'privacy_text' ) ) );
			add_action( 'admin_menu', Guard::wrap( array( Menu::class, 'register' ) ) );
			add_action( 'admin_notices', Guard::wrap( array( Notices::class, 'render' ) ) );
			add_action( 'admin_init', Guard::wrap( array( Scheduler::class, 'check_late' ) ) );
			add_action( 'admin_post_' . Config::ADMIN_POST_ACTION, Guard::wrap( array( ActionRouter::class, 'handle' ) ) );
		}
	}

	/** The plugin's own translations (the guest message ships in Spanish). */
	public static function load_translations(): void {
		load_plugin_textdomain( Config::TEXT_DOMAIN, false, dirname( plugin_basename( HOSTELMATE_HBOOK_FILE ) ) . '/languages' );
	}

	/** Suggested text for the site's privacy policy (Settings → Privacy). */
	public static function privacy_text(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				'HostelMate for HBook',
				'<p>' . esc_html__( 'When you book on this website, your booking (dates, room, price, payments) and your name, email address, phone number and country are sent to HostelMate, the system this property uses to manage its bookings. HostelMate keeps them to run your stay.', 'hostelmate-hbook' ) . '</p>'
			);
		}
	}
}

<?php
/**
 * When the sync runs: every Config::SYNC_EVERY seconds through WP-Cron while in preview or
 * live, a daily log prune, and the "late" warning when runs stop happening.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Scheduler {

	/**
	 * Adds the plugin's interval to WP-Cron.
	 *
	 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function schedules( array $schedules ): array {
		$schedules[ Config::CRON_SCHEDULE ] = array(
			'interval' => Config::SYNC_EVERY,
			'display'  => 'HostelMate (5 minutes)',
		);
		return $schedules;
	}

	/** Keeps the events scheduled exactly when they should run. */
	public static function ensure(): void {
		$running = in_array( Settings::mode(), array( Config::MODE_PREVIEW, Config::MODE_LIVE ), true );
		if ( $running && false === wp_next_scheduled( Config::CRON_SYNC ) ) {
			wp_schedule_event( time() + 30, Config::CRON_SCHEDULE, Config::CRON_SYNC );
		}
		if ( ! $running && false !== wp_next_scheduled( Config::CRON_SYNC ) ) {
			wp_clear_scheduled_hook( Config::CRON_SYNC );
		}
		$live = Config::MODE_LIVE === Settings::mode();
		if ( $live && false === wp_next_scheduled( Config::CRON_BOOKINGS ) ) {
			wp_schedule_event( time() + 60, Config::CRON_SCHEDULE, Config::CRON_BOOKINGS );
		}
		if ( ! $live && false !== wp_next_scheduled( Config::CRON_BOOKINGS ) ) {
			wp_clear_scheduled_hook( Config::CRON_BOOKINGS );
		}
		if ( false === wp_next_scheduled( Config::CRON_DAILY ) ) {
			wp_schedule_event( time() + 3600, 'daily', Config::CRON_DAILY );
		}
	}

	/** CRON_SYNC callback. */
	public static function sync(): void {
		SyncRunner::run( false );
	}

	/** CRON_BOOKINGS callback. */
	public static function bookings(): void {
		BookingRunner::run();
	}

	/** CRON_DAILY callback. */
	public static function daily(): void {
		Log::prune();
	}

	/**
	 * init, on every request: warns when a live site hasn't synced for a while. Found live 2026-11-24: it ran on
	 * admin pages only, so with WordPress's timer dead and nobody opening the admin, nobody was told. A visitor's
	 * page now raises it too (the email goes out at the end of that request). It writes only when the alert
	 * changes, so a busy site doesn't save an option on every page while it is late.
	 */
	public static function check_late(): void {
		if ( Config::MODE_LIVE !== Settings::mode() ) {
			return;
		}
		$age  = time() - (int) State::get( 'last_ok', time() );
		$want = $age > Config::VERY_LATE_AFTER ? Messages::SYNC_VERY_LATE : ( $age > Config::LATE_AFTER ? Messages::SYNC_LATE : '' );
		$open = array_column( Alerts::list(), 'code' );
		if ( '' !== $want && ! in_array( $want, $open, true ) ) {
			Alerts::open( $want, Messages::SYNC_VERY_LATE === $want ? Config::ALERT_LEVEL_RED : Config::ALERT_LEVEL_AMBER );
		}
	}
}

<?php
/**
 * HBook's hooks that mean "a booking may have changed". Each one only asks for a booking run
 * at the end of the request; the run itself re-reads everything (hooks can be missed).
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Core\Guard;
use HostelMate\HBook\Sync\BookingRunner;

defined( 'ABSPATH' ) || exit;

final class Hooks {

	/** HBook actions (hbook/database-actions/database-actions.php, front-end-ajax-actions.php). */
	public const HBOOK_ACTIONS = array( 'hb_create_reservation', 'hb_reservation_updated', 'hb_reservations_updated', 'hb_parent_reservation_updated', 'hb_parent_reservation_cancelled' );

	/** Registers them. */
	public static function register(): void {
		foreach ( self::HBOOK_ACTIONS as $hook ) {
			add_action( $hook, Guard::wrap( array( BookingRunner::class, 'soon' ) ), 99, 0 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- HBook's own hooks.
		}
	}
}

<?php
/**
 * HBook status → what HostelMate should have (plan 7.2). The one place this rule lives.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\HBook\Occupancy;

defined( 'ABSPATH' ) || exit;

final class StatusRules {

	public const WAIT      = 'wait';      // Not yet: the card step isn't done, or HBook holds no unit.
	public const RESERVED  = 'reserved';  // HostelMate status for a request (HBook 'new').
	public const CONFIRMED = 'confirmed'; // HostelMate status for a confirmed booking (D22).
	public const CANCELLED = 'cancelled';

	/**
	 * What HostelMate should hold for an HBook status (null = the row is gone).
	 *
	 * @param string|null $status     hb_resa.status, or null when the row was deleted.
	 * @param bool        $paid_needs HBook's hb_resa_paid_has_confirmation.
	 */
	public static function wanted( ?string $status, bool $paid_needs ): string {
		if ( null === $status || 'cancelled' === $status ) {
			return self::CANCELLED;
		}
		if ( in_array( $status, array( 'waiting_payment', 'multi_resa_pending', 'pending' ), true ) ) {
			return self::WAIT;
		}
		if ( 'confirmed' === $status ) {
			return self::CONFIRMED;
		}
		// 'new' (a request) and any status HBook may add later: HBook holds the unit, so HostelMate must too.
		return Occupancy::holds_unit( $status, $paid_needs ) ? self::RESERVED : self::WAIT;
	}
}

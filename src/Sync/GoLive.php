<?php
/**
 * The go-live checklist (plan 9.1), declared once: the Overview shows it and the go-live
 * action enforces it.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\HBook\Options;
use HostelMate\HBook\HBook\Schema;
use HostelMate\HBook\HBook\Units;

defined( 'ABSPATH' ) || exit;

final class GoLive {

	/**
	 * Every item, with whether it passes. Only "required" items block going live (at
	 * least one matched room: without it the plugin has nothing to do); the others are
	 * warnings the owner can accept with a tick.
	 *
	 * @return list<array{ok: bool, required: bool, text: string}>
	 */
	public static function checklist(): array {
		$preview  = State::get( 'preview' );
		$fresh    = is_array( $preview ) && time() - (int) $preview['at'] < Config::PREVIEW_FRESH_FOR;
		$property = State::get( 'property' );
		$mapping  = Mapping::get();
		$errors   = is_array( $property )
			? array_filter( Mapping::problems( $mapping, $property, Units::all() ), static fn( array $p ): bool => Mapping::LEVEL_ERROR === $p['level'] )
			: array( 'no property' );
		$ical     = Options::ical_calendar_count();
		return array(
			array(
				'ok'       => array() !== $mapping && array() === $errors,
				'required' => true,
				'text'     => __( 'At least one room is matched, with no mistakes in the matching (step 2). Required.', 'hostelmate-hbook' ),
			),
			array(
				'ok'       => Secret::has() && time() - (int) State::get( 'last_fetch_ok', 0 ) < Config::PREVIEW_FRESH_FOR,
				'required' => false,
				'text'     => __( 'HostelMate answered in the last 30 minutes.', 'hostelmate-hbook' ),
			),
			array(
				'ok'       => array() === Schema::problems(),
				'required' => false,
				'text'     => __( 'Your website\'s booking tables are in order.', 'hostelmate-hbook' ),
			),
			array(
				'ok'       => $fresh,
				'required' => false,
				'text'     => __( 'The check ran in the last 30 minutes (step 3).', 'hostelmate-hbook' ),
			),
			array(
				'ok'       => $fresh && array() === (array) $preview['foreign_high'],
				'required' => false,
				'text'     => __( 'No other plugin changes your prices (turn off any automatic last-minute prices).', 'hostelmate-hbook' ),
			),
			array(
				'ok'       => $fresh && 0 === (int) $preview['mismatch_total'],
				'required' => false,
				'text'     => __( 'HostelMate knows every booking on your matched rooms.', 'hostelmate-hbook' ),
			),
			array(
				'ok'       => 0 === $ical,
				'required' => false,
				/* translators: %d: number of iCal calendars */
				'text'     => sprintf( __( 'No iCal calendars sync with HBook (now %d). Channels on HostelMate must not also sync by iCal: remove them on HBook\'s iCal page.', 'hostelmate-hbook' ), $ical ),
			),
		);
	}

	/**
	 * Whether going live is allowed: every required item passes, and every warning
	 * passes or the owner accepted the warnings. Pure: the tests call it directly.
	 *
	 * @param list<array{ok: bool, required: bool}> $items    checklist().
	 * @param bool                                  $accepted The owner ticked "go live anyway".
	 */
	public static function allowed( array $items, bool $accepted ): bool {
		foreach ( $items as $item ) {
			if ( ! $item['ok'] && ( $item['required'] || ! $accepted ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * True when going live is allowed now.
	 *
	 * @param bool $accepted See allowed().
	 */
	public static function ready( bool $accepted = false ): bool {
		return self::allowed( self::checklist(), $accepted );
	}
}

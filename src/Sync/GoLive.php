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
	 * Every item, with whether it passes.
	 *
	 * @param bool $ical_confirmed The owner ticked "these iCal calendars are for channels not on HostelMate".
	 * @return list<array{ok: bool, text: string}>
	 */
	public static function checklist( bool $ical_confirmed = false ): array {
		$preview  = State::get( 'preview' );
		$fresh    = is_array( $preview ) && time() - (int) $preview['at'] < Config::PREVIEW_FRESH_FOR;
		$property = State::get( 'property' );
		$mapping  = Mapping::get();
		$errors   = is_array( $property )
			? array_filter( Mapping::problems( $mapping, $property, Units::all() ), static fn( array $p ): bool => Mapping::LEVEL_ERROR === $p['level'] )
			: array( 'no property' );
		$ical     = Options::ical_calendar_count();
		/* translators: %d: number of iCal calendars */
		$ical_text = sprintf( __( 'iCal: %d calendars still sync with HBook. Channels on HostelMate must not also sync by iCal; remove them on HBook\'s iCal page, or confirm they are for channels not on HostelMate.', 'hostelmate-hbook' ), $ical );
		return array(
			array(
				'ok'   => Secret::has() && time() - (int) State::get( 'last_fetch_ok', 0 ) < Config::PREVIEW_FRESH_FOR,
				'text' => __( 'HostelMate answered in the last 30 minutes with this key.', 'hostelmate-hbook' ),
			),
			array(
				'ok'   => array() === Schema::problems(),
				'text' => __( 'HBook\'s tables are as expected (InnoDB).', 'hostelmate-hbook' ),
			),
			array(
				'ok'   => array() !== $mapping && array() === $errors,
				'text' => __( 'Rooms are matched, with no errors.', 'hostelmate-hbook' ),
			),
			array(
				'ok'   => $fresh,
				'text' => __( 'A preview from the last 30 minutes (press Check now).', 'hostelmate-hbook' ),
			),
			array(
				'ok'   => $fresh && array() === (array) $preview['foreign_high'],
				'text' => __( 'No other plugin writes high-priority seasons (switch off automatic last-minute prices).', 'hostelmate-hbook' ),
			),
			array(
				'ok'   => $fresh && 0 === (int) $preview['mismatch_total'],
				'text' => __( 'No mismatches: HostelMate knows every booking and block HBook holds.', 'hostelmate-hbook' ),
			),
			array(
				'ok'   => 0 === $ical || $ical_confirmed,
				/* translators: %d: number of iCal calendars */
				'text' => sprintf( __( 'iCal: %d calendars still sync with HBook. Channels on HostelMate must not also sync by iCal; remove them on HBook\'s iCal page, or confirm they are for channels not on HostelMate.', 'hostelmate-hbook' ), $ical ),
			),
		);
	}

	/**
	 * True when every item passes.
	 *
	 * @param bool $ical_confirmed See checklist().
	 */
	public static function ready( bool $ical_confirmed = false ): bool {
		return array() === array_filter( self::checklist( $ical_confirmed ), static fn( array $i ): bool => ! $i['ok'] );
	}
}

<?php
/**
 * Overview: the setup steps (Connect, Rooms, Preview) until live, then the status.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\HBook\Options;
use HostelMate\HBook\HBook\Tools;
use HostelMate\HBook\HBook\Units;
use HostelMate\HBook\Sync\BookingRunner;
use HostelMate\HBook\Sync\GoLive;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class OverviewPage {

	/** Prints the page. */
	public static function render(): void {
		View::guard_page();
		echo '<div class="wrap"><h1>' . esc_html__( 'HostelMate', 'hostelmate-hbook' ) . '</h1>';
		View::action_notices();
		Notices::render_list();

		if ( ! Secret::has() ) {
			self::connect_step();
			echo '</div>';
			return;
		}
		self::status_line();
		self::property_card();
		self::rooms_step();
		self::preview_step();
		self::bookings_to_send();
		self::copies_tool();
		self::live_step();
		echo '</div>';
	}

	/** One line saying what the plugin is doing now. */
	private static function status_line(): void {
		$mode = Settings::mode();
		$text = match ( $mode ) {
			Config::MODE_LIVE   => __( 'Live: your website follows HostelMate.', 'hostelmate-hbook' ),
			Config::MODE_PAUSED => Messages::text( Messages::PAUSED ),
			default             => __( 'Setup: nothing on your website has been changed.', 'hostelmate-hbook' ),
		};
		$last = State::get( 'last_ok' );
		if ( is_int( $last ) ) {
			/* translators: %s: date and time */
			$text .= ' ' . sprintf( __( 'Last good check: %s.', 'hostelmate-hbook' ), (string) wp_date( 'Y-m-d H:i', $last ) );
		}
		View::notice( Config::MODE_PAUSED === $mode ? 'warning' : ( Config::MODE_LIVE === $mode ? 'success' : 'info' ), $text );
	}

	/** The website bookings HostelMate gets at go-live (numbers and dates only, never guest names). */
	private static function bookings_to_send(): void {
		if ( Config::MODE_LIVE === Settings::mode() ) {
			return;
		}
		$today     = \HostelMate\HBook\Core\Clock::today( \HostelMate\HBook\Core\Clock::site_zone() );
		$targets   = BookingRunner::targets();
		$rows      = array();
		$unmatched = 0;
		foreach ( \HostelMate\HBook\HBook\Bookings::website_since( $today ) as $b ) {
			$wanted = \HostelMate\HBook\Sync\StatusRules::wanted( $b['status'], false );
			if ( $b['check_out'] <= $today || ! in_array( $wanted, array( \HostelMate\HBook\Sync\StatusRules::RESERVED, \HostelMate\HBook\Sync\StatusRules::CONFIRMED ), true ) ) {
				continue;
			}
			if ( null === BookingRunner::target( $targets, $b ) ) {
				++$unmatched;
				continue;
			}
			$rows[] = array( '#' . $b['number'], $b['check_in'], $b['check_out'], $wanted );
		}
		if ( $unmatched > 0 ) {
			/* translators: %d: number of bookings */
			View::notice( 'warning', sprintf( _n( '%d website booking is on an HBook room that isn\'t matched to HostelMate, so HostelMate won\'t get it. Match the room.', '%d website bookings are on HBook rooms that aren\'t matched to HostelMate, so HostelMate won\'t get them. Match the rooms.', $unmatched, 'hostelmate-hbook' ), $unmatched ) );
		}
		self::table(
			/* translators: %d: number of bookings */
			sprintf( __( 'Website bookings HostelMate will get at go-live (%d)', 'hostelmate-hbook' ), count( $rows ) ),
			array( __( 'HBook booking', 'hostelmate-hbook' ), __( 'Check-in', 'hostelmate-hbook' ), __( 'Check-out', 'hostelmate-hbook' ), __( 'Status in HostelMate', 'hostelmate-hbook' ) ),
			$rows
		);
	}

	/** D8: cancel the iCal copies (with undo), once HostelMate provably has them. */
	private static function copies_tool(): void {
		$copies = Tools::ical_copies();
		$undo   = Tools::undo_count();
		if ( array() === $copies && 0 === $undo ) {
			return;
		}
		echo '<h2>' . esc_html__( 'iCal copies in HBook', 'hostelmate-hbook' ) . '</h2>';
		if ( array() !== $copies ) {
			$parts = array();
			foreach ( $copies as $origin => $n ) {
				$parts[] = $origin . ': ' . $n;
			}
			/* translators: %s: counts per channel, e.g. "airbnb: 484" */
			echo '<p>' . esc_html( sprintf( __( 'Future bookings copied in by iCal from channels: %s. Once those channels are on HostelMate, HBook no longer hears about their changes, so a cancelled copy would block a bed for nothing. When the check shows no mismatches, HostelMate has them all and the copies can go.', 'hostelmate-hbook' ), implode( ', ', $parts ) ) ) . '</p>';
			View::form_open( Config::ACTION_CANCEL_COPIES );
			echo '<p><label><input name="confirm" type="checkbox" value="1"> ' . esc_html__( 'Cancel these copies in HBook (an undo list is kept).', 'hostelmate-hbook' ) . '</label></p>';
			View::form_close( __( 'Cancel iCal copies', 'hostelmate-hbook' ), 'button-secondary' );
		}
		if ( $undo > 0 ) {
			View::form_open( Config::ACTION_UNDO_COPIES );
			/* translators: %d: number of copies */
			View::form_close( sprintf( __( 'Undo: bring back %d copies', 'hostelmate-hbook' ), $undo ), 'button-secondary' );
		}
	}

	/** Step 4: the go-live checklist, or Pause/Resume and a held change. */
	private static function live_step(): void {
		$mode = Settings::mode();
		if ( is_array( State::get( 'held' ) ) ) {
			echo '<h2>' . esc_html__( 'A large change is on hold', 'hostelmate-hbook' ) . '</h2>';
			echo '<p>' . esc_html( Messages::text( Messages::SYNC_HELD ) ) . '</p>';
			View::form_open( Config::ACTION_APPLY_HELD );
			View::form_close( __( 'Apply it', 'hostelmate-hbook' ) );
			View::form_open( Config::ACTION_DISCARD_HELD );
			View::form_close( __( 'Don\'t apply', 'hostelmate-hbook' ), 'button-secondary' );
		}
		if ( Config::MODE_LIVE === $mode ) {
			echo '<h2>' . esc_html__( 'Live', 'hostelmate-hbook' ) . '</h2>';
			View::form_open( Config::ACTION_PAUSE );
			View::form_close( __( 'Pause', 'hostelmate-hbook' ), 'button-secondary' );
			return;
		}
		if ( Config::MODE_PAUSED === $mode ) {
			echo '<h2>' . esc_html__( 'Paused', 'hostelmate-hbook' ) . '</h2>';
			View::form_open( Config::ACTION_RESUME );
			View::form_close( __( 'Resume', 'hostelmate-hbook' ) );
			return;
		}
		echo '<h2>' . esc_html__( 'Step 4: Go live', 'hostelmate-hbook' ) . '</h2><ul>';
		foreach ( GoLive::checklist() as $item ) {
			echo '<li>' . ( $item['ok'] ? '&#10003; ' : '&#10007; ' ) . esc_html( $item['text'] ) . '</li>';
		}
		echo '</ul>';
		View::form_open( Config::ACTION_GO_LIVE );
		if ( Options::ical_calendar_count() > 0 ) {
			echo '<p><label><input name="ical_ok" type="checkbox" value="1"> ' . esc_html__( 'The iCal calendars still in HBook are for channels that are NOT on HostelMate.', 'hostelmate-hbook' ) . '</label></p>';
		}
		View::form_close( __( 'Go live', 'hostelmate-hbook' ) );
	}

	/** Step 1: paste the key. */
	private static function connect_step(): void {
		echo '<h2>' . esc_html__( 'Step 1: Connect', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'Paste your HostelMate API key. It is checked with HostelMate before it is saved, stored encrypted, and never shown again.', 'hostelmate-hbook' ) . '</p>';
		echo '<p>' . esc_html__( 'Nothing on your website changes until you go live at the end of the setup.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_CONNECT );
		echo '<p><label for="hm-api-key">' . esc_html__( 'HostelMate API key', 'hostelmate-hbook' ) . '</label><br>';
		echo '<input id="hm-api-key" name="api_key" type="password" class="regular-text" autocomplete="off" spellcheck="false" required></p>';
		View::form_close( __( 'Connect', 'hostelmate-hbook' ) );
	}

	/** Step 1 done: what the key is connected to. */
	private static function property_card(): void {
		$property = State::get( 'property' );
		echo '<h2>' . esc_html__( 'Step 1: Connected', 'hostelmate-hbook' ) . '</h2>';
		if ( ! is_array( $property ) ) {
			echo '<p>' . esc_html__( 'The rooms haven\'t been read from HostelMate yet.', 'hostelmate-hbook' ) . '</p>';
		} else {
			$rooms = is_array( $property['rooms'] ?? null ) ? $property['rooms'] : array();
			$beds  = 0;
			foreach ( $rooms as $room ) {
				$beds += is_array( $room['beds'] ?? null ) ? count( $room['beds'] ) : 0;
			}
			echo '<table class="widefat striped" style="max-width:640px"><tbody>';
			self::row( __( 'Property', 'hostelmate-hbook' ), (string) ( $property['name'] ?? '' ) );
			self::row( __( 'Currency', 'hostelmate-hbook' ), (string) ( $property['currency'] ?? '' ) );
			self::row( __( 'Time zone', 'hostelmate-hbook' ), '' !== (string) ( $property['timezone'] ?? '' ) ? (string) $property['timezone'] : __( 'not set in HostelMate', 'hostelmate-hbook' ) );
			/* translators: 1: number of rooms, 2: number of beds or units */
			self::row( __( 'Rooms', 'hostelmate-hbook' ), sprintf( __( '%1$d rooms, %2$d beds or units', 'hostelmate-hbook' ), count( $rooms ), $beds ) );
			echo '</tbody></table>';
		}
		View::form_open( Config::ACTION_REFRESH_ROOMS );
		View::form_close( __( 'Read rooms from HostelMate again', 'hostelmate-hbook' ), 'button-secondary' );
	}

	/** Step 2: how many HBook units are matched. */
	private static function rooms_step(): void {
		$total   = 0;
		$matched = 0;
		foreach ( Units::all() as $accom ) {
			$total += count( $accom['units'] );
		}
		foreach ( Mapping::get() as $list ) {
			$matched += count( $list );
		}
		echo '<h2>' . esc_html__( 'Step 2: Rooms', 'hostelmate-hbook' ) . '</h2>';
		/* translators: 1: matched units, 2: all HBook units */
		echo '<p>' . esc_html( sprintf( __( '%1$d of %2$d HBook rooms and beds are matched to HostelMate.', 'hostelmate-hbook' ), $matched, $total ) ) . ' ';
		echo '<a href="' . esc_url( View::page_url( Config::PAGE_ROOMS ) ) . '">' . esc_html__( 'Match rooms', 'hostelmate-hbook' ) . '</a></p>';
	}

	/** Step 3: what would change, from the last check. */
	private static function preview_step(): void {
		echo '<h2>' . esc_html__( 'Step 3: Preview', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'Reads HostelMate and shows what would change on your website. Nothing is changed yet.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_CHECK_NOW );
		View::form_close( __( 'Check now', 'hostelmate-hbook' ) );

		$p = State::get( 'preview' );
		if ( ! is_array( $p ) ) {
			return;
		}
		/* translators: 1: date, 2: first night, 3: last night, 4: number of nights */
		echo '<p>' . esc_html( sprintf( __( 'Last check %1$s, nights %2$s to %3$s (%4$d nights).', 'hostelmate-hbook' ), (string) wp_date( 'Y-m-d H:i', (int) $p['at'] ), (string) $p['from'], (string) $p['to'], (int) $p['nights'] ) ) . '</p>';

		if ( array() !== (array) $p['foreign_high'] ) {
			View::notice( 'error', Messages::text( Messages::SYNC_FOREIGN_HIGH ) . ' (' . implode( ', ', array_slice( (array) $p['foreign_high'], 0, 10 ) ) . ')' );
		}
		self::table(
			/* translators: %d: number of price changes */
			sprintf( __( 'Price changes (%d)', 'hostelmate-hbook' ), (int) $p['prices_total'] ),
			array( __( 'Room', 'hostelmate-hbook' ), __( 'Now', 'hostelmate-hbook' ), __( 'From HostelMate', 'hostelmate-hbook' ), __( 'Nights', 'hostelmate-hbook' ), __( 'From', 'hostelmate-hbook' ) ),
			self::cells( (array) $p['prices'], array( 'room', 'old', 'new', 'nights', 'first' ) )
		);
		self::table(
			/* translators: %d: number of rooms */
			sprintf( __( 'Nights that will close (%d rooms or beds)', 'hostelmate-hbook' ), count( (array) $p['closes'] ) ),
			array( __( 'Room', 'hostelmate-hbook' ), __( 'Nights', 'hostelmate-hbook' ), __( 'From', 'hostelmate-hbook' ) ),
			self::cells( (array) $p['closes'], array( 'room', 'nights', 'first' ) )
		);
		$stopped = (array) ( $p['stopped_rates'] ?? array() );
		if ( array() !== $stopped ) {
			self::table(
				/* translators: %d: number of prices */
				sprintf( __( 'HBook package or conditional prices that stop on matched rooms, because HostelMate sets the price of every night (%d)', 'hostelmate-hbook' ), count( $stopped ) ),
				array( __( 'Room', 'hostelmate-hbook' ), __( 'Price for', 'hostelmate-hbook' ), __( 'Amount', 'hostelmate-hbook' ) ),
				self::cells( $stopped, array( 'room', 'kind', 'amount' ) )
			);
		}
		self::table(
			/* translators: %d: number of nights */
			sprintf( __( 'Mismatches: HBook holds something HostelMate doesn\'t know (%d room-nights)', 'hostelmate-hbook' ), (int) $p['mismatch_total'] ),
			array( __( 'Night', 'hostelmate-hbook' ), __( 'HostelMate room', 'hostelmate-hbook' ), __( 'Missing', 'hostelmate-hbook' ), __( 'In HBook', 'hostelmate-hbook' ) ),
			self::cells( (array) $p['mismatches'], array( 'night', 'room', 'missing', 'candidates' ) )
		);
		// Before the first live run the brake doesn't apply (SyncRunner::apply): the preview is the owner's check.
		// Once live, a held change has its own section with Apply and Don't apply (live_step()).
		$brake = (array) $p['brake'];
		if ( 'ok' !== ( $brake['verdict'] ?? 'ok' ) && ! State::get( 'first_live_done', false ) ) {
			View::notice( 'info', Messages::text( Messages::PREVIEW_LARGE ) );
		}
	}

	/**
	 * A small table, or one line when empty.
	 *
	 * @param string             $title   Heading.
	 * @param list<string>       $heads   Column headings.
	 * @param list<list<string>> $rows    Cells (plain text).
	 */
	private static function table( string $title, array $heads, array $rows ): void {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'None.', 'hostelmate-hbook' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( $heads as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $cells ) {
			echo '<tr>';
			foreach ( $cells as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Stored summary lines → plain text cells (lists are joined), whatever was stored.
	 *
	 * @param array<mixed> $lines  Lines from the stored summary.
	 * @param list<string> $fields Which fields, in column order.
	 * @return list<list<string>>
	 */
	private static function cells( array $lines, array $fields ): array {
		$out = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$row = array();
			foreach ( $fields as $field ) {
				$value = $line[ $field ] ?? '';
				$row[] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : ( is_scalar( $value ) ? (string) $value : '' );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * One label/value row.
	 *
	 * @param string $label Label (translated).
	 * @param string $value Value (data).
	 */
	private static function row( string $label, string $value ): void {
		echo '<tr><th scope="row" style="width:180px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}

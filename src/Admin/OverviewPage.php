<?php
/**
 * Overview: the guided setup (Setup) until the website is live, then the status page.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\HBook\Tools;
use HostelMate\HBook\Sync\BookingRunner;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class OverviewPage {

	/** Prints the page. */
	public static function render(): void {
		View::guard_page();
		echo '<div class="wrap">';
		View::heading( __( 'HB-HostelMate', 'hostelmate-hbook' ) );
		View::action_notices();
		if ( ! Setup::done() ) {
			Setup::render();
			echo '</div>';
			return;
		}
		self::status();
		if ( ! \HostelMate\HBook\Core\Secret::has() ) {
			echo '</div>';
			return;
		}
		self::held_change();
		self::attention();
		self::last_check();
		self::copies_tool();
		echo '</div>';
	}

	/** Live or paused, the property, the last good check, and Pause / Resume. */
	private static function status(): void {
		$mode     = Settings::mode();
		$property = State::get( 'property' );
		echo '<div>';
		if ( ! \HostelMate\HBook\Core\Secret::has() ) {
			// Live once, but the key was removed: nothing is kept in step, say so first.
			echo '<h2>' . esc_html__( 'Not connected', 'hostelmate-hbook' ) . '</h2>';
			echo '<p>' . esc_html__( 'There is no HostelMate API key, so your website doesn\'t take prices or free beds from HostelMate and doesn\'t send it bookings. Paste a key to connect again.', 'hostelmate-hbook' ) . '</p>';
			Setup::key_form();
			echo '</div>';
			return;
		}
		if ( Config::MODE_PAUSED === $mode ) {
			echo '<h2>' . esc_html__( 'Paused', 'hostelmate-hbook' ) . '</h2>';
			echo '<p>' . esc_html( Messages::text( Messages::PAUSED ) ) . '</p>';
		} else {
			echo '<h2>' . esc_html__( 'Live', 'hostelmate-hbook' ) . '</h2>';
			echo '<p>' . esc_html__( 'Your website takes its prices and free beds from HostelMate, and sends its bookings to HostelMate.', 'hostelmate-hbook' ) . '</p>';
		}
		if ( is_array( $property ) ) {
			/* translators: %s: property name */
			echo '<p>' . esc_html( sprintf( __( 'HostelMate property: %s', 'hostelmate-hbook' ), (string) ( $property['name'] ?? '' ) ) ) . '</p>';
		}
		self::matched_line( is_array( $property ) ? $property : null );
		$last = State::get( 'last_ok' );
		if ( is_int( $last ) ) {
			/* translators: %s: date and time */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last good check with HostelMate: %s', 'hostelmate-hbook' ), (string) wp_date( 'Y-m-d H:i', $last ) ) ) . '</p>';
		}
		if ( Config::MODE_PAUSED === $mode ) {
			View::form_open( Config::ACTION_RESUME );
			View::form_close( __( 'Resume', 'hostelmate-hbook' ) );
		} else {
			View::form_open( Config::ACTION_PAUSE );
			View::form_close( __( 'Pause', 'hostelmate-hbook' ), 'button-secondary' );
		}
		echo '</div>';
	}

	/**
	 * How many rooms are matched, and what that means for the others. A choice, not an alert.
	 *
	 * @param array<string, mixed>|null $property The property read from HostelMate.
	 */
	public static function matched_line( ?array $property ): void {
		if ( null === $property ) {
			return;
		}
		$units   = \HostelMate\HBook\HBook\Units::all();
		$mapping = \HostelMate\HBook\Core\Mapping::get();
		$total   = 0;
		foreach ( $units as $accom ) {
			$total += count( $accom['units'] );
		}
		$left    = \HostelMate\HBook\Core\Mapping::unmatched( $mapping, $property, $units );
		$matched = $total - $left['hbook'];
		/* translators: 1: matched, 2: all rooms and beds on the website */
		$text = sprintf( __( 'Matched: %1$d of %2$d rooms and beds on your website.', 'hostelmate-hbook' ), $matched, $total );
		if ( $left['hbook'] > 0 ) {
			/* translators: %d: rooms and beds not matched */
			$text .= ' ' . sprintf( _n( 'The other %d keeps HBook\'s own prices and free beds, and its bookings don\'t go to HostelMate.', 'The other %d keep HBook\'s own prices and free beds, and their bookings don\'t go to HostelMate.', $left['hbook'], 'hostelmate-hbook' ), $left['hbook'] );
		}
		if ( $left['hostelmate'] > 0 ) {
			/* translators: %d: HostelMate rooms or beds */
			$text .= ' ' . sprintf( _n( '%d room or bed in HostelMate isn\'t matched to your website.', '%d rooms or beds in HostelMate aren\'t matched to your website.', $left['hostelmate'], 'hostelmate-hbook' ), $left['hostelmate'] );
		}
		$url = Setup::done() ? View::page_url( Config::PAGE_ROOMS ) : View::page_url( Config::PAGE_OVERVIEW, array( 'step' => Setup::ROOMS ) );
		echo '<p>' . esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Change the matching', 'hostelmate-hbook' ) . '</a></p>';
	}

	/** Open problems, one line per kind. Three are said in the page's own words instead (status and last check). */
	private static function attention(): void {
		$skip   = array( Messages::MAP_HBOOK_UNMATCHED, Messages::MAP_HM_UNMATCHED, Messages::SYNC_MISMATCH );
		$groups = array_diff_key( Notices::groups(), array_flip( $skip ) );
		if ( array() === $groups ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Needs your attention', 'hostelmate-hbook' ) . '</h2>';
		Notices::render_list( $skip );
	}

	/** A large change the brake held: Apply or Don't apply. */
	private static function held_change(): void {
		if ( ! is_array( State::get( 'held' ) ) ) {
			return;
		}
		echo '<div class="notice notice-warning inline">';
		echo '<h2>' . esc_html__( 'A large change is waiting for you', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html( Messages::text( Messages::SYNC_HELD ) ) . '</p>';
		echo '<div>';
		View::form_open( Config::ACTION_APPLY_HELD );
		View::form_close( __( 'Apply it', 'hostelmate-hbook' ) );
		View::form_open( Config::ACTION_DISCARD_HELD );
		View::form_close( __( 'Don\'t apply', 'hostelmate-hbook' ), 'button-secondary' );
		echo '</div></div>';
	}

	/** Check now, and what the last check found. */
	private static function last_check(): void {
		echo '<div>';
		echo '<h2>' . esc_html__( 'Last check', 'hostelmate-hbook' ) . '</h2>';
		$p = State::get( 'preview' );
		if ( is_array( $p ) ) {
			self::preview_summary( $p );
		} else {
			echo '<p class="description">' . esc_html__( 'No check has run yet.', 'hostelmate-hbook' ) . '</p>';
		}
		View::form_open( Config::ACTION_CHECK_NOW );
		View::form_close( __( 'Check now', 'hostelmate-hbook' ), 'button-secondary' );
		echo '</div>';
	}

	/**
	 * What a check found: a short list first, the details folded away.
	 *
	 * @param array<string, mixed> $p The stored summary (State 'preview').
	 */
	public static function preview_summary( array $p ): void {
		$prices     = (int) $p['prices_total'];
		$closes     = count( (array) $p['closes'] );
		$mismatches = (int) $p['mismatch_total'];
		$stopped    = (array) ( $p['stopped_rates'] ?? array() );
		$foreign    = (array) $p['foreign_high'];

		/* translators: 1: date and time, 2: number of nights */
		echo '<p class="description">' . esc_html( sprintf( __( 'Checked %1$s, for the next %2$d nights.', 'hostelmate-hbook' ), (string) wp_date( 'Y-m-d H:i', (int) $p['at'] ), (int) $p['nights'] ) ) . '</p>';
		echo '<ul class="ul-disc">';
		/* translators: %d: number of price lines */
		echo '<li>' . esc_html( 0 === $prices ? __( 'Prices: nothing changes.', 'hostelmate-hbook' ) : sprintf( _n( 'Prices: %d change, to match HostelMate.', 'Prices: %d changes, to match HostelMate.', $prices, 'hostelmate-hbook' ), $prices ) ) . '</li>';
		/* translators: %d: number of rooms or beds */
		echo '<li>' . esc_html( 0 === $closes ? __( 'Free beds: nothing closes.', 'hostelmate-hbook' ) : sprintf( _n( 'Free beds: %d room or bed closes some nights, because HostelMate has them booked.', 'Free beds: %d rooms or beds close some nights, because HostelMate has them booked.', $closes, 'hostelmate-hbook' ), $closes ) ) . '</li>';
		if ( $mismatches > 0 ) {
			/* translators: %d: number of nights */
			echo '<li>' . esc_html( sprintf( _n( 'To fix: %d night is booked on your website (HBook) but HostelMate doesn\'t know it.', 'To fix: %d nights are booked on your website (HBook) but HostelMate doesn\'t know them.', $mismatches, 'hostelmate-hbook' ), $mismatches ) . ' ' . __( 'Those beds stay closed on your website, but HostelMate could sell them again on other channels. Add those bookings in HostelMate, or cancel them in HBook if they are old copies (for example from iCal). The details list each night.', 'hostelmate-hbook' ) ) . '</li>';
		}
		if ( array() !== $foreign ) {
			echo '<li>' . esc_html( Messages::text( Messages::SYNC_FOREIGN_HIGH ) ) . '</li>';
		}
		if ( array() !== $stopped ) {
			/* translators: %d: number of prices */
			echo '<li>' . esc_html( sprintf( _n( '%d HBook package or conditional price stops on matched rooms: HostelMate sets the price of every night.', '%d HBook package or conditional prices stop on matched rooms: HostelMate sets the price of every night.', count( $stopped ), 'hostelmate-hbook' ), count( $stopped ) ) ) . '</li>';
		}
		echo '</ul>';

		$brake = (array) $p['brake'];
		if ( 'ok' !== ( $brake['verdict'] ?? 'ok' ) && ! State::get( 'first_live_done', false ) ) {
			View::notice( 'info', Messages::text( Messages::PREVIEW_LARGE ) );
		}

		echo '<details><summary>' . esc_html__( 'Show the details', 'hostelmate-hbook' ) . '</summary>';
		View::table(
			__( 'Price changes', 'hostelmate-hbook' ),
			array( __( 'Room', 'hostelmate-hbook' ), __( 'Now', 'hostelmate-hbook' ), __( 'From HostelMate', 'hostelmate-hbook' ), __( 'Nights', 'hostelmate-hbook' ), __( 'From', 'hostelmate-hbook' ) ),
			View::cells( (array) $p['prices'], array( 'room', 'old', 'new', 'nights', 'first' ) )
		);
		View::table(
			__( 'Nights that close', 'hostelmate-hbook' ),
			array( __( 'Room', 'hostelmate-hbook' ), __( 'Nights', 'hostelmate-hbook' ), __( 'From', 'hostelmate-hbook' ) ),
			View::cells( (array) $p['closes'], array( 'room', 'nights', 'first' ) )
		);
		if ( array() !== $stopped ) {
			View::table(
				__( 'HBook prices that stop', 'hostelmate-hbook' ),
				array( __( 'Room', 'hostelmate-hbook' ), __( 'Price for', 'hostelmate-hbook' ), __( 'Amount', 'hostelmate-hbook' ) ),
				View::cells( $stopped, array( 'room', 'kind', 'amount' ) )
			);
		}
		if ( $mismatches > 0 ) {
			View::table(
				__( 'Booked on your website, not in HostelMate', 'hostelmate-hbook' ),
				array( __( 'Night', 'hostelmate-hbook' ), __( 'HostelMate room', 'hostelmate-hbook' ), __( 'Missing', 'hostelmate-hbook' ), __( 'In HBook', 'hostelmate-hbook' ) ),
				View::cells( (array) $p['mismatches'], array( 'night', 'room', 'missing', 'candidates' ) )
			);
		}
		if ( array() !== $foreign ) {
			echo '<p>' . esc_html( implode( ', ', array_slice( array_map( 'strval', $foreign ), 0, 10 ) ) ) . '</p>';
		}
		echo '</details>';
	}

	/** The website bookings HostelMate gets at go-live (numbers and dates only, never guest names). */
	public static function bookings_to_send(): void {
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
		/* translators: %d: number of bookings */
		echo '<p>' . esc_html( sprintf( _n( 'At go-live, %d upcoming website booking is sent to HostelMate.', 'At go-live, %d upcoming website bookings are sent to HostelMate.', count( $rows ), 'hostelmate-hbook' ), count( $rows ) ) ) . '</p>';
		if ( array() !== $rows ) {
			echo '<details><summary>' . esc_html__( 'Show them', 'hostelmate-hbook' ) . '</summary>';
			View::table( '', array( __( 'HBook booking', 'hostelmate-hbook' ), __( 'Check-in', 'hostelmate-hbook' ), __( 'Check-out', 'hostelmate-hbook' ), __( 'Status in HostelMate', 'hostelmate-hbook' ) ), $rows );
			echo '</details>';
		}
	}

	/** D8: cancel the iCal copies (with undo), once HostelMate provably has them. */
	public static function copies_tool(): void {
		$copies = Tools::ical_copies();
		$undo   = Tools::undo_count();
		if ( array() === $copies && 0 === $undo ) {
			return;
		}
		echo '<details><summary>' . esc_html__( 'iCal copies in HBook', 'hostelmate-hbook' ) . '</summary>';
		if ( array() !== $copies ) {
			$parts = array();
			foreach ( $copies as $origin => $n ) {
				$parts[] = $origin . ': ' . $n;
			}
			/* translators: %s: counts per channel, e.g. "airbnb: 484" */
			echo '<p>' . esc_html( sprintf( __( 'Future bookings copied in by iCal from channels: %s. Once those channels are on HostelMate, HBook no longer hears about their changes, so a cancelled copy would block a bed for nothing. When the check shows nothing to fix, HostelMate has them all and the copies can go.', 'hostelmate-hbook' ), implode( ', ', $parts ) ) ) . '</p>';
			View::form_open( Config::ACTION_CANCEL_COPIES );
			echo '<p><label><input name="confirm" type="checkbox" value="1"> ' . esc_html__( 'Cancel these copies in HBook (an undo list is kept).', 'hostelmate-hbook' ) . '</label></p>';
			View::form_close( __( 'Cancel iCal copies', 'hostelmate-hbook' ), 'button-secondary' );
		}
		if ( $undo > 0 ) {
			View::form_open( Config::ACTION_UNDO_COPIES );
			/* translators: %d: number of copies */
			View::form_close( sprintf( __( 'Undo: bring back %d copies', 'hostelmate-hbook' ), $undo ), 'button-secondary' );
		}
		echo '</details>';
	}
}

<?php
/**
 * Everything the screens say about currency conversion, declared once. Each part prints nothing
 * while HostelMate and the website use the same currency (Fx::needed), so those screens look exactly
 * as they did before conversion existed.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\HBook\Options;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\Fx;
use HostelMate\HBook\Sync\SyncRunner;

defined( 'ABSPATH' ) || exit;

final class CurrencyView {

	/** The two currencies differ (and both are known). */
	public static function needed(): bool {
		return Fx::needed( SyncRunner::hm_currency(), Options::currency() );
	}

	/** Conversion is agreed for the two currencies in use now. */
	public static function agreed(): bool {
		$stored = self::needed() ? Fx::stored() : null;
		return null !== $stored && SyncRunner::hm_currency() === $stored['hm'] && Options::currency() === $stored['web'];
	}

	/**
	 * The offer to convert, with the Convert currencies button (setup step 3, and Settings). Nothing when the
	 * currencies match or conversion is already agreed for them.
	 */
	public static function offer(): void {
		if ( ! self::needed() || self::agreed() ) {
			return;
		}
		$hm  = SyncRunner::hm_currency();
		$web = Options::currency();
		echo '<div class="notice notice-info inline">';
		/* translators: 1: HostelMate currency, 2: website currency */
		echo '<p>' . esc_html( sprintf( __( 'HostelMate uses %1$s and your website uses %2$s. The plugin can convert between them at HostelMate\'s daily exchange rate:', 'hostelmate-hbook' ), $hm, $web ) ) . '</p>';
		echo '<ul class="ul-disc">';
		/* translators: 1: HostelMate currency, 2: website currency */
		echo '<li>' . esc_html( sprintf( __( 'HostelMate\'s prices in %1$s are shown on your website in %2$s.', 'hostelmate-hbook' ), $hm, $web ) ) . '</li>';
		/* translators: 1: HostelMate currency, 2: website currency */
		echo '<li>' . esc_html( sprintf( __( 'Website bookings and payments are sent to HostelMate in %1$s. The booking\'s note keeps the amount in %2$s and the rate used.', 'hostelmate-hbook' ), $hm, $web ) ) . '</li>';
		echo '<li>' . esc_html__( 'The rate is checked once a day. Small moves (under 1%) are ignored, so prices don\'t shift every day; a move over 10% waits for your OK.', 'hostelmate-hbook' ) . '</li>';
		echo '</ul>';
		echo '<p>' . esc_html__( 'You see today\'s rate and the converted prices in the check before anything is changed. You can stop converting at any time in Settings.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_FX_AGREE );
		View::form_close( __( 'Convert currencies', 'hostelmate-hbook' ) );
		echo '</div>';
	}

	/** One line: the rate in use, its date, and since when (nothing when not converting). */
	public static function rate_line(): void {
		if ( ! self::agreed() ) {
			return;
		}
		$stored = Fx::stored();
		if ( null === $stored ) {
			return;
		}
		$pair = $stored['in_use'];
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: HostelMate currency, 2: website currency, 3: rate, 4: date of HostelMate's rate */
				__( 'Prices and bookings are converted: 1 %1$s = %3$s %2$s (HostelMate\'s rate of %4$s).', 'hostelmate-hbook' ),
				$pair['hm'],
				$pair['web'],
				Fx::shown_rate( $pair ),
				(string) wp_date( 'Y-m-d', $pair['refreshed'] )
			)
		) . '</p>';
	}

	/** A rate that moved more than Config::FX_HOLD_PCT: Use the new rate, or Keep the old one. */
	public static function held(): void {
		if ( ! self::agreed() ) {
			return;
		}
		$stored = Fx::stored();
		$held   = Fx::held();
		if ( null === $stored || null === $held ) {
			return;
		}
		echo '<div class="notice notice-warning inline">';
		echo '<h2>' . esc_html__( 'A new exchange rate is waiting for you', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: HostelMate currency, 2: rate in use, 3: website currency, 4: new rate, 5: change in percent */
				__( 'HostelMate\'s rate moved from 1 %1$s = %2$s %3$s to %4$s %3$s (%5$s). Your website keeps the old rate until you choose. Using the new one changes every converted price at once.', 'hostelmate-hbook' ),
				$stored['hm'],
				Fx::shown_rate( $stored['in_use'] ),
				$stored['web'],
				Fx::shown_rate( $held ),
				Fx::change_pct( $stored['in_use'], $held )
			)
		) . '</p>';
		echo '<div>';
		View::form_open( Config::ACTION_FX_USE_HELD );
		View::form_close( __( 'Use the new rate', 'hostelmate-hbook' ) );
		View::form_open( Config::ACTION_FX_KEEP_HELD );
		View::form_close( __( 'Keep the old one', 'hostelmate-hbook' ), 'button-secondary' );
		echo '</div></div>';
	}

	/** Settings: the two currencies, the rate in use, and Stop converting (or the offer). */
	public static function settings_section(): void {
		if ( ! self::needed() ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Currency', 'hostelmate-hbook' ) . '</h2>';
		if ( ! self::agreed() ) {
			echo '<p>' . esc_html( Messages::text( Messages::SYNC_CURRENCY ) ) . '</p>';
			self::offer();
			return;
		}
		self::rate_line();
		$stored = Fx::stored();
		if ( null !== $stored && $stored['last_try'] > 0 ) {
			/* translators: %s: date and time */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last asked HostelMate for the rate: %s', 'hostelmate-hbook' ), (string) wp_date( 'Y-m-d H:i', $stored['last_try'] ) ) ) . '</p>';
		}
		echo '<details><summary>' . esc_html__( 'Stop converting', 'hostelmate-hbook' ) . '</summary>';
		echo '<p>' . esc_html( Messages::text( Messages::FX_STOPPED ) ) . '</p>';
		View::form_open( Config::ACTION_FX_STOP );
		echo '<p><label><input name="confirm" type="checkbox" value="1" required> ' . esc_html__( 'Yes, stop converting currencies.', 'hostelmate-hbook' ) . '</label></p>';
		View::form_close( __( 'Stop converting', 'hostelmate-hbook' ), 'button-link-delete' );
		echo '</details>';
	}

	/**
	 * The go-live checklist line (only while the currencies differ): a warning, like every item but the room match.
	 *
	 * @return list<array{ok: bool, required: bool, text: string}>
	 */
	public static function checklist_items(): array {
		if ( ! self::needed() ) {
			return array();
		}
		$hm  = SyncRunner::hm_currency();
		$web = Options::currency();
		$ok  = null !== SyncRunner::booking_fx();
		return array(
			array(
				'ok'       => $ok,
				'required' => false,
				'text'     => $ok
					/* translators: 1: HostelMate currency, 2: website currency */
					? sprintf( __( 'HostelMate\'s %1$s and your website\'s %2$s are converted at HostelMate\'s daily rate.', 'hostelmate-hbook' ), $hm, $web )
					/* translators: 1: HostelMate currency, 2: website currency */
					: sprintf( __( 'HostelMate uses %1$s and your website %2$s, and they aren\'t converted (step 3). Until they are, nothing is kept in step and website bookings wait.', 'hostelmate-hbook' ), $hm, $web ),
			),
		);
	}

	/** Support info: one line, no key and no guest data ('' when the currencies match). */
	public static function diagnostics_line(): string {
		if ( ! self::needed() ) {
			return '';
		}
		$stored = Fx::stored();
		if ( null === $stored ) {
			return 'fx ' . SyncRunner::hm_currency() . '>' . Options::currency() . ' not agreed';
		}
		return 'fx ' . $stored['hm'] . '>' . $stored['web'] . ( self::agreed() ? '' : ' (other pair now)' )
			. ' rate ' . Fx::shown_rate( $stored['in_use'] ) . ' of ' . gmdate( 'Y-m-d H:i', $stored['in_use']['refreshed'] ) . 'Z'
			. ' · newest ' . Fx::shown_rate( $stored['newest'] ) . ' of ' . gmdate( 'Y-m-d H:i', $stored['newest']['refreshed'] ) . 'Z'
			. ' · last try ' . ( $stored['last_try'] > 0 ? gmdate( 'Y-m-d H:i', $stored['last_try'] ) . 'Z' : 'never' );
	}
}

<?php
/**
 * The guided setup, shown on the plugin's only page until the website goes live:
 * 1 Connect, 2 Match rooms, 3 Check, 4 Go live. One step on screen at a time,
 * with a step bar; finished steps can be opened again from the bar.
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
use HostelMate\HBook\HBook\Units;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\GoLive;

defined( 'ABSPATH' ) || exit;

final class Setup {

	public const CONNECT = 'connect';
	public const ROOMS   = 'rooms';
	public const CHECK   = 'check';
	public const LIVE    = 'live';
	public const STEPS   = array( self::CONNECT, self::ROOMS, self::CHECK, self::LIVE );

	/** Setup is over once the website has gone live (also while paused). */
	public static function done(): bool {
		return Config::MODE_SETUP !== Settings::mode();
	}

	/**
	 * Which steps are finished, from what is saved. Pure: the tests call it directly.
	 *
	 * @param bool     $connected      A key is saved and the property was read.
	 * @param bool     $rooms_ok       At least one room is matched and nothing in the matching is an error.
	 * @param int|null $rooms_saved_at When the matching was last saved.
	 * @param int|null $checked_at     When the last check (preview) ran.
	 * @return array<string, bool>
	 */
	public static function finished( bool $connected, bool $rooms_ok, ?int $rooms_saved_at, ?int $checked_at ): array {
		$rooms = $connected && $rooms_ok && null !== $rooms_saved_at;
		$check = $rooms && null !== $checked_at && $checked_at >= $rooms_saved_at && time() - $checked_at < Config::PREVIEW_FRESH_FOR;
		return array(
			self::CONNECT => $connected,
			self::ROOMS   => $rooms,
			self::CHECK   => $check,
			self::LIVE    => false,
		);
	}

	/**
	 * The first step not finished.
	 *
	 * @param array<string, bool> $finished finished().
	 */
	public static function current( array $finished ): string {
		foreach ( self::STEPS as $step ) {
			if ( ! $finished[ $step ] ) {
				return $step;
			}
		}
		return self::LIVE;
	}

	/**
	 * The step to show: the one asked for in the URL if it isn't past the current one.
	 *
	 * @param string $asked   From the URL ('' if none).
	 * @param string $current current().
	 */
	public static function shown( string $asked, string $current ): string {
		$i = array_search( $asked, self::STEPS, true );
		return false !== $i && $i <= array_search( $current, self::STEPS, true ) ? $asked : $current;
	}

	/** Prints the setup. */
	public static function render(): void {
		$property = State::get( 'property' );
		$mapping  = Mapping::get();
		$rooms_ok = is_array( $property ) && array() !== $mapping
			&& array() === array_filter( Mapping::problems( $mapping, $property, Units::all() ), static fn( array $p ): bool => Mapping::LEVEL_ERROR === $p['level'] );
		$preview  = State::get( 'preview' );
		$saved_at = State::get( 'rooms_saved_at' );
		$finished = self::finished(
			Secret::has() && is_array( $property ),
			$rooms_ok,
			is_int( $saved_at ) ? $saved_at : null,
			is_array( $preview ) ? (int) $preview['at'] : null
		);
		$current  = self::current( $finished );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which step to show, from a fixed list.
		$asked = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		$step  = self::shown( $asked, $current );

		self::step_bar( $finished, $step );
		match ( $step ) {
			self::CONNECT => self::connect( is_array( $property ) ? $property : null ),
			self::ROOMS   => self::rooms(),
			self::CHECK   => self::check( $finished[ self::CHECK ] ),
			default       => self::live(),
		};
	}

	/**
	 * 1 Connect — 2 Match rooms — 3 Check — 4 Go live.
	 *
	 * @param array<string, bool> $finished finished().
	 * @param string              $shown    The step on screen.
	 */
	private static function step_bar( array $finished, string $shown ): void {
		$labels  = array(
			self::CONNECT => __( 'Connect', 'hostelmate-hbook' ),
			self::ROOMS   => __( 'Match rooms', 'hostelmate-hbook' ),
			self::CHECK   => __( 'Check', 'hostelmate-hbook' ),
			self::LIVE    => __( 'Go live', 'hostelmate-hbook' ),
		);
		$current = self::current( $finished );
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Setup steps', 'hostelmate-hbook' ) . '">';
		foreach ( self::STEPS as $i => $step ) {
			$text = esc_html( ( $i + 1 ) . '. ' . $labels[ $step ] ) . ( $finished[ $step ] ? ' <span class="dashicons dashicons-yes" aria-label="' . esc_attr__( 'done', 'hostelmate-hbook' ) . '"></span>' : '' );
			if ( $step === $shown ) {
				echo '<span class="nav-tab nav-tab-active" aria-current="step">' . $text . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() and esc_attr__() above.
			} elseif ( array_search( $step, self::STEPS, true ) <= array_search( $current, self::STEPS, true ) ) {
				echo '<a class="nav-tab" href="' . esc_url( View::page_url( Config::PAGE_OVERVIEW, array( 'step' => $step ) ) ) . '">' . $text . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() and esc_attr__() above.
			} else {
				echo '<span class="nav-tab" aria-disabled="true">' . $text . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() above.
			}
		}
		echo '</nav>';
	}

	/**
	 * Step 1: the key. Once connected: what it is connected to.
	 *
	 * @param array<string, mixed>|null $property The property read with the key.
	 */
	private static function connect( ?array $property ): void {
		echo '<div>';
		if ( null === $property || ! Secret::has() ) {
			echo '<h2>' . esc_html__( 'Connect your website to HostelMate', 'hostelmate-hbook' ) . '</h2>';
			echo '<p>' . esc_html__( 'Paste your HostelMate API key below. In HostelMate, you find it under User Management → API Access.', 'hostelmate-hbook' ) . '</p>';
			self::key_form();
			echo '<p class="description">' . esc_html__( 'Nothing on your website changes until the last step. The key is checked with HostelMate, then saved encrypted.', 'hostelmate-hbook' ) . '</p>';
			echo '</div>';
			return;
		}
		echo '<h2>' . esc_html__( 'Connected', 'hostelmate-hbook' ) . '</h2>';
		$rooms = is_array( $property['rooms'] ?? null ) ? $property['rooms'] : array();
		/* translators: 1: property name, 2: number of rooms */
		echo '<p>' . esc_html( sprintf( __( 'This website is connected to %1$s in HostelMate (%2$d rooms).', 'hostelmate-hbook' ), (string) ( $property['name'] ?? '' ), count( $rooms ) ) ) . '</p>';
		echo '<div>';
		View::link_button( View::page_url( Config::PAGE_OVERVIEW, array( 'step' => self::ROOMS ) ), __( 'Next: match rooms', 'hostelmate-hbook' ) );
		echo '</div>';
		echo '<details><summary>' . esc_html__( 'Wrong property?', 'hostelmate-hbook' ) . '</summary>';
		echo '<p>' . esc_html__( 'Disconnect, then connect again with the key of the right property.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_REMOVE_KEY );
		echo '<input type="hidden" name="confirm" value="1">';
		View::form_close( __( 'Disconnect', 'hostelmate-hbook' ), 'button-secondary' );
		echo '</details></div>';
	}

	/** The API key box and Connect button (setup step 1, and the Overview if the key was removed after going live). */
	public static function key_form(): void {
		View::form_open( Config::ACTION_CONNECT );
		echo '<p><label for="hm-api-key"><strong>' . esc_html__( 'API key', 'hostelmate-hbook' ) . '</strong></label><br>';
		echo '<input id="hm-api-key" name="api_key" type="password" class="regular-text code" autocomplete="off" spellcheck="false" required></p>';
		View::form_close( __( 'Connect', 'hostelmate-hbook' ), 'button-hero' );
	}

	/** Step 2: the matching table. */
	private static function rooms(): void {
		echo '<div>';
		echo '<h2>' . esc_html__( 'Match your rooms', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'On the left are the rooms and beds of your website. For each one, pick the same room or bed in HostelMate. We filled in our best guess from the names, so mostly you only need to check them.', 'hostelmate-hbook' ) . '</p>';
		RoomsPage::matching( __( 'Save and continue', 'hostelmate-hbook' ) );
		echo '</div>';
	}

	/**
	 * Step 3: run the check, then a short summary of what would change.
	 *
	 * @param bool $fresh A check ran after the rooms were saved, in the last 30 minutes.
	 */
	private static function check( bool $fresh ): void {
		$p = State::get( 'preview' );
		echo '<div>';
		echo '<h2>' . esc_html__( 'Check what will change', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'The plugin compares your website with HostelMate and shows what going live would change. Nothing is changed now.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_CHECK_NOW );
		View::form_close( $fresh ? __( 'Run the check again', 'hostelmate-hbook' ) : __( 'Run the check', 'hostelmate-hbook' ), $fresh ? 'button-secondary' : 'button-hero' );
		// The last check failed: say why, here, even if an earlier result is still recent
		// (otherwise the owner would read the earlier result as this check's).
		$last   = State::get( 'last_run' );
		$saved  = State::get( 'rooms_saved_at' );
		$since  = max( is_int( $saved ) ? $saved : 0, is_array( $p ) ? (int) $p['at'] : 0 );
		$failed = is_array( $last ) && is_string( $last['code'] ?? null ) && (int) ( $last['at'] ?? 0 ) >= $since
			&& ! in_array( $last['code'], array( Messages::PREVIEW_READY, Messages::SYNC_NO_CHANGE, Messages::SYNC_APPLIED ), true );
		if ( ! $fresh || ! is_array( $p ) || $failed ) {
			if ( $failed ) {
				echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'The check stopped:', 'hostelmate-hbook' ) . '</strong> ' . esc_html( Messages::text( $last['code'] ) ) . '</p>';
				$property = State::get( 'property' );
				$hm       = is_array( $property ) ? strtoupper( (string) ( $property['currency'] ?? '' ) ) : '';
				if ( Messages::SYNC_CURRENCY === $last['code'] && '' !== $hm && Options::currency() === $hm ) {
					/* translators: %s: currency code */
					echo '<p>' . esc_html( sprintf( __( 'Both use %s now. Run the check again.', 'hostelmate-hbook' ), $hm ) ) . '</p></div>';
				} elseif ( Messages::SYNC_CURRENCY === $last['code'] ) {
					/* translators: 1: HBook currency, 2: HostelMate currency */
					echo '<p>' . esc_html( sprintf( __( 'Your website\'s prices are in %1$s, and HostelMate\'s are in %2$s. Set the same currency in HostelMate\'s property settings (or in HBook), then run the check again.', 'hostelmate-hbook' ), '' !== Options::currency() ? Options::currency() : '?', is_array( $property ) ? strtoupper( (string) ( $property['currency'] ?? '?' ) ) : '?' ) ) . '</p></div>';
				} else {
					echo '<p>' . esc_html__( 'When that\'s sorted, run the check again.', 'hostelmate-hbook' ) . '</p></div>';
				}
			}
			if ( ! $fresh || ! is_array( $p ) ) {
				echo '</div>';
				return;
			}
			echo '<h3>' . esc_html__( 'The check before that found:', 'hostelmate-hbook' ) . '</h3>';
		}
		OverviewPage::preview_summary( $p );
		echo '<div>';
		View::link_button( View::page_url( Config::PAGE_OVERVIEW, array( 'step' => self::LIVE ) ), __( 'Continue', 'hostelmate-hbook' ) );
		echo '</div></div>';
	}

	/** Step 4: the checklist and the Go live button. */
	private static function live(): void {
		echo '<div>';
		echo '<h2>' . esc_html__( 'Go live', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'From now on your website takes its prices and free beds from HostelMate every 5 minutes, and sends its bookings to HostelMate. You can pause at any time.', 'hostelmate-hbook' ) . '</p>';
		$property = State::get( 'property' );
		OverviewPage::matched_line( is_array( $property ) ? $property : null );
		$items    = GoLive::checklist();
		$required = array_filter( $items, static fn( array $i ): bool => $i['required'] && ! $i['ok'] );
		$warnings = array_filter( $items, static fn( array $i ): bool => ! $i['required'] && ! $i['ok'] );
		echo '<ul>';
		foreach ( $items as $item ) {
			$prefix = $item['ok'] ? '' : ( $item['required'] ? __( 'Missing:', 'hostelmate-hbook' ) . ' ' : __( 'Not yet:', 'hostelmate-hbook' ) . ' ' );
			echo '<li>' . ( $item['ok'] ? '&#10003; ' : ( $item['required'] ? '&#10007; ' : '&#9888; ' ) ) . esc_html( $prefix . $item['text'] ) . '</li>';
		}
		echo '</ul>';
		OverviewPage::bookings_to_send();
		if ( array() !== $required ) {
			View::notice( 'error', __( 'Match at least one room in step 2 first. Without a matched room the plugin has nothing to keep in step.', 'hostelmate-hbook' ) );
			echo '</div>';
			return;
		}
		View::form_open( Config::ACTION_GO_LIVE );
		if ( array() !== $warnings ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some lines above have a warning. You can fix them first (step 3 lists the details), or go live anyway.', 'hostelmate-hbook' ) . '</p>';
			echo '<p><label><input name="accept_warnings" type="checkbox" value="1" required> ' . esc_html__( 'I\'ve read the warnings and want to go live anyway.', 'hostelmate-hbook' ) . '</label></p></div>';
		}
		View::form_close( __( 'Go live', 'hostelmate-hbook' ), 'button-hero' );
		echo '</div>';
	}
}

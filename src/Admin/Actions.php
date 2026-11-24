<?php
/**
 * What each admin action does. Called only by ActionRouter (after POST, nonce and
 * capability checks). Each returns the Messages codes to show afterwards.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\HBook\Units;
use HostelMate\HBook\HostelMate\Client;
use HostelMate\HBook\HostelMate\Result;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Actions {

	/**
	 * Saves the key only if HostelMate accepts it, and keeps the property's rooms.
	 *
	 * @param array<string, mixed> $input api_key.
	 * @return list<string>
	 */
	public static function connect( array $input ): array {
		if ( Secret::from_constant() ) {
			return array( Messages::KEY_IN_CONFIG );
		}
		$key = is_string( $input['api_key'] ?? null ) ? trim( $input['api_key'] ) : '';
		if ( ! Secret::salts_strong() ) {
			return array( Messages::KEY_WEAK_SALTS );
		}
		$previous = get_option( Config::OPT_KEY, null );
		if ( ! Secret::store( $key ) ) {
			return array( Messages::CONNECT_BAD_KEY );
		}
		unset( $key );
		$result = self::read_property();
		if ( Messages::CONNECTED !== $result ) {
			// Never keep a key HostelMate didn't accept, and never lose the one that worked.
			if ( is_string( $previous ) ) {
				update_option( Config::OPT_KEY, $previous, false );
			} else {
				Secret::clear();
			}
		}
		return array( $result );
	}

	/**
	 * Reads the property's rooms and beds again.
	 *
	 * @param array<string, mixed> $input Unused.
	 * @return list<string>
	 */
	public static function refresh_rooms( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every action takes the same argument.
		$result = self::read_property();
		return array( Messages::CONNECTED === $result ? Messages::ROOMS_REFRESHED : $result );
	}

	/**
	 * Forgets the stored key (needs the confirm box).
	 *
	 * @param array<string, mixed> $input confirm.
	 * @return list<string>
	 */
	public static function remove_key( array $input ): array {
		if ( Secret::from_constant() ) {
			return array( Messages::KEY_IN_CONFIG );
		}
		if ( '1' !== ( $input['confirm'] ?? null ) ) {
			return array( Messages::CONFIRM_MISSING );
		}
		Secret::clear();
		State::set( 'property', null );
		Log::write( Config::LOG_LEVEL_INFO, Messages::KEY_REMOVED );
		return array( Messages::KEY_REMOVED );
	}

	/**
	 * Saves the Settings form.
	 *
	 * @param array<string, mixed> $input alert_email, payment_methods, precheck.
	 * @return list<string>
	 */
	public static function save_settings( array $input ): array {
		$errors = Settings::update( $input );
		return array() === $errors ? array( Messages::SETTINGS_SAVED ) : $errors;
	}

	/**
	 * Saves the room matching when it has no errors; the problems go to the next page view.
	 *
	 * @param array<string, mixed> $input mapping.
	 * @return list<string>
	 */
	public static function save_mapping( array $input ): array {
		$property = State::get( 'property' );
		if ( ! is_array( $property ) ) {
			return array( Messages::CONNECT_FIRST );
		}
		$issues = Mapping::save( Mapping::from_form( $input['mapping'] ?? null ), $property, Units::all() );
		set_transient( Config::TRANSIENT_ISSUES . get_current_user_id(), $issues, 120 );
		$errors = array_filter( $issues, static fn( array $i ): bool => Mapping::LEVEL_ERROR === $i['level'] );
		if ( array() === $errors ) {
			Log::write( Config::LOG_LEVEL_INFO, Messages::MAPPING_SAVED );
			return array( Messages::MAPPING_SAVED );
		}
		return array( Messages::MAPPING_NOT_SAVED );
	}

	/**
	 * Runs one sync now (a preview until the site is live).
	 *
	 * @param array<string, mixed> $input Unused.
	 * @return list<string>
	 */
	public static function check_now( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every action takes the same argument.
		$code = \HostelMate\HBook\Sync\SyncRunner::run( true );
		\HostelMate\HBook\Sync\BookingRunner::run();
		return array( $code );
	}

	/**
	 * Go live: only when the whole checklist passes. The first live run follows at once.
	 *
	 * @param array<string, mixed> $input ical_ok.
	 * @return list<string>
	 */
	public static function go_live( array $input ): array {
		if ( ! \HostelMate\HBook\Sync\GoLive::ready( '1' === ( $input['ical_ok'] ?? null ) ) ) {
			return array( Messages::GO_LIVE_BLOCKED );
		}
		Settings::set_mode( Config::MODE_LIVE );
		State::set( 'first_live_done', false );
		Log::write( Config::LOG_LEVEL_INFO, Messages::GO_LIVE_DONE );
		\HostelMate\HBook\Sync\Scheduler::ensure();
		return array( Messages::GO_LIVE_DONE, \HostelMate\HBook\Sync\SyncRunner::run( false ) );
	}

	/**
	 * Pause: nothing is written or sent; everything already written stays.
	 *
	 * @param array<string, mixed> $input Unused.
	 * @return list<string>
	 */
	public static function pause( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every action takes the same argument.
		Settings::set_mode( Config::MODE_PAUSED );
		\HostelMate\HBook\Sync\Scheduler::ensure();
		Log::write( Config::LOG_LEVEL_WARN, Messages::PAUSED );
		return array( Messages::PAUSED );
	}

	/**
	 * Resume after a pause.
	 *
	 * @param array<string, mixed> $input Unused.
	 * @return list<string>
	 */
	public static function resume( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every action takes the same argument.
		if ( Config::MODE_PAUSED !== Settings::mode() ) {
			return array( Messages::NOT_PAUSED );
		}
		Settings::set_mode( Config::MODE_LIVE );
		\HostelMate\HBook\Sync\Scheduler::ensure();
		Log::write( Config::LOG_LEVEL_INFO, Messages::RESUMED );
		return array( Messages::RESUMED, \HostelMate\HBook\Sync\SyncRunner::run( false ) );
	}

	/**
	 * Apply a held change: the brake is skipped for the next 10 minutes, and a run starts now.
	 *
	 * @param array<string, mixed> $input Unused.
	 * @return list<string>
	 */
	public static function apply_held( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every action takes the same argument.
		if ( ! is_array( State::get( 'held' ) ) || Config::MODE_LIVE !== Settings::mode() ) {
			return array( Messages::NOTHING_HELD );
		}
		State::set( 'brake_override_until', time() + 600 );
		Log::write( Config::LOG_LEVEL_WARN, Messages::HELD_APPLIED );
		return array( Messages::HELD_APPLIED, \HostelMate\HBook\Sync\SyncRunner::run( false ) );
	}

	/**
	 * Discard a held change (it comes back if HostelMate still says the same next time).
	 *
	 * @param array<string, mixed> $input Unused.
	 * @return list<string>
	 */
	public static function discard_held( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every action takes the same argument.
		State::set( 'held', null );
		\HostelMate\HBook\Core\Alerts::resolve( Messages::SYNC_HELD );
		Log::write( Config::LOG_LEVEL_WARN, Messages::HELD_DISCARDED );
		return array( Messages::HELD_DISCARDED );
	}

	/**
	 * Removes every season, rate and block the plugin wrote (typed confirmation), back to setup mode.
	 *
	 * @param array<string, mixed> $input confirm (must be "REMOVE").
	 * @return list<string>
	 */
	public static function remove_data( array $input ): array {
		if ( 'REMOVE' !== ( is_string( $input['confirm'] ?? null ) ? trim( $input['confirm'] ) : '' ) ) {
			return array( Messages::REMOVE_CONFIRM );
		}
		\HostelMate\HBook\HBook\Writer::remove_everything();
		Settings::set_mode( Config::MODE_SETUP );
		\HostelMate\HBook\Sync\Scheduler::ensure();
		Log::write( Config::LOG_LEVEL_WARN, Messages::DATA_REMOVED );
		return array( Messages::DATA_REMOVED );
	}

	/**
	 * D8: cancel the iCal copies, only once a fresh check shows no mismatches (HostelMate has them all).
	 *
	 * @param array<string, mixed> $input confirm.
	 * @return list<string>
	 */
	public static function cancel_copies( array $input ): array {
		if ( '1' !== ( $input['confirm'] ?? null ) ) {
			return array( Messages::CONFIRM_MISSING );
		}
		$p = State::get( 'preview' );
		if ( ! is_array( $p ) || time() - (int) $p['at'] >= Config::PREVIEW_FRESH_FOR || 0 !== (int) $p['mismatch_total'] ) {
			return array( Messages::COPIES_NOT_PROVEN );
		}
		$n = \HostelMate\HBook\HBook\Tools::cancel_ical_copies();
		Log::write( Config::LOG_LEVEL_WARN, Messages::COPIES_CANCELLED, null, null, (string) $n );
		return array( Messages::COPIES_CANCELLED );
	}

	/**
	 * Undo of D8.
	 *
	 * @param array<string, mixed> $input Unused.
	 * @return list<string>
	 */
	public static function undo_copies( array $input ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- every action takes the same argument.
		$n = \HostelMate\HBook\HBook\Tools::undo_ical_cancel();
		Log::write( Config::LOG_LEVEL_WARN, Messages::COPIES_RESTORED, null, null, (string) $n );
		return array( Messages::COPIES_RESTORED );
	}

	/** GET /property → State 'property'. Returns a Messages code. */
	private static function read_property(): string {
		$result = Client::property();
		if ( $result->is_ok() ) {
			$old = State::get( 'property' );
			if ( is_array( $old ) && ( $old['id'] ?? '' ) !== ( $result->data['id'] ?? '' ) ) {
				// Another property: its rooms are different, so the matching and the preview start over.
				delete_option( Config::OPT_MAPPING );
				State::set( 'preview', null );
				Settings::set_mode( Config::MODE_SETUP );
				\HostelMate\HBook\Sync\Scheduler::ensure();
			}
			State::set( 'property', $result->data );
			State::set( 'property_read_at', time() );
			Log::write( Config::LOG_LEVEL_INFO, Messages::CONNECTED );
			return Messages::CONNECTED;
		}
		$code = match ( $result->kind ) {
			Result::UNAUTHORIZED => Messages::CONNECT_REFUSED,
			Result::FORBIDDEN    => Messages::CONNECT_PLAN,
			Result::INVALID      => Messages::CONNECT_BAD_ANSWER,
			default              => Messages::CONNECT_UNREACHABLE,
		};
		Log::write( Config::LOG_LEVEL_WARN, $code, null, null, $result->kind . ' ' . $result->status . ' ' . $result->code );
		return $code;
	}
}

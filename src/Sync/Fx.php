<?php
/**
 * Currency conversion, the only home for it. Nothing here runs while HostelMate and HBook use
 * the same currency: every caller asks needed() first and stops when it is false.
 *
 * The maths is HostelMate's own: converted = amount × (rate of the target ÷ rate of the source),
 * each rate per 1 USD with USD itself at 1 (backend api/booking_engine/pricing.py:66-84), rounded
 * half up to cents (api/booking_engine/views_public.py:68). It runs on whole numbers only, so no
 * float ever touches money, and it never overflows: big products are kept as digit strings.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\HostelMate\Client;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * A pair: the two currencies, their rates per 1 USD as HostelMate lists them ("19.17796700"; USD is "1"),
 * and when HostelMate last refreshed its list (Unix time).
 *
 * @phpstan-type FxPair array{hm: string, web: string, hm_rate: string, web_rate: string, refreshed: int}
 * @phpstan-type FxStored array{hm: string, web: string, agreed_at: int, agreed_by: int, in_use: FxPair, since: int, newest: FxPair, last_try: int, kept: int}
 */
final class Fx {

	// What to do with a newer rate (step()).
	public const IGNORE = 'ignore'; // Moved less than Config::FX_IGNORE_PCT: keep the rate in use.
	public const USE    = 'use';    // Moved up to Config::FX_HOLD_PCT: use it, and log it.
	public const HOLD   = 'hold';   // Moved more: wait for the owner.

	/** Alerts this class opens; each one is closed again by the state that ends it. */
	public const ALERTS = array( Messages::FX_TOO_OLD, Messages::FX_HELD, Messages::FX_PAIR_CHANGED, Messages::FX_TOO_BIG, Messages::SYNC_CURRENCY );

	/** Largest rate accepted: 9 whole digits (IRR is about 1,950,000 per USD). */
	private const RATE_PATTERN = '/^(\d{1,9})(?:\.(\d{1,8}))?\z/';

	/**
	 * True only when both currencies are known and differ. The one question every caller asks first.
	 *
	 * @param string $hm  HostelMate's currency.
	 * @param string $web HBook's currency.
	 */
	public static function needed( string $hm, string $web ): bool {
		$hm  = strtoupper( trim( $hm ) );
		$web = strtoupper( trim( $web ) );
		return '' !== $hm && '' !== $web && $hm !== $web;
	}

	/**
	 * A rate as HostelMate lists it → the rate × 10^8 as a whole number, or null when it isn't a usable rate
	 * (empty, zero, negative, more than 8 decimals, too large, not a number).
	 *
	 * @param mixed $rate Anything.
	 */
	public static function rate_e8( $rate ): ?int {
		if ( ! is_string( $rate ) || 1 !== preg_match( self::RATE_PATTERN, $rate, $m ) ) {
			return null;
		}
		$e8 = (int) $m[1] * 100000000 + (int) str_pad( $m[2] ?? '', 8, '0' );
		return $e8 > 0 ? $e8 : null;
	}

	/**
	 * An amount in minor units from one currency to another: amount × to ÷ from, half up (away from zero).
	 *
	 * @param int    $minor     Amount in minor units.
	 * @param string $from_rate The source currency's rate per USD.
	 * @param string $to_rate   The target currency's rate per USD.
	 * @throws \InvalidArgumentException A rate that isn't usable.
	 * @throws \OverflowException        A result too large for the plugin's numbers.
	 */
	public static function convert( int $minor, string $from_rate, string $to_rate ): int {
		$from = self::rate_e8( $from_rate );
		$to   = self::rate_e8( $to_rate );
		if ( null === $from || null === $to ) {
			throw new \InvalidArgumentException( 'Not a usable rate.' );
		}
		if ( PHP_INT_MIN === $minor ) {
			throw new \OverflowException( 'Amount too large.' );
		}
		$out = self::div_round( self::mul( (string) abs( $minor ), (string) $to ), $from );
		if ( strlen( $out ) > 18 ) {
			throw new \OverflowException( 'Converted amount too large.' );
		}
		return $minor < 0 ? - (int) $out : (int) $out;
	}

	/**
	 * HostelMate's amount in the website's currency (prices).
	 *
	 * @param int    $minor HostelMate's amount, minor units.
	 * @param FxPair $pair  The pair in use.
	 */
	public static function to_website( int $minor, array $pair ): int {
		return self::convert( $minor, $pair['hm_rate'], $pair['web_rate'] );
	}

	/**
	 * The website's amount in HostelMate's currency (bookings and payments), at the booking's own pair.
	 *
	 * @param int    $minor Website amount, minor units.
	 * @param FxPair $pair  The pair saved with the booking.
	 */
	public static function to_hostelmate( int $minor, array $pair ): int {
		return self::convert( $minor, $pair['web_rate'], $pair['hm_rate'] );
	}

	/**
	 * What to do with a newer rate for the same two currencies: compares 1 HostelMate unit in website money,
	 * then and now, exactly (both sides cross-multiplied).
	 *
	 * @param FxPair $in_use The pair in use.
	 * @param FxPair $newest The newest one stored.
	 */
	public static function step( array $in_use, array $newest ): string {
		// now ÷ then = (new_web × old_hm) ÷ (new_hm × old_web) = a ÷ b. "Moved less than p%": b×(100−p) < a×100 < b×(100+p).
		$a     = self::mul( self::mul( (string) self::e8( $newest['web_rate'] ), (string) self::e8( $in_use['hm_rate'] ) ), '100' );
		$b     = self::mul( (string) self::e8( $newest['hm_rate'] ), (string) self::e8( $in_use['web_rate'] ) );
		$lo    = static fn( int $pct ): string => self::mul( $b, (string) ( 100 - $pct ) );
		$hi    = static fn( int $pct ): string => self::mul( $b, (string) ( 100 + $pct ) );
		$under = static fn( int $pct ): bool => self::cmp( $a, $lo( $pct ) ) > 0 && self::cmp( $a, $hi( $pct ) ) < 0;
		if ( $under( Config::FX_IGNORE_PCT ) ) {
			return self::IGNORE;
		}
		// Exactly 10% is still used; only more than 10% waits.
		return self::cmp( $a, $lo( Config::FX_HOLD_PCT ) ) >= 0 && self::cmp( $a, $hi( Config::FX_HOLD_PCT ) ) <= 0 ? self::USE : self::HOLD;
	}

	/**
	 * How much 1 HostelMate unit moved in website money, in percent with one decimal (for words only, never money).
	 *
	 * @param FxPair $in_use The pair in use.
	 * @param FxPair $newest The newer one.
	 */
	public static function change_pct( array $in_use, array $newest ): string {
		$then = (float) self::shown_rate( $in_use, 8 );
		$now  = (float) self::shown_rate( $newest, 8 );
		$pct  = $then > 0 ? ( $now - $then ) / $then * 100 : 0.0;
		return ( $pct > 0 ? '+' : '' ) . number_format( $pct, 1, '.', '' ) . '%';
	}

	/**
	 * 1 HostelMate unit in website money, as text with $decimals decimals, half up ("19.177967").
	 *
	 * @param FxPair $pair     A pair.
	 * @param int    $decimals 0 to 8.
	 */
	public static function shown_rate( array $pair, int $decimals = 6 ): string {
		$decimals = max( 0, min( 8, $decimals ) );
		$scaled   = self::div_round( self::mul( (string) self::e8( $pair['web_rate'] ), '1' . str_repeat( '0', $decimals ) ), self::e8( $pair['hm_rate'] ) );
		if ( 0 === $decimals ) {
			return $scaled;
		}
		$scaled = str_pad( $scaled, $decimals + 1, '0', STR_PAD_LEFT );
		return substr( $scaled, 0, -$decimals ) . '.' . substr( $scaled, -$decimals );
	}

	/**
	 * HostelMate's list is too old to use (Config::FX_MAX_AGE). Unknown (0) counts as too old.
	 *
	 * @param int $refreshed HostelMate's last refresh.
	 * @param int $now       Now.
	 */
	public static function too_old( int $refreshed, int $now ): bool {
		return $refreshed <= 0 || $now - $refreshed > Config::FX_MAX_AGE;
	}

	/**
	 * Time to fetch the list again: HostelMate's copy is a day old and the last attempt an hour ago.
	 *
	 * @param int $refreshed HostelMate's last refresh in the stored list (0 = none stored).
	 * @param int $last_try  The plugin's last attempt (0 = never).
	 * @param int $now       Now.
	 */
	public static function refresh_due( int $refreshed, int $last_try, int $now ): bool {
		return $now - $refreshed > Config::FX_REFRESH_AFTER && $now - $last_try > Config::FX_RETRY_AFTER;
	}

	/**
	 * A rate known to be usable (checked when stored) as × 10^8.
	 *
	 * @param string $rate Rate.
	 * @throws \InvalidArgumentException A rate that isn't usable.
	 */
	private static function e8( string $rate ): int {
		$e8 = self::rate_e8( $rate );
		if ( null === $e8 ) {
			throw new \InvalidArgumentException( 'Not a usable rate.' );
		}
		return $e8;
	}

	/**
	 * $a × $b for non-negative whole numbers written in digits, any size.
	 *
	 * @param string $a Digits.
	 * @param string $b Digits.
	 */
	private static function mul( string $a, string $b ): string {
		$x   = array_reverse( str_split( $a ) );
		$y   = array_reverse( str_split( $b ) );
		$out = array_fill( 0, count( $x ) + count( $y ), 0 );
		foreach ( $x as $i => $dx ) {
			$carry = 0;
			foreach ( $y as $j => $dy ) {
				$sum            = $out[ $i + $j ] + (int) $dx * (int) $dy + $carry;
				$out[ $i + $j ] = $sum % 10;
				$carry          = intdiv( $sum, 10 );
			}
			$k = $i + count( $y );
			while ( $carry > 0 ) {
				$sum       = $out[ $k ] + $carry;
				$out[ $k ] = $sum % 10;
				$carry     = intdiv( $sum, 10 );
				++$k;
			}
		}
		$digits = ltrim( implode( '', array_reverse( $out ) ), '0' );
		return '' === $digits ? '0' : $digits;
	}

	/**
	 * $n ÷ $d rounded half up, for a non-negative whole number in digits and a positive divisor below 10^17.
	 *
	 * @param string $n Digits.
	 * @param int    $d Divisor.
	 */
	private static function div_round( string $n, int $d ): string {
		$quotient = '';
		$rest     = 0;
		foreach ( str_split( $n ) as $digit ) {
			$rest      = $rest * 10 + (int) $digit;
			$quotient .= (string) intdiv( $rest, $d );
			$rest      = $rest % $d;
		}
		$quotient = ltrim( $quotient, '0' );
		$quotient = '' === $quotient ? '0' : $quotient;
		return $rest * 2 >= $d ? self::add_one( $quotient ) : $quotient;
	}

	/**
	 * $n + 1 in digits.
	 *
	 * @param string $n Digits.
	 */
	private static function add_one( string $n ): string {
		$i = strlen( $n ) - 1;
		while ( $i >= 0 && '9' === $n[ $i ] ) {
			$n[ $i ] = '0';
			--$i;
		}
		return $i < 0 ? '1' . $n : substr_replace( $n, (string) ( (int) $n[ $i ] + 1 ), $i, 1 );
	}

	/**
	 * Compares two non-negative whole numbers in digits (no leading zeros): -1, 0 or 1.
	 *
	 * @param string $a Digits.
	 * @param string $b Digits.
	 */
	private static function cmp( string $a, string $b ): int {
		return strlen( $a ) === strlen( $b ) ? strcmp( $a, $b ) <=> 0 : strlen( $a ) <=> strlen( $b );
	}

	// ---- The stored rate (Config::OPT_FX). Only ever called once needed() said yes. ----

	/**
	 * The owner agrees to convert between these two currencies: fetches today's list, stores the pair, logs it.
	 * Returns a Messages code: FX_AGREED, or why it couldn't (nothing is stored then).
	 *
	 * @param string $hm   HostelMate's currency.
	 * @param string $web  HBook's currency.
	 * @param int    $user WordPress user id.
	 * @param int    $now  Now.
	 */
	public static function agree( string $hm, string $web, int $user, int $now ): string {
		if ( ! self::needed( $hm, $web ) ) {
			return Messages::FX_SAME; // Rule 1: same currency, nothing is stored.
		}
		$hm   = strtoupper( trim( $hm ) );
		$web  = strtoupper( trim( $web ) );
		$list = self::fetch( $now );
		if ( null === $list ) {
			return Messages::FX_UNREACHABLE;
		}
		$pair = self::pair_from( $list, $hm, $web );
		if ( null === $pair ) {
			return Messages::FX_NO_RATE;
		}
		if ( self::too_old( $pair['refreshed'], $now ) ) {
			return Messages::FX_TOO_OLD;
		}
		self::save(
			array(
				'hm'        => $hm,
				'web'       => $web,
				'agreed_at' => $now,
				'agreed_by' => $user,
				'in_use'    => $pair,
				'since'     => $now,
				'newest'    => $pair,
				'last_try'  => $now,
				'kept'      => 0,
			)
		);
		foreach ( self::ALERTS as $code ) {
			Alerts::resolve_code( $code );
		}
		// The sync's last known prices (kept in HostelMate's currency, for nights it gives no price) may be in another
		// currency if HostelMate's changed: start them afresh.
		\HostelMate\HBook\Core\State::set( 'last_price', array() );
		Log::write( Config::LOG_LEVEL_INFO, Messages::FX_AGREED, null, null, $hm . '>' . $web . ' ' . self::shown_rate( $pair ) );
		return Messages::FX_AGREED;
	}

	/** The owner stops converting: the stored pair is deleted, so syncs stop and bookings wait, as without conversion. */
	public static function stop(): void {
		delete_option( Config::OPT_FX );
		foreach ( self::ALERTS as $code ) {
			Alerts::resolve_code( $code );
		}
		Log::write( Config::LOG_LEVEL_WARN, Messages::FX_STOPPED );
	}

	/**
	 * What is stored, checked field by field (anything odd counts as nothing stored).
	 *
	 * @return FxStored|null
	 */
	public static function stored(): ?array {
		$v = get_option( Config::OPT_FX, null );
		if ( ! is_array( $v ) || ! is_string( $v['hm'] ?? null ) || ! is_string( $v['web'] ?? null ) || ! self::needed( $v['hm'], $v['web'] ) ) {
			return null;
		}
		$in_use = self::checked_pair( $v['in_use'] ?? null, $v['hm'], $v['web'] );
		$newest = self::checked_pair( $v['newest'] ?? null, $v['hm'], $v['web'] );
		if ( null === $in_use || null === $newest ) {
			return null;
		}
		$int = static fn( string $k ): int => is_int( $v[ $k ] ?? null ) ? $v[ $k ] : 0;
		return array(
			'hm'        => $v['hm'],
			'web'       => $v['web'],
			'agreed_at' => $int( 'agreed_at' ),
			'agreed_by' => $int( 'agreed_by' ),
			'in_use'    => $in_use,
			'since'     => $int( 'since' ),
			'newest'    => $newest,
			'last_try'  => $int( 'last_try' ),
			'kept'      => $int( 'kept' ),
		);
	}

	/**
	 * The rate a sync converts prices with, called inside the sync lock. Fetches a newer list when it is due,
	 * then: not agreed → SYNC_CURRENCY (as without conversion); agreed for other currencies → FX_PAIR_CHANGED;
	 * HostelMate's list too old → FX_TOO_OLD. Otherwise 'pair' is the rate in use and 'next' a newer one to use
	 * in a sync of its own (null when none, or when it moved too much and waits for the owner).
	 *
	 * @param string $hm  HostelMate's currency.
	 * @param string $web HBook's currency.
	 * @param int    $now Now.
	 * @return array{code: string, pair: FxPair|null, next: FxPair|null}
	 */
	public static function for_sync( string $hm, string $web, int $now ): array {
		$stored = self::stored();
		$stop   = static fn( string $code ): array => array(
			'code' => $code,
			'pair' => null,
			'next' => null,
		);
		if ( null === $stored ) {
			return $stop( Messages::SYNC_CURRENCY );
		}
		if ( strtoupper( $hm ) !== $stored['hm'] || strtoupper( $web ) !== $stored['web'] ) {
			Alerts::open( Messages::FX_PAIR_CHANGED, Config::ALERT_LEVEL_RED, $stored['hm'] . '>' . $stored['web'] . ' now ' . strtoupper( $hm ) . '>' . strtoupper( $web ) );
			return $stop( Messages::FX_PAIR_CHANGED );
		}
		// FX_PAIR_CHANGED is closed by the sync once /rates answers in the agreed currency, never here: closing it here
		// while HostelMate's answer still disagrees would reopen it, and email it again, every run.

		if ( self::refresh_due( $stored['newest']['refreshed'], $stored['last_try'], $now ) ) {
			$stored['last_try'] = $now;
			$list               = self::fetch( $now );
			$pair               = null === $list ? null : self::pair_from( $list, $stored['hm'], $stored['web'] );
			if ( null !== $list && null === $pair ) {
				Log::write( Config::LOG_LEVEL_WARN, Messages::FX_NO_RATE, null, null, $stored['hm'] . '>' . $stored['web'] );
			}
			if ( null !== $pair && $pair['refreshed'] >= $stored['newest']['refreshed'] ) {
				$stored['newest'] = $pair;
			}
			self::save( $stored );
		}

		if ( self::too_old( $stored['newest']['refreshed'], $now ) ) {
			Alerts::open( Messages::FX_TOO_OLD, Config::ALERT_LEVEL_RED );
			return $stop( Messages::FX_TOO_OLD );
		}
		Alerts::resolve_code( Messages::FX_TOO_OLD );

		$next = null;
		switch ( self::step( $stored['in_use'], $stored['newest'] ) ) {
			case self::USE:
				$next = $stored['newest'];
				Alerts::resolve_code( Messages::FX_HELD );
				break;
			case self::HOLD:
				if ( $stored['kept'] !== $stored['newest']['refreshed'] ) {
					Alerts::open( Messages::FX_HELD, Config::ALERT_LEVEL_RED, self::change_pct( $stored['in_use'], $stored['newest'] ) );
				}
				break;
			default:
				Alerts::resolve_code( Messages::FX_HELD );
		}
		return array(
			'code' => '',
			'pair' => $stored['in_use'],
			'next' => $next,
		);
	}

	/**
	 * The rate website bookings are sent with now: the agreed pair for these currencies, while HostelMate's list
	 * is fresh enough. Never fetches (the sync does that). Null = bookings wait.
	 *
	 * @param string $hm  HostelMate's currency.
	 * @param string $web HBook's currency.
	 * @param int    $now Now.
	 * @return FxPair|null
	 */
	public static function for_bookings( string $hm, string $web, int $now ): ?array {
		$stored = self::stored();
		if ( null === $stored || strtoupper( $hm ) !== $stored['hm'] || strtoupper( $web ) !== $stored['web'] || self::too_old( $stored['newest']['refreshed'], $now ) ) {
			return null;
		}
		return $stored['in_use'];
	}

	/**
	 * A newer rate becomes the rate in use (from for_sync()'s 'next', or the owner's OK on a held one). Logged.
	 *
	 * @param FxPair $pair The newer pair.
	 * @param int    $now  Now.
	 */
	public static function use_rate( array $pair, int $now ): void {
		$stored = self::stored();
		if ( null === $stored || $pair['hm'] !== $stored['hm'] || $pair['web'] !== $stored['web'] ) {
			return;
		}
		$old              = $stored['in_use'];
		$stored['in_use'] = $pair;
		$stored['since']  = $now;
		$stored['kept']   = 0;
		self::save( $stored );
		Alerts::resolve_code( Messages::FX_HELD );
		Log::write( Config::LOG_LEVEL_INFO, Messages::FX_RATE_USED, null, null, self::shown_rate( $old ) . ' > ' . self::shown_rate( $pair ) . ' (' . self::change_pct( $old, $pair ) . ')' );
	}

	/**
	 * The held rate (moved more than Config::FX_HOLD_PCT), or null when none waits.
	 *
	 * @return FxPair|null
	 */
	public static function held(): ?array {
		$stored = self::stored();
		if ( null === $stored || self::HOLD !== self::step( $stored['in_use'], $stored['newest'] ) || $stored['kept'] === $stored['newest']['refreshed'] ) {
			return null;
		}
		return $stored['newest'];
	}

	/** The owner keeps the rate in use instead of the held one (asked again when an even newer rate arrives). */
	public static function keep_rate(): void {
		$stored = self::stored();
		if ( null === $stored ) {
			return;
		}
		$stored['kept'] = $stored['newest']['refreshed'];
		self::save( $stored );
		Alerts::resolve_code( Messages::FX_HELD );
		Log::write( Config::LOG_LEVEL_INFO, Messages::FX_RATE_KEPT, null, null, self::shown_rate( $stored['in_use'] ) . ' kept, not ' . self::shown_rate( $stored['newest'] ) );
	}

	/**
	 * One fetch of HostelMate's list. Null when it failed (logged), or when it claims a refresh more than a day in
	 * the future (a list that would look fresh for ever).
	 *
	 * @param int $now Now.
	 * @return array{refreshed: int, rates: array<string, string>}|null
	 */
	private static function fetch( int $now ): ?array {
		$result = Client::currency_rates();
		$data   = $result->data;
		if ( ! $result->is_ok() || ! is_array( $data ) || ! is_int( $data['refreshed'] ?? null ) || ! is_array( $data['rates'] ?? null ) || $data['refreshed'] > $now + DAY_IN_SECONDS ) {
			Log::write( Config::LOG_LEVEL_WARN, Messages::FX_FETCH_FAILED, null, null, $result->kind . ' ' . $result->status . ' ' . $result->code );
			return null;
		}
		$rates = array();
		foreach ( $data['rates'] as $code => $rate ) {
			if ( is_string( $code ) && is_string( $rate ) ) {
				$rates[ $code ] = $rate;
			}
		}
		return array(
			'refreshed' => $data['refreshed'],
			'rates'     => $rates,
		);
	}

	/**
	 * The pair for two currencies from a list (USD is 1), or null when HostelMate has no rate for one of them.
	 *
	 * @param array{refreshed: int, rates: array<string, string>} $rates_list From fetch().
	 * @param string                                              $hm   HostelMate's currency.
	 * @param string                                              $web  HBook's currency.
	 * @return FxPair|null
	 */
	private static function pair_from( array $rates_list, string $hm, string $web ): ?array {
		$rate = static fn( string $c ): ?string => 'USD' === $c ? '1' : ( $rates_list['rates'][ $c ] ?? null );
		$h    = $rate( $hm );
		$w    = $rate( $web );
		if ( null === $h || null === $w || null === self::rate_e8( $h ) || null === self::rate_e8( $w ) ) {
			return null;
		}
		return array(
			'hm'        => $hm,
			'web'       => $web,
			'hm_rate'   => $h,
			'web_rate'  => $w,
			'refreshed' => $rates_list['refreshed'],
		);
	}

	/**
	 * A stored pair, checked (usable rates, the expected currencies).
	 *
	 * @param mixed  $v   Stored value.
	 * @param string $hm  Expected HostelMate currency.
	 * @param string $web Expected HBook currency.
	 * @return FxPair|null
	 */
	public static function checked_pair( $v, string $hm, string $web ): ?array {
		$pair = self::stored_pair( $v );
		return null !== $pair && $pair['hm'] === $hm && $pair['web'] === $web ? $pair : null;
	}

	/**
	 * Any stored pair (the option, or the one saved with a booking), checked: two different currency codes,
	 * usable rates, a time. Null for anything else.
	 *
	 * @param mixed $v Stored value.
	 * @return FxPair|null
	 */
	public static function stored_pair( $v ): ?array {
		if ( ! is_array( $v ) || ! is_string( $v['hm'] ?? null ) || ! is_string( $v['web'] ?? null ) || 1 !== preg_match( '/^[A-Z]{3}\z/', $v['hm'] )
			|| 1 !== preg_match( '/^[A-Z]{3}\z/', $v['web'] ) || $v['hm'] === $v['web'] || ! is_int( $v['refreshed'] ?? null )
			|| null === self::rate_e8( $v['hm_rate'] ?? null ) || null === self::rate_e8( $v['web_rate'] ?? null ) ) {
			return null;
		}
		return array(
			'hm'        => $v['hm'],
			'web'       => $v['web'],
			'hm_rate'   => (string) $v['hm_rate'],
			'web_rate'  => (string) $v['web_rate'],
			'refreshed' => $v['refreshed'],
		);
	}

	/**
	 * Stores the state (not autoloaded: only syncs and the plugin's screens read it).
	 *
	 * @param FxStored $stored State.
	 */
	private static function save( array $stored ): void {
		update_option( Config::OPT_FX, $stored, false );
	}
}

<?php
/**
 * One sync run, HostelMate → HBook (plan 6.1). In P4 it stops at a preview; P5 adds the writing.
 * Every failure leaves HBook exactly as it was and says why.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Sync;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Core\BookingStore;
use HostelMate\HBook\Core\Clock;
use HostelMate\HBook\Core\Db;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\HBook\Dates;
use HostelMate\HBook\HBook\Occupancy;
use HostelMate\HBook\HBook\Options;
use HostelMate\HBook\HBook\Owned;
use HostelMate\HBook\HBook\Prices;
use HostelMate\HBook\HBook\Schema;
use HostelMate\HBook\HBook\Units;
use HostelMate\HBook\HBook\Writer;
use HostelMate\HBook\Integrations\Cache;
use HostelMate\HBook\HostelMate\Client;
use HostelMate\HBook\HostelMate\Result;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type Context from \HostelMate\HBook\Types
 * @phpstan-import-type HmRates from \HostelMate\HBook\Types
 * @phpstan-import-type HmNight from \HostelMate\HBook\Types
 * @phpstan-import-type PricePlan from \HostelMate\HBook\Types
 * @phpstan-import-type BlockPlan from \HostelMate\HBook\Types
 * @phpstan-import-type BrakeResult from \HostelMate\HBook\Types
 * @phpstan-import-type FxPair from \HostelMate\HBook\Sync\Fx
 */
final class SyncRunner {

	/** Fetch problems: one alert at a time, resolved by the next good fetch. */
	private const FETCH_ALERTS = array( Messages::SYNC_KEY_REFUSED, Messages::SYNC_PLAN, Messages::SYNC_BAD_ANSWER, Messages::SYNC_UNREACHABLE );

	/**
	 * One run. Returns the Messages code that describes how it ended.
	 *
	 * @param bool $manual True for "Check now" (runs in setup mode too, as a preview).
	 */
	public static function run( bool $manual = false ): string {
		$mode = \HostelMate\HBook\Core\Settings::mode();
		$live = Config::MODE_LIVE === $mode;
		if ( ! $manual && ! in_array( $mode, array( Config::MODE_PREVIEW, Config::MODE_LIVE ), true ) ) {
			return Messages::SYNC_NO_CHANGE;
		}
		if ( ! Db::lock( Config::LOCK_SYNC ) ) {
			return Messages::SYNC_BUSY;
		}
		try {
			return self::run_locked( $live );
		} finally {
			Db::unlock( Config::LOCK_SYNC );
		}
	}

	/**
	 * Everything after the lock.
	 *
	 * @param bool $live Write to HBook (P5) or only preview.
	 */
	private static function run_locked( bool $live ): string {
		$stop = self::preflight();
		if ( '' !== $stop ) {
			return $stop;
		}
		// Different currencies: the agreed rate, or stop as without conversion. Same currency: none of this runs.
		$fx   = null;
		$next = null;
		if ( self::fx_needed() ) {
			$rate = Fx::for_sync( self::hm_currency(), Options::currency(), time() );
			if ( '' !== $rate['code'] ) {
				if ( Messages::SYNC_CURRENCY === $rate['code'] ) {
					Alerts::open( Messages::SYNC_CURRENCY, Config::ALERT_LEVEL_RED );
				}
				return self::finish( $rate['code'], Config::LOG_LEVEL_ERROR );
			}
			$fx   = $rate['pair'];
			$next = $rate['next'];
			if ( null !== $next && ! $live ) {
				// Nothing is written before go-live, so a check simply shows the newest rate.
				Fx::use_rate( $next, time() );
				$fx   = $next;
				$next = null;
			}
		}
		$ctx = self::context();
		if ( array() === $ctx['room_units'] ) {
			return self::finish( Messages::SYNC_NO_MAPPING, Config::LOG_LEVEL_WARN );
		}

		$hm = self::fetch( $ctx['nights'], null === $fx ? Options::currency() : $fx['hm'] );
		if ( is_string( $hm ) ) {
			return $hm;
		}
		State::set( 'last_fetch_ok', time() );
		if ( null === $fx ) {
			return self::run_plan( $ctx, $hm, $live );
		}
		$code = self::run_plan( $ctx, $hm, $live, $fx );
		if ( null === $next || Messages::SYNC_NO_CHANGE !== $code ) {
			return $code; // HostelMate's own changes go first; a newer rate waits for a sync where nothing else changed.
		}
		// Nothing changed in HostelMate: this sync is the newer rate's own step (it moved at most Config::FX_HOLD_PCT,
		// far below the brake's Config::BRAKE_PRICE_JUMP_PCT, so the brake still only reacts to HostelMate's changes).
		$code = self::run_plan( $ctx, $hm, $live, $next );
		if ( in_array( $code, array( Messages::SYNC_APPLIED, Messages::SYNC_NO_CHANGE ), true ) ) {
			Fx::use_rate( $next, time() );
		}
		return $code;
	}

	/**
	 * HostelMate's answer with every price in the website's currency (closed nights and missing prices as they are).
	 * A price HBook can't store (decimal(14,2)) stops the run: nothing is written.
	 *
	 * @param HmRates $hm   HostelMate's checked answer.
	 * @param FxPair  $pair The pair to use.
	 * @return HmRates|string
	 */
	private static function converted( array $hm, array $pair ) {
		$out = array();
		try {
			foreach ( $hm as $room => $nights ) {
				foreach ( $nights as $night => $n ) {
					if ( null !== $n['price'] ) {
						$n['price'] = Fx::to_website( $n['price'], $pair );
						if ( $n['price'] > Config::HBOOK_MAX_MINOR ) {
							throw new \OverflowException( 'Too large for HBook.' );
						}
					}
					$out[ $room ][ $night ] = $n;
				}
			}
		} catch ( \OverflowException $e ) {
			Alerts::open( Messages::FX_TOO_BIG, Config::ALERT_LEVEL_RED );
			return self::finish( Messages::FX_TOO_BIG, Config::LOG_LEVEL_ERROR );
		}
		Alerts::resolve_code( Messages::FX_TOO_BIG );
		return $out;
	}

	/**
	 * Everything after HostelMate answered: plan, preview, and (live) write. Callers hold the sync lock.
	 *
	 * @param Context     $ctx  context().
	 * @param HmRates     $hm   HostelMate's checked answer for every horizon night, in HostelMate's currency.
	 * @param bool        $live Write to HBook, or only preview.
	 * @param FxPair|null $pair Converting: the pair to use. Null (same currency) runs exactly as before conversion existed.
	 */
	public static function run_plan( array $ctx, array $hm, bool $live, ?array $pair = null ): string {
		$last = self::last_prices();
		if ( null === $pair ) {
			$prices = PricePlanner::plan( $ctx['nights'], $ctx['seasons'], $ctx['own_seasons'], $ctx['rates'], $ctx['accoms'], $ctx['accom_room'], $hm, $last );
		} else {
			// The last known prices are kept in HostelMate's currency and converted here, like the answer itself, so a
			// new rate or a new pair never leaves an old-currency price on a night HostelMate gives no price for (found
			// live 2026-11-25: right after agreeing, three such nights got 90.00, the USD price, on an MXN website).
			$web = self::converted( $hm, $pair );
			// The last known prices go through the same conversion and the same HBook limit, as one night each.
			$web_last = is_string( $web ) ? $web : self::converted( array_map( static fn( int $price ): array => array( 'x' => array( 'price' => $price ) + self::night( array() ) ), $last ), $pair );
			if ( is_string( $web_last ) ) {
				return $web_last;
			}
			$web_last = array_map( static fn( array $n ): int => (int) $n['x']['price'], $web_last );
			if ( is_string( $web ) ) {
				return $web;
			}
			$prices               = PricePlanner::plan( $ctx['nights'], $ctx['seasons'], $ctx['own_seasons'], $ctx['rates'], $ctx['accoms'], $ctx['accom_room'], $web, $web_last );
			$prices['last_price'] = PricePlanner::new_last_prices( $ctx['nights'], $hm, $last ); // Stored unconverted.
			$hm                   = $web;
		}
		$blocks = BlockPlanner::plan( $ctx['nights'], $ctx['room_units'], $hm, $ctx['rows'], Occupancy::paid_needs_confirmation(), $ctx['hybrids'] );
		$brake  = self::brake( $ctx, $prices, $blocks );

		self::alert_if( array() !== $prices['foreign_high'], Messages::SYNC_FOREIGN_HIGH, $live ? Config::ALERT_LEVEL_RED : Config::ALERT_LEVEL_AMBER );
		self::alert_if( array() !== $blocks['mismatches'], Messages::SYNC_MISMATCH, $live ? Config::ALERT_LEVEL_RED : Config::ALERT_LEVEL_AMBER );
		// A room added later on either side (scenario 38): a quiet warning until it is matched.
		$property  = State::get( 'property' );
		$unmatched = Mapping::unmatched( Mapping::get(), is_array( $property ) ? $property : null, $ctx['units'] );
		self::alert_if( $unmatched['hbook'] > 0, Messages::MAP_HBOOK_UNMATCHED, Config::ALERT_LEVEL_AMBER );
		self::alert_if( $unmatched['hostelmate'] > 0, Messages::MAP_HM_UNMATCHED, Config::ALERT_LEVEL_AMBER );
		// A matched room or bed removed on either side after matching: those rooms silently drop out, so say so.
		$broken = is_array( $property ) && array() !== array_filter( Mapping::problems( Mapping::get(), $property, $ctx['units'] ), static fn( array $p ): bool => Mapping::LEVEL_ERROR === $p['level'] );
		self::alert_if( $broken, Messages::MAP_BROKEN, $live ? Config::ALERT_LEVEL_RED : Config::ALERT_LEVEL_AMBER );

		State::set( 'preview', Summary::build( $ctx, $prices, $blocks, $brake ) );
		if ( ! $live ) {
			self::mark_ok();
			return self::finish( Messages::PREVIEW_READY, Config::LOG_LEVEL_INFO );
		}
		return self::apply( $ctx, $prices, $blocks, $brake );
	}

	/**
	 * Writes the plan into HBook, in one transaction, when anything differs from what HBook has.
	 *
	 * @param Context     $ctx    context().
	 * @param PricePlan   $prices PricePlanner result.
	 * @param BlockPlan   $blocks BlockPlanner result.
	 * @param BrakeResult $brake  Brake result.
	 */
	private static function apply( array $ctx, array $prices, array $blocks, array $brake ): string {
		// Another plugin's high-priority seasons would make HBook pick prices at random: keep the
		// last prices (red alert is open) but still keep availability right.
		// HBook's own rules, coupons, discounts and extra-person rates are copied onto the plugin's seasons when they
		// are written; a change to them (found live 2026-11-24: an extra-adult rate added after go-live was not charged)
		// makes the prices be written again, not only a change from HostelMate.
		$links        = self::links_fingerprint( $ctx );
		$write_prices = array() === $prices['foreign_high'] && ( self::prices_differ( $ctx, $prices ) || State::get( 'base_links' ) !== $links );
		$all_units    = array_merge( ...array_values( $ctx['room_units'] ) );
		$block_units  = Writer::units_to_change( $blocks['wanted'], $ctx['rows'], $all_units, $ctx['today'] );
		if ( ! $write_prices && array() === $block_units ) {
			State::set( 'last_price', $prices['last_price'] );
			self::mark_ok();
			return self::finish( Messages::SYNC_NO_CHANGE, Config::LOG_LEVEL_INFO );
		}

		$exempt = ! State::get( 'first_live_done', false ) || (int) State::get( 'brake_override_until', 0 ) > time();
		if ( Brake::OK !== $brake['verdict'] && ! $exempt ) {
			State::set(
				'held',
				array(
					'at'    => time(),
					'brake' => $brake,
				)
			);
			Alerts::open( Messages::SYNC_HELD, Config::ALERT_LEVEL_RED );
			return self::finish( Messages::SYNC_HELD, Config::LOG_LEVEL_WARN );
		}

		$counts = array();
		try {
			Db::transaction(
				static function () use ( $ctx, $prices, $blocks, $all_units, $write_prices, &$counts ): void {
					if ( $write_prices ) {
						$counts = Writer::replace_prices( $prices, $ctx['seasons'] );
						\HostelMate\HBook\HBook\Tools::update_starting_prices( $prices['prices'], array_keys( $ctx['accom_room'] ), $ctx['nights'] );
					}
					$counts += Writer::sync_blocks( $blocks['wanted'], $ctx['rows'], $all_units, $ctx['today'] );
				}
			);
		} catch ( \Throwable $e ) {
			Alerts::open( Messages::SYNC_WRITE_FAILED, Config::ALERT_LEVEL_RED );
			Log::write( Config::LOG_LEVEL_ERROR, Messages::SYNC_WRITE_FAILED, null, null, get_class( $e ) . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine() );
			return Messages::SYNC_WRITE_FAILED;
		}

		State::set( 'last_price', $prices['last_price'] );
		if ( $write_prices ) {
			State::set( 'base_links', $links );
		}
		State::set( 'first_live_done', true );
		State::set( 'brake_override_until', 0 );
		State::set( 'held', null );
		Alerts::resolve( Messages::SYNC_HELD );
		Alerts::resolve( Messages::SYNC_WRITE_FAILED );
		$purged = Cache::purge();
		self::mark_ok();
		State::set(
			'last_run',
			array(
				'at'   => time(),
				'code' => Messages::SYNC_APPLIED,
			)
		);
		$detail = wp_json_encode(
			$counts + array(
				'block_units' => count( $block_units ),
				'cache'       => implode( '+', $purged ),
			)
		);
		Log::write( Config::LOG_LEVEL_INFO, Messages::SYNC_APPLIED, null, null, false === $detail ? '' : $detail );
		return Messages::SYNC_APPLIED;
	}

	/**
	 * True when HBook would charge anything other than the plan for a horizon night, or a night
	 * is not yet covered by one of the plugin's seasons.
	 *
	 * @param Context   $ctx    context().
	 * @param PricePlan $prices PricePlanner result.
	 */
	private static function prices_differ( array $ctx, array $prices ): bool {
		$season_of = Prices::season_per_night( $ctx['seasons'], $ctx['nights'] );
		$rate_of   = Prices::night_rates( $ctx['rates'] );
		$own       = array_flip( $ctx['own_seasons'] );
		foreach ( $ctx['nights'] as $night ) {
			$season = $season_of[ $night ];
			if ( ! isset( $own[ $season ] ) ) {
				return true;
			}
			foreach ( $prices['prices'] as $accom => $by_night ) {
				if ( isset( $by_night[ $night ] ) && ( $rate_of[ (int) $accom ][ $season ] ?? null ) !== $by_night[ $night ] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * What HBook links to its own seasons (not the plugin's): the links the plugin copies onto its seasons.
	 *
	 * @param Context $ctx context().
	 */
	private static function links_fingerprint( array $ctx ): string {
		return md5( (string) wp_json_encode( array_diff_key( Prices::season_links(), array_flip( $ctx['own_seasons'] ) ) ) );
	}

	/** Records a successful run (used for the "late" warning). */
	private static function mark_ok(): void {
		State::set( 'last_ok', time() );
		Alerts::resolve( Messages::SYNC_LATE );
		Alerts::resolve( Messages::SYNC_VERY_LATE );
	}

	/** Checks that stop a run before anything is fetched. Returns '' when all is well. */
	private static function preflight(): string {
		$problems = Schema::problems();
		$checks   = array(
			Messages::SYNC_NO_KEY   => Secret::has(),
			Messages::SYNC_SCHEMA   => array() === $problems,
			Messages::SYNC_CURRENCY => self::currency_matches(),
		);
		foreach ( $checks as $code => $ok ) {
			if ( Messages::SYNC_CURRENCY === $code && ! $ok && self::fx_needed() ) {
				continue; // Different currencies: run_locked() asks Fx, and opens or closes this alert itself.
			}
			if ( ! $ok ) {
				// The table problem is named (found live 2026-11-24: a MyISAM table gave only "not what this plugin expects").
				Alerts::open( $code, Config::ALERT_LEVEL_RED, Messages::SYNC_SCHEMA === $code ? Schema::summary( $problems ) : '' );
				return self::finish( $code, Config::LOG_LEVEL_ERROR );
			}
			if ( Messages::SYNC_CURRENCY !== $code ) {
				// The currency alert closes only once /rates answers in the right currency (fetch()): closing it here
				// and reopening it there opened a new alert, and sent a new email, every run.
				Alerts::resolve_code( $code );
			}
		}
		self::zone_alert();
		// An HBook version not tested yet whose tables still match: carry on, and say so (plan 11, scenario 39).
		Alerts::resolve_code( Messages::SYNC_HBOOK_UNTESTED );
		if ( ! Schema::is_tested_version() ) {
			Alerts::open( Messages::SYNC_HBOOK_UNTESTED, Config::ALERT_LEVEL_AMBER, 'HBook ' . Schema::version() );
		}
		return '';
	}

	/**
	 * What every planner needs, read once.
	 *
	 * @return Context
	 */
	public static function context(): array {
		$today   = Clock::today( Clock::site_zone() );
		$units   = Units::all();
		$end     = Dates::horizon_end( array_keys( $units ), $today, Config::HORIZON_PAD_DAYS, Config::HORIZON_CAP_DAYS );
		$nights  = Clock::nights( $today, Clock::add_days( $end, 1 ) );
		$seasons = Prices::seasons();

		$accom_room = array();
		$room_units = array();
		foreach ( Mapping::get() as $room => $list ) {
			foreach ( $list as $u ) {
				$accom_room[ $u['accom'] ] = $room;
				$room_units[ $room ][]     = $u['accom'] . ':' . $u['num'];
			}
		}
		$hybrids  = array();
		$property = State::get( 'property' );
		foreach ( is_array( $property ) ? (array) $property['rooms'] : array() as $room ) {
			if ( is_string( $room['hybrid_parent_id'] ?? null ) ) {
				$hybrids[ $room['hybrid_parent_id'] ][] = (string) $room['id'];
			}
		}
		return array(
			'today'       => $today,
			'nights'      => $nights,
			'units'       => $units,
			'accoms'      => array_keys( $units ),
			'accom_room'  => $accom_room,
			'room_units'  => $room_units,
			'hybrids'     => $hybrids,
			'seasons'     => $seasons,
			'own_seasons' => Owned::season_ids( $seasons ),
			'rates'       => Prices::rates(),
			'rows'        => Occupancy::read( $today, Clock::add_days( $end, 1 ) ) + array( 'created' => BookingStore::created_ids() ),
		);
	}

	/**
	 * /rates for the whole horizon, in chunks. Returns room → night → answer, or a Messages code.
	 *
	 * @param list<string> $nights   Horizon nights.
	 * @param string       $currency The currency HostelMate must answer in.
	 * @return HmRates|string
	 */
	private static function fetch( array $nights, string $currency ) {
		$out = array();
		// HostelMate refuses nights before its own today (backend api/client/utils.py nights_between). When its zone is
		// ahead of WordPress's, the website's "tonight" is already past for HostelMate: ask from HostelMate's today. The
		// night left out counts as closed on the website, as it is in HostelMate (BlockPlanner), and every other night
		// keeps syncing. Found live 2026-11-25: a blank zone (UTC) against a UTC−6 site stopped every sync from 00:23 to
		// 05:16 UTC.
		$from = Clock::today( self::hm_zone() );
		$ask  = array_values( array_filter( $nights, static fn( string $n ): bool => $n >= $from ) );
		foreach ( array_chunk( array() === $ask ? $nights : $ask, Config::RATES_MAX_NIGHTS ) as $chunk ) {
			$result = Client::rates( $chunk[0], $chunk[ count( $chunk ) - 1 ] );
			if ( ! $result->is_ok() ) {
				$code = match ( $result->kind ) {
					Result::UNAUTHORIZED, Result::FORBIDDEN => Result::refusal_message( $result->kind ),
					Result::INVALID => Messages::SYNC_BAD_ANSWER,
					default => Messages::SYNC_UNREACHABLE,
				};
				foreach ( self::FETCH_ALERTS as $other ) {
					if ( $other !== $code ) {
						Alerts::resolve( $other );
					}
				}
				Alerts::open( $code, Messages::SYNC_UNREACHABLE === $code ? Config::ALERT_LEVEL_AMBER : Config::ALERT_LEVEL_RED );
				Log::write( Config::LOG_LEVEL_ERROR, $code, null, null, $result->kind . ' ' . $result->status . ' ' . $result->code );
				State::set(
					'last_run',
					array(
						'at'   => time(),
						'code' => $code,
					)
				);
				return $code;
			}
			$data     = (array) $result->data;
			$property = State::get( 'property' );
			if ( ! is_array( $property ) || ( $property['id'] ?? '' ) !== ( $data['property_id'] ?? null ) ) {
				Alerts::open( Messages::SYNC_WRONG_PROPERTY, Config::ALERT_LEVEL_RED );
				return self::finish( Messages::SYNC_WRONG_PROPERTY, Config::LOG_LEVEL_ERROR );
			}
			Alerts::resolve( Messages::SYNC_WRONG_PROPERTY );
			if ( strtoupper( (string) $data['currency'] ) !== $currency ) {
				// HostelMate's currency changed since connecting. Converting: the agreed pair no longer fits.
				$code = Options::currency() === $currency ? Messages::SYNC_CURRENCY : Messages::FX_PAIR_CHANGED;
				Alerts::open( $code, Config::ALERT_LEVEL_RED );
				return self::finish( $code, Config::LOG_LEVEL_ERROR );
			}
			Alerts::resolve_code( Messages::SYNC_CURRENCY );
			Alerts::resolve_code( Messages::FX_PAIR_CHANGED );
			foreach ( (array) $data['rooms'] as $room => $info ) {
				foreach ( (array) ( is_array( $info ) ? $info['nights'] : array() ) as $night => $n ) {
					if ( is_array( $n ) ) {
						$out[ (string) $room ][ (string) $night ] = self::night( $n );
					}
				}
			}
		}
		foreach ( self::FETCH_ALERTS as $code ) {
			Alerts::resolve( $code );
		}
		return $out;
	}

	/**
	 * One night already checked by Schemas::rates(), in its exact shape.
	 *
	 * @param array<mixed> $n Checked night.
	 * @return HmNight
	 */
	private static function night( array $n ): array {
		return array(
			'price'     => is_int( $n['price'] ?? null ) ? $n['price'] : null,
			'closed'    => true === ( $n['closed'] ?? true ),
			'available' => is_int( $n['available'] ?? null ) ? $n['available'] : 0,
			'min_stay'  => is_int( $n['min_stay'] ?? null ) ? $n['min_stay'] : null,
			'max_stay'  => is_int( $n['max_stay'] ?? null ) ? $n['max_stay'] : null,
			'cta'       => true === ( $n['cta'] ?? false ),
			'ctd'       => true === ( $n['ctd'] ?? false ),
		);
	}

	/**
	 * Runs the brake over the first Config::BRAKE_WINDOW_DAYS nights.
	 *
	 * @param Context   $ctx    context().
	 * @param PricePlan $prices PricePlanner result.
	 * @param BlockPlan $blocks BlockPlanner result.
	 * @return BrakeResult
	 */
	private static function brake( array $ctx, array $prices, array $blocks ): array {
		$window   = array_slice( $ctx['nights'], 0, Config::BRAKE_WINDOW_DAYS );
		$units_of = array();
		foreach ( $ctx['room_units'] as $list ) {
			foreach ( $list as $unit ) {
				$units_of[ (int) strtok( $unit, ':' ) ][] = $unit;
			}
		}
		$now_blocked = array();
		foreach ( $ctx['rows']['blocks'] as $b ) {
			if ( Occupancy::is_own_block( $b ) ) {
				foreach ( Occupancy::block_nights( $b, $ctx['today'], Clock::add_days( $ctx['today'], Config::BRAKE_WINDOW_DAYS ) ) as $n ) {
					$now_blocked[ $b['accom_id'] . ':' . $b['accom_num'] ][] = $n;
				}
			}
		}
		return Brake::check( $window, array_keys( $ctx['accom_room'] ), $units_of, Summary::prices_now( $ctx, $window ), $prices['prices'], $now_blocked, $blocks['wanted'] );
	}

	/**
	 * HostelMate's last known price per room (for nights it gives no price).
	 *
	 * @return array<string, int>
	 */
	private static function last_prices(): array {
		$v   = State::get( 'last_price', array() );
		$out = array();
		foreach ( is_array( $v ) ? $v : array() as $room => $price ) {
			if ( is_int( $price ) ) {
				$out[ (string) $room ] = $price;
			}
		}
		return $out;
	}

	/**
	 * Safe to exchange money amounts: the currencies match as far as this site knows, and the
	 * last answer from HostelMate didn't say otherwise (its currency can change after connecting).
	 * Used by the booking run too, so a booking's prices are never sent in the wrong currency.
	 */
	public static function currency_ok(): bool {
		return self::currency_matches() && ! in_array( Messages::SYNC_CURRENCY, array_column( Alerts::list(), 'code' ), true );
	}

	/** The two currencies differ and both are known: only then does any conversion code run (Fx::needed). */
	private static function fx_needed(): bool {
		return Fx::needed( self::hm_currency(), Options::currency() );
	}

	/**
	 * The rate website bookings are converted with, when the currencies differ and conversion is agreed and usable;
	 * null otherwise (bookings wait). Only called when currency_ok() is false.
	 *
	 * @return FxPair|null
	 */
	public static function booking_fx(): ?array {
		if ( ! self::fx_needed() ) {
			return null;
		}
		$open = array_column( Alerts::list(), 'code' );
		if ( in_array( Messages::SYNC_CURRENCY, $open, true ) || in_array( Messages::FX_PAIR_CHANGED, $open, true ) ) {
			return null; // HostelMate's last answer disagreed with the agreed currencies.
		}
		return Fx::for_bookings( self::hm_currency(), Options::currency(), time() );
	}

	/** HostelMate's currency from the last /property ('' when not connected). */
	public static function hm_currency(): string {
		$property = State::get( 'property' );
		return is_array( $property ) ? strtoupper( (string) ( $property['currency'] ?? '' ) ) : '';
	}

	/** HostelMate's currency (from the last /property) equals HBook's. */
	private static function currency_matches(): bool {
		$property = State::get( 'property' );
		return is_array( $property ) && '' !== Options::currency() && strtoupper( (string) ( $property['currency'] ?? '' ) ) === Options::currency();
	}

	/** WordPress and HostelMate give the same UTC offset now and in six months (unknown zone = no complaint). */
	public static function zones_match(): bool {
		$property = State::get( 'property' );
		if ( ! is_array( $property ) ) {
			return true; // Not connected: nothing to compare.
		}
		$zone = (string) ( $property['timezone'] ?? '' );
		try {
			// A blank zone is UTC to HostelMate (backend api/helpers/main.py:715-719), so it is compared as UTC.
			$hm = new \DateTimeZone( '' === $zone ? 'UTC' : $zone );
		} catch ( \Exception $e ) {
			return false; // A zone this PHP doesn't know: can't compare, so say the zones may differ (amber), and carry on.
		}
		$wp = Clock::site_zone();
		foreach ( array( 'now', '+182 days' ) as $when ) {
			$t = new \DateTimeImmutable( $when );
			if ( $hm->getOffset( $t ) !== $wp->getOffset( $t ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * HostelMate's zone for this property, as HostelMate reads it: its own zone, or UTC when blank or one this PHP
	 * doesn't know (HostelMate falls back to UTC too, backend api/helpers/main.py:715-719).
	 */
	public static function hm_zone(): \DateTimeZone {
		$property = State::get( 'property' );
		$zone     = is_array( $property ) ? (string) ( $property['timezone'] ?? '' ) : '';
		try {
			return new \DateTimeZone( '' === $zone ? 'UTC' : $zone );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	/** The time-zone warning, naming both zones; one alert, replaced (not added to) if the zones change. */
	private static function zone_alert(): void {
		if ( self::zones_match() ) {
			Alerts::resolve_code( Messages::SYNC_TIMEZONE );
			return;
		}
		$property = State::get( 'property' );
		$zone     = is_array( $property ) ? (string) ( $property['timezone'] ?? '' ) : '';
		/* translators: 1: HostelMate's time zone, 2: WordPress's time zone */
		$ref = sprintf( __( 'HostelMate: %1$s · WordPress: %2$s', 'hostelmate-hbook' ), '' === $zone ? __( 'not set, so UTC', 'hostelmate-hbook' ) : $zone, wp_timezone_string() );
		foreach ( Alerts::list() as $alert ) {
			if ( Messages::SYNC_TIMEZONE === $alert['code'] && $alert['ref'] !== $ref ) {
				Alerts::resolve( Messages::SYNC_TIMEZONE, $alert['ref'] );
			}
		}
		Alerts::open( Messages::SYNC_TIMEZONE, Config::ALERT_LEVEL_AMBER, $ref );
	}

	/**
	 * Opens or resolves an alert.
	 *
	 * @param bool   $open  Open when true, resolve when false.
	 * @param string $code  Messages code.
	 * @param string $level Config::ALERT_LEVEL_*.
	 */
	private static function alert_if( bool $open, string $code, string $level ): void {
		if ( $open ) {
			Alerts::open( $code, $level );
		} else {
			Alerts::resolve( $code );
		}
	}

	/**
	 * Logs how the run ended and returns the code.
	 *
	 * @param string $code  Messages code.
	 * @param string $level Config::LOG_LEVEL_*.
	 */
	private static function finish( string $code, string $level ): string {
		State::set(
			'last_run',
			array(
				'at'   => time(),
				'code' => $code,
			)
		);
		Log::write( $level, $code );
		return $code;
	}
}

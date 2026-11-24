<?php
/**
 * Open problems that need the owner: kept until resolved, shown on the screens,
 * and emailed at most once a day each (and at most Config::ALERT_EMAILS_PER_DAY in total).
 * Alerts carry codes and short references only, never guest data.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Alerts {

	/**
	 * Opens an alert (or keeps it open) and emails it when due.
	 *
	 * @param string $code  Messages::* code.
	 * @param string $level Config::ALERT_LEVEL_*.
	 * @param string $ref   What it is about: an HBook booking number, a room, a date range. No guest data.
	 */
	public static function open( string $code, string $level, string $ref = '' ): void {
		$ref   = self::clean_ref( $ref );
		$level = Config::ALERT_LEVEL_RED === $level ? Config::ALERT_LEVEL_RED : Config::ALERT_LEVEL_AMBER;
		$all   = self::all();
		$id    = $code . '|' . $ref;

		if ( ! isset( $all[ $id ] ) ) {
			if ( count( $all ) >= Config::ALERT_MAX_OPEN ) {
				return; // Enough is already on screen; never grow without limit.
			}
			$all[ $id ] = array(
				'code'       => $code,
				'ref'        => $ref,
				'level'      => $level,
				'first'      => time(),
				'last_email' => 0,
			);
		}
		$all[ $id ]['level'] = $level;

		if ( self::email_due( $all[ $id ] ) && self::send_email( $all[ $id ] ) ) {
			$all[ $id ]['last_email'] = time();
		}
		self::save( $all );
	}

	/**
	 * Closes one alert.
	 *
	 * @param string $code Messages::* code.
	 * @param string $ref  Same reference it was opened with.
	 */
	public static function resolve( string $code, string $ref = '' ): void {
		$all = self::all();
		$id  = $code . '|' . self::clean_ref( $ref );
		if ( isset( $all[ $id ] ) ) {
			unset( $all[ $id ] );
			self::save( $all );
		}
	}

	/**
	 * Closes every alert with this code, whatever its reference.
	 *
	 * @param string $code Messages::* code.
	 */
	public static function resolve_code( string $code ): void {
		$all  = self::all();
		$kept = array_filter( $all, static fn( array $a ): bool => $a['code'] !== $code );
		if ( count( $kept ) !== count( $all ) ) {
			self::save( $kept );
		}
	}

	/**
	 * Open alerts, red first, oldest first.
	 *
	 * @return list<array{code: string, ref: string, level: string, first: int, last_email: int}>
	 */
	public static function list(): array {
		$all = array_values( self::all() );
		usort(
			$all,
			static fn( array $a, array $b ): int => array( Config::ALERT_LEVEL_RED !== $a['level'], $a['first'] ) <=> array( Config::ALERT_LEVEL_RED !== $b['level'], $b['first'] )
		);
		return $all;
	}

	/**
	 * Stored alerts, each checked; malformed entries are dropped.
	 *
	 * @return array<string, array{code: string, ref: string, level: string, first: int, last_email: int}>
	 */
	private static function all(): array {
		$stored = get_option( Config::OPT_ALERTS, array() );
		$out    = array();
		if ( ! is_array( $stored ) ) {
			return $out;
		}
		foreach ( $stored as $id => $a ) {
			if ( is_string( $id ) && is_array( $a )
				&& is_string( $a['code'] ?? null ) && is_string( $a['ref'] ?? null ) && is_string( $a['level'] ?? null )
				&& is_int( $a['first'] ?? null ) && is_int( $a['last_email'] ?? null ) ) {
				$out[ $id ] = array(
					'code'       => $a['code'],
					'ref'        => $a['ref'],
					'level'      => $a['level'],
					'first'      => $a['first'],
					'last_email' => $a['last_email'],
				);
			}
		}
		return $out;
	}

	/**
	 * Writes the alerts option.
	 *
	 * @param array<string, array<string, mixed>> $all Alerts by id.
	 */
	private static function save( array $all ): void {
		update_option( Config::OPT_ALERTS, $all, false );
	}

	/**
	 * Red alerts are emailed; each at most once per Config::ALERT_EMAIL_EVERY.
	 *
	 * @param array{level: string, last_email: int} $alert The alert.
	 */
	private static function email_due( array $alert ): bool {
		return Config::ALERT_LEVEL_RED === $alert['level'] && time() - $alert['last_email'] >= Config::ALERT_EMAIL_EVERY;
	}

	/**
	 * Plain-text email to the alert address, within the daily budget.
	 *
	 * @param array{code: string, ref: string} $alert The alert.
	 */
	private static function send_email( array $alert ): bool {
		$to = Settings::alert_email();
		if ( ! Validate::is_email( $to ) || ! self::take_email_budget() ) {
			return false;
		}
		$site    = wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
		$text    = Messages::text( $alert['code'] );
		$subject = self::one_line( '[HostelMate] ' . $site . ': ' . $text );
		$body    = $text . ( '' !== $alert['ref'] ? "\n\n" . $alert['ref'] : '' )
			. "\n\n" . Messages::text( Messages::ALERT_EMAIL_FOOTER ) . "\n" . admin_url( 'admin.php?page=' . Config::MENU_SLUG );
		return (bool) wp_mail( $to, mb_substr( $subject, 0, 150 ), $body );
	}

	/** Counts today's emails; false once the daily budget is used up. */
	private static function take_email_budget(): bool {
		$today = gmdate( 'Y-m-d' );
		$used  = State::get( 'alert_emails', array() );
		$count = ( is_array( $used ) && ( $used['day'] ?? '' ) === $today && is_int( $used['count'] ?? null ) ) ? $used['count'] : 0;
		if ( $count >= Config::ALERT_EMAILS_PER_DAY ) {
			return false;
		}
		State::set(
			'alert_emails',
			array(
				'day'   => $today,
				'count' => $count + 1,
			)
		);
		return true;
	}

	/**
	 * A reference is short text with no control characters; anything else becomes ''.
	 *
	 * @param string $ref Raw reference.
	 */
	private static function clean_ref( string $ref ): string {
		$ref = self::one_line( $ref );
		return Validate::is_text( $ref, Config::ALERT_REF_MAX_LEN ) ? $ref : '';
	}

	/**
	 * No line breaks or control characters (they could add mail headers).
	 *
	 * @param string $text Raw text.
	 */
	private static function one_line( string $text ): string {
		return trim( preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text ) ?? '' );
	}
}

<?php
/**
 * Open alerts: a short notice on every admin page (for admins, red ones only), and
 * the list on the Overview, one notice per kind of problem however many cases it has.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Alerts;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Notices {

	/** Cases listed under one notice before "and N more". */
	private const SHOW_REFS = 30;

	/** admin_notices: one line when red alerts are open (not on the plugin's own pages). */
	public static function render(): void {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only compares the page name.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( str_starts_with( $page, Config::PAGE_OVERVIEW ) ) {
			return;
		}
		$red = count( self::groups( Config::ALERT_LEVEL_RED ) );
		if ( 0 === $red ) {
			return;
		}
		/* translators: %d: number of open problems */
		$text = sprintf( _n( 'HB-HostelMate: %d problem needs you.', 'HB-HostelMate: %d problems need you.', $red, 'hostelmate-hbook' ), $red );
		echo '<div class="notice notice-error"><p>' . esc_html( $text ) . ' <a href="' . esc_url( View::page_url( Config::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Open HB-HostelMate', 'hostelmate-hbook' ) . '</a></p></div>';
	}

	/**
	 * The open alerts on the Overview: one notice per kind, red first, the cases folded away.
	 *
	 * @param list<string> $skip Codes the page shows in its own words.
	 */
	public static function render_list( array $skip = array() ): void {
		foreach ( self::groups() as $code => $group ) {
			if ( in_array( $code, $skip, true ) ) {
				continue;
			}
			$type = Config::ALERT_LEVEL_RED === $group['level'] ? 'error' : 'warning';
			$refs = $group['refs'];
			echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( Messages::text( $code ) );
			if ( 1 === count( $refs ) ) {
				echo ' (' . esc_html( $refs[0] ) . ')';
			}
			echo '</p>';
			if ( count( $refs ) > 1 ) {
				/* translators: %d: number of cases */
				echo '<details><summary>' . esc_html( sprintf( _n( '%d case: show which', '%d cases: show which', count( $refs ), 'hostelmate-hbook' ), count( $refs ) ) ) . '</summary><p>';
				echo esc_html( implode( ', ', array_slice( $refs, 0, self::SHOW_REFS ) ) );
				if ( count( $refs ) > self::SHOW_REFS ) {
					/* translators: %d: number of cases not listed */
					echo ' ' . esc_html( sprintf( __( 'and %d more', 'hostelmate-hbook' ), count( $refs ) - self::SHOW_REFS ) );
				}
				echo '</p></details>';
			}
			echo '</div>';
		}
	}

	/**
	 * Open alerts grouped by code: the worst level and every reference, red groups first.
	 *
	 * @param string $only Only groups at this level ('' = all).
	 * @return array<string, array{level: string, refs: list<string>}>
	 */
	public static function groups( string $only = '' ): array {
		$groups = array();
		foreach ( Alerts::list() as $alert ) {
			$code = $alert['code'];
			if ( ! isset( $groups[ $code ] ) ) {
				$groups[ $code ] = array(
					'level' => $alert['level'],
					'refs'  => array(),
				);
			}
			if ( Config::ALERT_LEVEL_RED === $alert['level'] ) {
				$groups[ $code ]['level'] = Config::ALERT_LEVEL_RED;
			}
			if ( '' !== $alert['ref'] ) {
				$groups[ $code ]['refs'][] = $alert['ref'];
			}
		}
		if ( '' !== $only ) {
			$groups = array_filter( $groups, static fn( array $g ): bool => $g['level'] === $only );
		}
		uasort( $groups, static fn( array $a, array $b ): int => ( Config::ALERT_LEVEL_RED !== $a['level'] ) <=> ( Config::ALERT_LEVEL_RED !== $b['level'] ) );
		return $groups;
	}
}

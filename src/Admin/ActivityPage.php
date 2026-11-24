<?php
/**
 * Activity: the plugin's log, newest first, in plain words. Codes and ids only are
 * stored; the technical detail is folded away for support.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Log;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class ActivityPage {

	/** Prints the page. */
	public static function render(): void {
		View::guard_page();
		$rows = Log::recent( Config::ACTIVITY_PAGE_SIZE );
		echo '<div class="wrap">';
		View::heading( __( 'HB-HostelMate activity', 'hostelmate-hbook' ) );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Nothing has happened yet.', 'hostelmate-hbook' ) . '</p></div>';
			return;
		}
		/* translators: 1: number of entries, 2: days kept */
		echo '<p class="description">' . esc_html( sprintf( __( 'The last %1$d things the plugin did, newest first. Kept for %2$d days.', 'hostelmate-hbook' ), Config::ACTIVITY_PAGE_SIZE, Config::LOG_KEEP_DAYS ) ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'When', 'hostelmate-hbook' ), __( 'What happened', 'hostelmate-hbook' ), __( 'HBook booking', 'hostelmate-hbook' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$when  = strtotime( (string) $row['at'] . ' UTC' );
			$label = match ( (string) $row['level'] ) {
				Config::LOG_LEVEL_ERROR => __( 'Problem:', 'hostelmate-hbook' ) . ' ',
				Config::LOG_LEVEL_WARN  => __( 'Warning:', 'hostelmate-hbook' ) . ' ',
				default                 => '',
			};
			echo '<tr>';
			echo '<td>' . esc_html( false === $when ? '' : (string) wp_date( 'Y-m-d H:i', $when ) ) . '</td>';
			echo '<td>' . ( '' !== $label ? '<strong>' . esc_html( $label ) . '</strong>' : '' ) . esc_html( Messages::text( (string) $row['code'] ) );
			if ( '' !== (string) $row['detail'] ) {
				echo '<details><summary>' . esc_html__( 'Details for support', 'hostelmate-hbook' ) . '</summary><code>' . esc_html( (string) $row['code'] . ' ' . (string) $row['detail'] ) . '</code></details>';
			}
			echo '</td>';
			echo '<td>' . esc_html( null === $row['resa_id'] ? '' : '#' . (string) $row['resa_id'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}

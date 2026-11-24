<?php
/**
 * Activity: the plugin's log, newest first. Codes and ids only.
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
		echo '<div class="wrap"><h1>' . esc_html__( 'HostelMate activity', 'hostelmate-hbook' ) . '</h1>';
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'Nothing has happened yet.', 'hostelmate-hbook' ) . '</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'When', 'hostelmate-hbook' ), __( 'Level', 'hostelmate-hbook' ), __( 'What', 'hostelmate-hbook' ), __( 'Booking', 'hostelmate-hbook' ), __( 'Detail', 'hostelmate-hbook' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$when = strtotime( (string) $row['at'] . ' UTC' );
			echo '<tr>';
			echo '<td>' . esc_html( false === $when ? '' : (string) wp_date( 'Y-m-d H:i', $when ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['level'] ) . '</td>';
			echo '<td>' . esc_html( Messages::text( (string) $row['code'] ) ) . '</td>';
			echo '<td>' . esc_html( null === $row['resa_id'] ? '' : '#' . (string) $row['resa_id'] ) . '</td>';
			echo '<td><code>' . esc_html( (string) $row['detail'] ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}

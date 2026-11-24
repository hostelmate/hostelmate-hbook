<?php
/**
 * Rooms: match each HBook unit to a HostelMate room and bed.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\HBook\Units;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class RoomsPage {

	/** Prints the page. */
	public static function render(): void {
		View::guard_page();
		echo '<div class="wrap"><h1>' . esc_html__( 'Rooms', 'hostelmate-hbook' ) . '</h1>';
		View::action_notices();

		$property = State::get( 'property' );
		if ( ! is_array( $property ) ) {
			View::notice( 'warning', Messages::text( Messages::CONNECT_FIRST ) );
			echo '<p><a class="button button-primary" href="' . esc_url( View::page_url( Config::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Connect', 'hostelmate-hbook' ) . '</a></p></div>';
			return;
		}

		$units   = Units::all();
		$mapping = Mapping::get();
		$chosen  = self::chosen( $mapping );
		self::issues( $mapping, $property, $units );

		if ( array() === $mapping ) {
			$chosen = Mapping::suggest( $property, $units );
			View::notice( 'info', __( 'Matches below are suggested from the names. Check each one, then save.', 'hostelmate-hbook' ) );
		}

		echo '<p>' . esc_html__( 'Match every HBook room or bed to the same room or bed in HostelMate. Rooms left "not matched" keep their own HBook prices and dates.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_SAVE_MAPPING );
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th scope="col">' . esc_html__( 'HBook', 'hostelmate-hbook' ) . '</th><th scope="col">' . esc_html__( 'HostelMate', 'hostelmate-hbook' ) . '</th></tr></thead><tbody>';
		$options = self::options( $property );
		foreach ( $units as $accom ) {
			foreach ( $accom['units'] as $num ) {
				$key   = $accom['id'] . ':' . $num;
				$label = $accom['name'] . ( count( $accom['units'] ) > 1 ? ' · ' . $num : '' );
				echo '<tr><td><label for="hm-map-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><span class="description">' . esc_html( $accom['title'] ) . '</span></td><td>';
				echo '<select id="hm-map-' . esc_attr( $key ) . '" name="mapping[' . esc_attr( $key ) . ']">';
				echo '<option value="">' . esc_html__( '— not matched —', 'hostelmate-hbook' ) . '</option>';
				foreach ( $options as $value => $text ) {
					echo '<option value="' . esc_attr( $value ) . '"' . selected( $chosen[ $key ] ?? '', $value, false ) . '>' . esc_html( $text ) . '</option>';
				}
				echo '</select></td></tr>';
			}
		}
		echo '</tbody></table>';
		View::form_close( __( 'Save rooms', 'hostelmate-hbook' ) );
		echo '</div>';
	}

	/**
	 * "roomId|bedId" → label, for every HostelMate bed or whole room.
	 *
	 * @param array<string, mixed> $property Normalised /property answer.
	 * @return array<string, string>
	 */
	private static function options( array $property ): array {
		$out = array();
		foreach ( (array) $property['rooms'] as $room ) {
			if ( 'hybrid' === $room['sell_mode'] ) {
				$out[ $room['id'] . '|' ] = $room['name'] . ' · ' . __( 'whole room', 'hostelmate-hbook' );
				continue;
			}
			foreach ( (array) $room['beds'] as $bed ) {
				$out[ $room['id'] . '|' . $bed['id'] ] = $room['name'] . ' · ' . ( '' !== $bed['name'] ? $bed['name'] : __( 'unnamed bed', 'hostelmate-hbook' ) );
			}
		}
		return $out;
	}

	/**
	 * The saved mapping as "accom:num" → "roomId|bedId".
	 *
	 * @param array<string, list<array{accom: int, num: int, bed: string}>> $mapping Saved mapping.
	 * @return array<string, string>
	 */
	private static function chosen( array $mapping ): array {
		$out = array();
		foreach ( $mapping as $room_id => $list ) {
			foreach ( $list as $unit ) {
				$out[ $unit['accom'] . ':' . $unit['num'] ] = $room_id . '|' . $unit['bed'];
			}
		}
		return $out;
	}

	/**
	 * Problems from the last save (if any), else the problems of what is saved now.
	 *
	 * @param array<string, list<array{accom: int, num: int, bed: string}>> $mapping  Saved mapping.
	 * @param array<string, mixed>                                          $property Normalised /property answer.
	 * @param array<int, array{units: list<int>}>                           $units    HBook units.
	 */
	private static function issues( array $mapping, array $property, array $units ): void {
		$key    = Config::TRANSIENT_ISSUES . get_current_user_id();
		$issues = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $issues ) ) {
			$issues = Mapping::problems( $mapping, $property, $units );
		}
		foreach ( $issues as $issue ) {
			if ( is_array( $issue ) && is_string( $issue['code'] ?? null ) && is_string( $issue['ref'] ?? null ) ) {
				View::notice( Mapping::LEVEL_ERROR === ( $issue['level'] ?? '' ) ? 'error' : 'warning', Messages::text( $issue['code'] ) . ' (' . $issue['ref'] . ')' );
			}
		}
	}
}

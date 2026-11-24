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

	/** Prints the page (after setup; during setup the same table is step 2). */
	public static function render(): void {
		View::guard_page();
		echo '<div class="wrap">';
		View::heading( __( 'Rooms', 'hostelmate-hbook' ) );
		View::action_notices();
		echo '<div>';
		echo '<p>' . esc_html__( 'Each room and bed of your website points to the same room or bed in HostelMate.', 'hostelmate-hbook' ) . '</p>';
		self::matching( __( 'Save rooms', 'hostelmate-hbook' ) );
		echo '</div></div>';
	}

	/**
	 * The matching table with its save button. Used by Setup (step 2) and by this page.
	 *
	 * @param string $button Text of the save button (translated).
	 */
	public static function matching( string $button ): void {
		$property = State::get( 'property' );
		if ( ! is_array( $property ) ) {
			View::notice( 'warning', Messages::text( Messages::CONNECT_FIRST ) );
			return;
		}

		$units   = Units::all();
		$mapping = Mapping::get();
		$chosen  = self::chosen( $mapping );
		self::issues( $mapping, $property, $units );
		$suggested = array() === $mapping;
		if ( $suggested ) {
			$chosen = Mapping::suggest( $property, $units );
		}

		$total   = 0;
		$matched = 0;
		foreach ( $units as $accom ) {
			foreach ( $accom['units'] as $num ) {
				++$total;
				$matched += isset( $chosen[ $accom['id'] . ':' . $num ] ) && '' !== $chosen[ $accom['id'] . ':' . $num ] ? 1 : 0;
			}
		}
		/* translators: 1: matched, 2: all */
		echo '<p><strong>' . esc_html( sprintf( __( '%1$d of %2$d matched', 'hostelmate-hbook' ), $matched, $total ) ) . '</strong>';
		if ( $suggested ) {
			echo ' · ' . esc_html__( 'suggested from the names, not saved yet', 'hostelmate-hbook' );
		}
		echo '</p>';

		View::form_open( Config::ACTION_SAVE_MAPPING );
		echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'On your website (HBook)', 'hostelmate-hbook' ) . '</th><th scope="col">' . esc_html__( 'In HostelMate', 'hostelmate-hbook' ) . '</th></tr></thead><tbody>';
		$groups = self::options( $property );
		foreach ( $units as $accom ) {
			foreach ( $accom['units'] as $num ) {
				$key   = $accom['id'] . ':' . $num;
				$label = $accom['name'] . ( count( $accom['units'] ) > 1 ? ' · ' . $num : '' );
				$value = $chosen[ $key ] ?? '';
				echo '<tr><td><label for="hm-map-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label></td><td>';
				echo '<select id="hm-map-' . esc_attr( $key ) . '" name="mapping[' . esc_attr( $key ) . ']">';
				echo '<option value="">' . esc_html__( 'Not in HostelMate (keep HBook\'s own prices)', 'hostelmate-hbook' ) . '</option>';
				foreach ( $groups as $room => $options ) {
					echo '<optgroup label="' . esc_attr( $room ) . '">';
					foreach ( $options as $option => $text ) {
						echo '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . '>' . esc_html( $text ) . '</option>';
					}
					echo '</optgroup>';
				}
				echo '</select></td></tr>';
			}
		}
		echo '</tbody></table>';
		View::form_close( $button, 'button-hero' );

		echo '<details><summary>' . esc_html__( 'A room is missing from the HostelMate list?', 'hostelmate-hbook' ) . '</summary>';
		echo '<p>' . esc_html__( 'Add it in HostelMate first, then read the rooms again here.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_REFRESH_ROOMS );
		View::form_close( __( 'Read rooms from HostelMate again', 'hostelmate-hbook' ), 'button-secondary' );
		echo '</details>';
	}

	/**
	 * HostelMate room name → ["roomId|bedId" → label], for every bed or whole room.
	 *
	 * @param array<string, mixed> $property Normalised /property answer.
	 * @return array<string, array<string, string>>
	 */
	private static function options( array $property ): array {
		$out = array();
		foreach ( (array) $property['rooms'] as $room ) {
			$group = (string) $room['name'];
			if ( 'hybrid' === $room['sell_mode'] ) {
				$out[ $group ][ $room['id'] . '|' ] = $room['name'] . ' · ' . __( 'whole room', 'hostelmate-hbook' );
				continue;
			}
			foreach ( (array) $room['beds'] as $bed ) {
				$out[ $group ][ $room['id'] . '|' . $bed['id'] ] = $room['name'] . ' · ' . ( '' !== $bed['name'] ? $bed['name'] : __( 'unnamed bed', 'hostelmate-hbook' ) );
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
	 * A problem's reference in words: a room's name instead of its id.
	 *
	 * @param string                                 $ref      "accom:num", an HBook room id, a HostelMate room id, or a name.
	 * @param array<string, mixed>                   $property Normalised /property answer.
	 * @param array<int, array{name: string, units: list<int>}> $units    HBook units.
	 */
	private static function ref_name( string $ref, array $property, array $units ): string {
		if ( 1 === preg_match( '/^(\d+):(\d+)$/', $ref, $m ) ) {
			$accom = $units[ (int) $m[1] ] ?? null;
			if ( null === $accom ) {
				/* translators: %d: HBook room id */
				return sprintf( __( 'HBook room #%d, deleted', 'hostelmate-hbook' ), (int) $m[1] );
			}
			return $accom['name'] . ( count( $accom['units'] ) > 1 ? ' · ' . $m[2] : '' );
		}
		if ( 1 === preg_match( '/^\d+$/', $ref ) ) {
			/* translators: %d: HBook room id */
			return $units[ (int) $ref ]['name'] ?? sprintf( __( 'HBook room #%d, deleted', 'hostelmate-hbook' ), (int) $ref );
		}
		foreach ( (array) ( $property['rooms'] ?? array() ) as $room ) {
			if ( ( $room['id'] ?? '' ) === $ref ) {
				return (string) $room['name'];
			}
		}
		return \HostelMate\HBook\Core\Validate::is_uuid( $ref ) ? __( 'a HostelMate room that no longer exists', 'hostelmate-hbook' ) : $ref;
	}

	/**
	 * Problems from the last save (if any), else the problems of what is saved now.
	 *
	 * @param array<string, list<array{accom: int, num: int, bed: string}>> $mapping  Saved mapping.
	 * @param array<string, mixed>                                          $property Normalised /property answer.
	 * @param array<int, array{name: string, units: list<int>}>             $units    HBook units.
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
				View::notice( Mapping::LEVEL_ERROR === ( $issue['level'] ?? '' ) ? 'error' : 'warning', Messages::text( $issue['code'] ) . ' (' . self::ref_name( $issue['ref'], $property, $units ) . ')' );
			}
		}
	}
}

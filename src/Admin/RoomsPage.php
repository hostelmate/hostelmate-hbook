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
		$refused = self::issues( $mapping, $property, $units );
		// What the rows show: the picks of a save just refused (nothing was saved), else what is saved, else a guess.
		$chosen    = $refused ?? Mapping::picks( $mapping );
		$suggested = null === $refused && array() === $mapping;
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
		} elseif ( null !== $refused ) {
			echo ' · ' . esc_html__( 'your choices, not saved yet', 'hostelmate-hbook' );
		}
		echo '</p>';
		echo '<p>' . esc_html__( 'Each HostelMate bed can be picked for one website room or bed only, and all beds of one website room go to the same HostelMate room. A choice that would break this is greyed out: to free a HostelMate bed, set the row that has it to "Not in HostelMate".', 'hostelmate-hbook' ) . '</p>';

		// The script (assets/rooms.js) greys out choices the save would refuse, and says why; without it, the save still refuses them.
		wp_enqueue_script( 'hostelmate-hbook-rooms', plugins_url( 'assets/rooms.js', HOSTELMATE_HBOOK_FILE ), array(), Config::VERSION, true );
		View::form_open( Config::ACTION_SAVE_MAPPING );
		/* translators: %s: website rooms or beds, for example "Garden, Dorm · 2" */
		$used_by = __( 'used by %s', 'hostelmate-hbook' );
		/* translators: %s: website rooms or beds */
		$also  = __( 'Also picked for %s. Each HostelMate bed can be picked once.', 'hostelmate-hbook' );
		$split = __( 'The other beds of this website room are matched to a different HostelMate room. All beds of one website room go to the same HostelMate room.', 'hostelmate-hbook' );
		echo '<table class="widefat striped" id="hm-map" data-used-by="' . esc_attr( $used_by ) . '" data-also="' . esc_attr( $also ) . '" data-split="' . esc_attr( $split ) . '"><thead><tr><th scope="col">' . esc_html__( 'On your website (HBook)', 'hostelmate-hbook' ) . '</th><th scope="col">' . esc_html__( 'In HostelMate', 'hostelmate-hbook' ) . '</th></tr></thead><tbody>';
		$groups = self::options( $property );
		foreach ( $units as $accom ) {
			foreach ( $accom['units'] as $num ) {
				$key   = $accom['id'] . ':' . $num;
				$label = $accom['name'] . ( count( $accom['units'] ) > 1 ? ' · ' . $num : '' );
				$value = $chosen[ $key ] ?? '';
				echo '<tr><td><label for="hm-map-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label></td><td>';
				echo '<select id="hm-map-' . esc_attr( $key ) . '" name="mapping[' . esc_attr( $key ) . ']" data-accom="' . esc_attr( (string) $accom['id'] ) . '" data-label="' . esc_attr( $label ) . '" aria-describedby="hm-map-note-' . esc_attr( $key ) . '">';
				echo '<option value="">' . esc_html__( 'Not in HostelMate (keep HBook\'s own prices)', 'hostelmate-hbook' ) . '</option>';
				foreach ( $groups as $room => $options ) {
					echo '<optgroup label="' . esc_attr( $room ) . '">';
					foreach ( $options as $option => $text ) {
						echo '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . '>' . esc_html( $text ) . '</option>';
					}
					echo '</optgroup>';
				}
				echo '</select><p class="description hm-map-note" id="hm-map-note-' . esc_attr( $key ) . '" role="status" hidden></p></td></tr>';
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
	 * A problem's reference in words: a room's name instead of its id.
	 *
	 * @param string                                 $ref      "accom:num", an HBook room id, a HostelMate room id, or a name.
	 * @param array<string, mixed>                   $property Normalised /property answer.
	 * @param array<int, array{name: string, units: list<int>}> $units    HBook units.
	 * @param array<string, list<array{accom: int, num: int, bed: string}>> $mapping Saved mapping.
	 */
	private static function ref_name( string $ref, array $property, array $units, array $mapping ): string {
		$shared = Mapping::parse_bed_ref( $ref );
		if ( null !== $shared ) {
			// "Room 1 · B2: Garden, Dorm · 2".
			$bed = $shared['bed'];
			foreach ( (array) ( $property['rooms'] ?? array() ) as $room ) {
				foreach ( (array) ( $room['beds'] ?? array() ) as $b ) {
					if ( ( $b['id'] ?? '' ) === $shared['bed'] ) {
						$bed = $room['name'] . ' · ' . ( '' !== $b['name'] ? $b['name'] : __( 'unnamed bed', 'hostelmate-hbook' ) );
					}
				}
			}
			return $bed . ': ' . implode( ', ', array_map( fn( string $k ): string => self::ref_name( $k, $property, $units, $mapping ), $shared['units'] ) );
		}
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
		if ( isset( $mapping[ $ref ] ) ) {
			// A HostelMate room that is gone: name it by the website rooms that were matched to it.
			$names = array();
			foreach ( $mapping[ $ref ] as $u ) {
				$names[] = isset( $units[ $u['accom'] ] ) ? $units[ $u['accom'] ]['name'] . ( count( $units[ $u['accom'] ]['units'] ) > 1 ? ' · ' . $u['num'] : '' ) : '#' . $u['accom'];
			}
			/* translators: %s: website room names */
			return sprintf( __( 'matched to %s', 'hostelmate-hbook' ), implode( ', ', array_unique( $names ) ) );
		}
		return \HostelMate\HBook\Core\Validate::is_uuid( $ref ) ? __( 'a HostelMate room that no longer exists', 'hostelmate-hbook' ) : $ref;
	}

	/**
	 * Problems from the last save (if any), else the problems of what is saved now. Returns the
	 * picks of a save that was just refused (to show again), or null.
	 *
	 * @param array<string, list<array{accom: int, num: int, bed: string}>> $mapping  Saved mapping.
	 * @param array<string, mixed>                                          $property Normalised /property answer.
	 * @param array<int, array{name: string, units: list<int>}>             $units    HBook units.
	 * @return array<string, string>|null "accom:num" → "roomId|bedId", or null if no save was just refused.
	 */
	private static function issues( array $mapping, array $property, array $units ): ?array {
		$key  = Config::TRANSIENT_ISSUES . get_current_user_id();
		$last = get_transient( $key );
		delete_transient( $key );
		$issues = is_array( $last ) && is_array( $last['issues'] ?? null ) ? $last['issues'] : null;
		$picks  = null;
		if ( null !== $issues && is_array( $last['picks'] ?? null ) ) {
			// Only well-formed "accom:num" → "roomId|bedId" pairs, as the form sends them.
			$picks = Mapping::picks( Mapping::from_form( $last['picks'] ) );
			// Names of matched rooms that are gone come from what the owner picked, not from what is saved.
			$mapping = Mapping::from_form( $picks );
		}
		if ( null === $issues ) {
			$issues = Mapping::problems( $mapping, $property, $units );
		}
		foreach ( $issues as $issue ) {
			if ( is_array( $issue ) && is_string( $issue['code'] ?? null ) && is_string( $issue['ref'] ?? null ) ) {
				View::notice( Mapping::LEVEL_ERROR === ( $issue['level'] ?? '' ) ? 'error' : 'warning', Messages::text( $issue['code'] ) . ' (' . self::ref_name( $issue['ref'], $property, $units, $mapping ) . ')' );
			}
		}
		return $picks;
	}
}

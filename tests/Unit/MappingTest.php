<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Mapping;
use HostelMate\HBook\Messages;

/** A typical hostel layout: a private room, a dorm, a whole-room parent and its one-bed children. */
final class MappingTest extends TestCase {

	private const CAFE = '00000000-0000-4000-8000-0000000000a1';
	private const DORM   = '00000000-0000-4000-8000-0000000000d1';
	private const GARDEN  = '00000000-0000-4000-8000-0000000000c0';

	private static function bed( string $room, int $n ): string {
		// Unique per room: the room's last 6 hex digits, then the bed number.
		return sprintf( '11111111-0000-4000-8000-%s%06d', substr( $room, -6 ), $n );
	}

	private static function child( int $n ): string {
		return sprintf( '00000000-0000-4000-8000-%012d', 9000 + $n );
	}

	/** @return array<string, mixed> */
	private static function property(): array {
		$room  = static fn( string $id, string $name, string $sell, array $beds, ?string $parent = null ): array => array(
			'id'                 => $id,
			'name'               => $name,
			'active'             => true,
			'sell_mode'          => $sell,
			'sell_count'         => null,
			'hybrid_parent_id'   => $parent,
			'channel_id'         => 'cm-' . substr( $id, -4 ),
			'fake_capacity'      => null,
			'fake_max_available' => null,
			'beds'               => $beds,
		);
		$rooms = array(
			$room( self::CAFE, 'Café', 'per_room', array( array( 'id' => self::bed( self::CAFE, 1 ), 'name' => 'B1' ) ) ),
			$room( self::DORM, 'Mixed dorm', 'per_person', array( array( 'id' => self::bed( self::DORM, 74 ), 'name' => 'N°74' ), array( 'id' => self::bed( self::DORM, 76 ), 'name' => 'N°76' ), array( 'id' => self::bed( self::DORM, 6 ), 'name' => 'N°6' ) ) ),
			$room( self::GARDEN, 'Garden', 'hybrid', array() ),
		);
		foreach ( array( 62, 63 ) as $n ) {
			$rooms[] = $room( self::child( $n ), 'N°' . $n, 'per_room', array( array( 'id' => self::bed( self::child( $n ), 1 ), 'name' => 'B1' ) ), self::GARDEN );
		}
		return array(
			'id'       => '5f0c7a52-3b1e-4d8a-9c61-2e4b7d90a1f3',
			'name'     => 'Test Hostel',
			'currency' => 'MXN',
			'timezone' => 'America/Mexico_City',
			'rooms'    => $rooms,
		);
	}

	/** @return array<int, array{id: int, title: string, name: string, units: list<int>}> */
	private static function units(): array {
		$u = static fn( int $id, string $name, array $units = array( 1 ) ): array => array(
			'id'    => $id,
			'title' => $name . ' | Test Hostel, Beach Town',
			'name'  => $name,
			'units' => $units,
		);
		return array(
			10  => $u( 10, 'Café' ),
			74  => $u( 74, 'N°74' ),
			76  => $u( 76, 'N°76' ),
			600 => $u( 600, 'Garden' ),
			62  => $u( 62, 'Garden, N°62' ),
			63  => $u( 63, 'Garden, N°63' ),
			171 => $u( 171, 'Ocean', array( 5 ) ),
		);
	}

	public function test_suggestions_match_whole_words_only(): void {
		$s = Mapping::suggest( self::property(), self::units() );
		$this->assertSame( self::CAFE . '|' . self::bed( self::CAFE, 1 ), $s['10:1'] );
		$this->assertSame( self::DORM . '|' . self::bed( self::DORM, 74 ), $s['74:1'] );
		$this->assertSame( self::DORM . '|' . self::bed( self::DORM, 76 ), $s['76:1'] );
		$this->assertSame( self::GARDEN . '|', $s['600:1'] );
		// "Garden, N°62" goes to the child room N°62, never to dorm bed N°6 nor to the whole Garden room.
		$this->assertSame( self::child( 62 ) . '|' . self::bed( self::child( 62 ), 1 ), $s['62:1'] );
		$this->assertSame( self::child( 63 ) . '|' . self::bed( self::child( 63 ), 1 ), $s['63:1'] );
		$this->assertArrayNotHasKey( '171:5', $s ); // No room called Ocean: no guess.
	}

	public function test_suggested_mapping_saves_without_errors(): void {
		$mapping = Mapping::from_form( Mapping::suggest( self::property(), self::units() ) );
		$issues  = Mapping::save( $mapping, self::property(), self::units() );
		$this->assertSame( array(), $issues );
		$this->assertSame( $mapping, Mapping::get() );
	}

	public function test_form_parsing_drops_anything_malformed(): void {
		$form = array(
			'74:1'          => self::DORM . '|' . self::bed( self::DORM, 74 ),
			'600:1'         => self::GARDEN . '|',
			'76:1'          => '',
			'x:1'           => self::DORM . '|',
			'1:1'           => "' OR 1=1 --",
			'2:1'           => self::DORM . '|nope',
			'3:1'           => array( 'nested' ),
			"4:1\n"         => self::DORM . '|',
		);
		$m    = Mapping::from_form( $form );
		$this->assertSame( array( self::DORM, self::GARDEN ), array_keys( $m ) );
		$this->assertSame( array( array( 'accom' => 74, 'num' => 1, 'bed' => self::bed( self::DORM, 74 ) ) ), $m[ self::DORM ] );
		$this->assertSame( array(), Mapping::from_form( 'not an array' ) );
	}

	/** @return list<string> */
	private static function codes( array $mapping ): array {
		return array_column( Mapping::problems( $mapping, self::property(), self::units() ), 'code' );
	}

	public function test_errors(): void {
		$bed74 = self::bed( self::DORM, 74 );
		$this->assertContains( Messages::MAP_BED_TWICE, self::codes( array( self::DORM => array( array( 'accom' => 74, 'num' => 1, 'bed' => $bed74 ), array( 'accom' => 76, 'num' => 1, 'bed' => $bed74 ) ) ) ) );
		$this->assertContains( Messages::MAP_UNIT_TWICE, self::codes( array( self::DORM => array( array( 'accom' => 74, 'num' => 1, 'bed' => $bed74 ), array( 'accom' => 74, 'num' => 1, 'bed' => self::bed( self::DORM, 76 ) ) ) ) ) );
		$this->assertContains( Messages::MAP_UNKNOWN_UNIT, self::codes( array( self::DORM => array( array( 'accom' => 171, 'num' => 1, 'bed' => $bed74 ) ) ) ) );
		$this->assertContains( Messages::MAP_BED_REQUIRED, self::codes( array( self::DORM => array( array( 'accom' => 74, 'num' => 1, 'bed' => '' ) ) ) ) );
		$this->assertContains( Messages::MAP_UNKNOWN_BED, self::codes( array( self::DORM => array( array( 'accom' => 74, 'num' => 1, 'bed' => self::bed( self::CAFE, 1 ) ) ) ) ) );
		$this->assertContains( Messages::MAP_WHOLE_ROOM_NO_BED, self::codes( array( self::GARDEN => array( array( 'accom' => 600, 'num' => 1, 'bed' => $bed74 ) ) ) ) );
		$this->assertContains( Messages::MAP_UNKNOWN_ROOM, self::codes( array( '11111111-1111-4111-8111-111111111111' => array( array( 'accom' => 74, 'num' => 1, 'bed' => $bed74 ) ) ) ) );
		$split = array(
			self::DORM   => array( array( 'accom' => 74, 'num' => 1, 'bed' => $bed74 ) ),
			self::CAFE => array( array( 'accom' => 74, 'num' => 1, 'bed' => self::bed( self::CAFE, 1 ) ) ),
		);
		$this->assertContains( Messages::MAP_SPLIT_ACCOM, self::codes( $split ) );
	}

	public function test_errors_block_the_save_warnings_do_not(): void {
		$bed74 = self::bed( self::DORM, 74 );
		Mapping::save( array( self::DORM => array( array( 'accom' => 74, 'num' => 1, 'bed' => '' ) ) ), self::property(), self::units() );
		$this->assertSame( array(), Mapping::get() );

		$property                          = self::property();
		$property['rooms'][1]['channel_id']    = '';
		$property['rooms'][1]['fake_capacity'] = 3;
		$property['rooms'][1]['active']        = false;
		$issues = Mapping::save( array( self::DORM => array( array( 'accom' => 74, 'num' => 1, 'bed' => $bed74 ) ) ), $property, self::units() );
		$this->assertSame( array( Messages::MAP_NO_CHANNEL_ID, Messages::MAP_FAKE_NUMBERS, Messages::MAP_ROOM_OFF ), array_column( $issues, 'code' ) );
		$this->assertCount( 1, Mapping::get()[ self::DORM ] );
	}

	public function test_tampered_option_is_cleaned_on_read(): void {
		\WpStub::$options[ Config::OPT_MAPPING ] = array(
			'rooms' => array(
				self::DORM => array( array( 'accom' => '74', 'num' => 1, 'bed' => '' ), array( 'accom' => 76, 'num' => 1, 'bed' => '' ) ),
				'evil'     => array( array( 'accom' => 1, 'num' => 1, 'bed' => '' ) ),
			),
		);
		$this->assertSame( array( self::DORM => array( array( 'accom' => 76, 'num' => 1, 'bed' => '' ) ) ), Mapping::get() );
	}
}

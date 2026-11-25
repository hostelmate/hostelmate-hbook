<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Core\Ids;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Sync\BookingPlanner;
use HostelMate\HBook\Sync\BookingSender;
use HostelMate\HBook\Sync\Fx;

/** Website bookings into HostelMate's currency: the total then the split, each payment, the note, each booking's own rate. */
final class FxBookingsTest extends TestCase {

	private const UUID  = '11111111-2222-4333-8444-555555555555';
	private const ROOM  = '00000000-0000-4000-8000-0000000000d1';
	private const GUEST = '99999999-0000-4000-8000-000000000001';
	private const PAY   = '88888888-0000-4000-8000-000000000001';

	private const METHODS = array( 'stripe' => 'Credit Card', 'paypal' => 'Credit Card', 'other' => 'Cash' );

	/** 1 USD = 19.177967 MXN, refreshed 2026-11-24. */
	private static function pair( string $mxn = '19.17796700' ): array {
		return array(
			'hm'        => 'USD',
			'web'       => 'MXN',
			'hm_rate'   => '1',
			'web_rate'  => $mxn,
			'refreshed' => (int) strtotime( '2026-11-24T00:00:09Z' ),
		);
	}

	/** @return array<string, mixed> MX$3,346.60 over 2 nights. */
	private static function booking( array $over = array() ): array {
		return $over + array(
			'id'        => 4662,
			'accom_id'  => 74,
			'accom_num' => 1,
			'check_in'  => '2026-12-03',
			'check_out' => '2026-12-05',
			'guests'    => 2,
			'status'    => 'confirmed',
			'total'     => 334660,
			'number'    => 'A1B2C3',
			'guest'     => array( 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@example.com', 'phone' => '', 'country' => 'MX' ),
			'payments'  => array(),
		);
	}

	private static function target(): array {
		return array( 'room' => self::ROOM, 'bed' => '' );
	}

	/** @return array<string, mixed> Op data by op name (the first of each). */
	private static function data( array $plan ): array {
		$out = array();
		foreach ( $plan['ops'] as $op ) {
			$out[ $op['op'] ] = $out[ $op['op'] ] ?? $op['data'];
		}
		return $out;
	}

	/** What was sent after a converted create at $pair. */
	private static function sent_converted( array $pair ): array {
		$nights = array( '2026-12-03' => 8725, '2026-12-04' => 8725 );
		return array(
			'created'  => true,
			'guest_id' => self::GUEST,
			'status'   => 'confirmed',
			'room'     => self::ROOM,
			'bed'      => '',
			'nights'   => $nights,
			'fx'       => $pair,
			'guest_hash' => BookingPlanner::guest_hash( array( 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@example.com', 'phone' => '', 'country' => 'MX' ) ),
		) + BookingPlanner::nothing_sent( self::UUID );
	}

	public function test_same_currency_plan_is_unchanged(): void {
		$plan = BookingPlanner::plan( self::booking(), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS );
		$data = self::data( $plan );
		$this->assertSame( array( '2026-12-03' => 167330, '2026-12-04' => 167330 ), $data[ BookingPlanner::CREATE ]['nights'] );
		$this->assertNull( $data[ BookingPlanner::CREATE ]['fx'] );
		$this->assertStringNotContainsString( 'Website price', $data[ BookingPlanner::CREATE ]['notes'] );
	}

	public function test_same_currency_keys_are_what_they_were(): void {
		// Before conversion existed, the key hashed the sent record without an fx field.
		$sent   = BookingPlanner::nothing_sent( self::UUID );
		$old    = $sent;
		unset( $old['fx'], $old['blocked'] );
		$parts  = array( 'status', 'confirmed' );
		$key    = new \ReflectionMethod( BookingSender::class, 'key' );
		$this->assertSame( Ids::idempotency_key( self::UUID, (string) json_encode( $parts ), (string) json_encode( $old ) ), $key->invoke( null, $sent, ...$parts ) );
	}

	public function test_converted_create_total_first_then_split(): void {
		$plan = BookingPlanner::plan( self::booking(), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS, '0000-00-00', self::pair() );
		$this->assertSame( '', $plan['problem'] );
		$data   = self::data( $plan )[ BookingPlanner::CREATE ];
		$total  = Fx::to_hostelmate( 334660, self::pair() );
		$this->assertSame( 17450, $total ); // MX$3,346.60 = USD 174.50.
		$this->assertSame( array( '2026-12-03' => 8725, '2026-12-04' => 8725 ), $data['nights'] );
		$this->assertSame( $total, array_sum( $data['nights'] ) );
		$this->assertSame( self::pair(), $data['fx'] );
		$this->assertStringContainsString( 'Website price in MXN: total 3346.60 MXN = 174.50 USD (1 USD = 19.177967 MXN, HostelMate rate of 2026-11-24)', $data['notes'] );
	}

	public function test_odd_split_still_adds_up(): void {
		$plan   = BookingPlanner::plan( self::booking( array( 'check_out' => '2026-12-06', 'total' => 100001 ) ), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS, '0000-00-00', self::pair() );
		$nights = self::data( $plan )[ BookingPlanner::CREATE ]['nights'];
		$this->assertCount( 3, $nights );
		$this->assertSame( Fx::to_hostelmate( 100001, self::pair() ), array_sum( $nights ) );
	}

	public function test_a_sent_booking_keeps_its_own_rate(): void {
		// The rate moved to 21 since; the booking was sent at 19.177967: nothing to re-send.
		$plan = BookingPlanner::plan( self::booking(), self::sent_converted( self::pair() ), self::target(), false, self::METHODS, '0000-00-00', self::pair( '21.00000000' ) );
		$this->assertSame( array(), $plan['ops'] );

		// New dates later: converted at the booking's own rate, not today's.
		$plan = BookingPlanner::plan( self::booking( array( 'check_out' => '2026-12-06', 'total' => 501990 ) ), self::sent_converted( self::pair() ), self::target(), false, self::METHODS, '0000-00-00', self::pair( '21.00000000' ) );
		$nights = self::data( $plan )[ BookingPlanner::FIX_NIGHTS ]['nights'];
		$this->assertSame( Fx::to_hostelmate( 501990, self::pair() ), array_sum( $nights ) );
		$this->assertNotSame( Fx::to_hostelmate( 501990, self::pair( '21.00000000' ) ), array_sum( $nights ) );
	}

	public function test_payment_and_refund_net_to_zero_at_the_booking_rate(): void {
		$booking = self::booking(
			array(
				'payments' => array(
					array( 'ref' => '7', 'amount' => 334660, 'gateway' => 'stripe' ),
					array( 'ref' => '8', 'amount' => -334660, 'gateway' => 'stripe' ),
				),
			)
		);
		$plan    = BookingPlanner::plan( $booking, self::sent_converted( self::pair() ), self::target(), false, self::METHODS, '0000-00-00', self::pair( '25.00000000' ) );
		$by_ref  = array();
		foreach ( $plan['ops'] as $op ) {
			if ( BookingPlanner::ADD_PAYMENT === $op['op'] ) {
				$by_ref[ $op['data']['ref'] ] = array( $op['data']['amount'], $op['data']['type'] );
			}
		}
		$this->assertSame( array( 17450, 'income' ), $by_ref['p7'] );
		$this->assertSame( array( 17450, 'outgoing' ), $by_ref['p8r'] );
	}

	public function test_a_payment_already_sent_is_not_patched_by_rounding(): void {
		$booking = self::booking( array( 'payments' => array( array( 'ref' => '7', 'amount' => 100000, 'gateway' => 'stripe' ) ) ) );
		$sent    = self::sent_converted( self::pair() );
		$sent['payments']['p7'] = array(
			'hm_id'  => self::PAY,
			'amount' => Fx::to_hostelmate( 100000, self::pair() ),
		);
		$this->assertSame( array(), BookingPlanner::plan( $booking, $sent, self::target(), false, self::METHODS, '0000-00-00', self::pair( '30' ) )['ops'] );
	}

	public function test_a_booking_sent_before_conversion_stays_unconverted(): void {
		$sent           = self::sent_converted( self::pair() );
		$sent['fx']     = null;
		$sent['nights'] = array( '2026-12-03' => 167330, '2026-12-04' => 167330 );
		$this->assertSame( array(), BookingPlanner::plan( self::booking(), $sent, self::target(), false, self::METHODS, '0000-00-00', self::pair() )['ops'] );
	}

	public function test_amounts_beyond_hostelmate_limits_are_held(): void {
		// MXN into VND: 2 nights of MX$999,999.99 is about 2.7 billion VND a night, over HostelMate's 9,999,999.99.
		$vnd  = array(
			'hm'        => 'VND',
			'web'       => 'MXN',
			'hm_rate'   => '25785.00000000',
			'web_rate'  => '19.17796700',
			'refreshed' => 1,
		);
		$plan = BookingPlanner::plan( self::booking( array( 'total' => 199999998 ) ), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS, '0000-00-00', $vnd );
		$this->assertSame( BookingPlanner::PROBLEM_TOO_BIG, $plan['problem'] );
		$this->assertSame( array(), $plan['ops'] );
		// A payment alone over the payment limit, on a booking already sent.
		$sent = self::sent_converted( $vnd );
		$big  = self::booking( array( 'payments' => array( array( 'ref' => '7', 'amount' => 900000000, 'gateway' => 'stripe' ) ) ) );
		$this->assertSame( BookingPlanner::PROBLEM_TOO_BIG, BookingPlanner::plan( $big, $sent, self::target(), false, self::METHODS )['problem'] );
	}

	public function test_the_sender_saves_the_rate_with_the_booking(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		$plan           = BookingPlanner::plan( self::booking(), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS, '0000-00-00', self::pair() );
		\WpStub::$queue = array(
			array(
				'response' => array( 'code' => 201 ),
				'body'     => (string) json_encode( array( 'bookingId' => self::UUID, 'guest' => array( 'id' => self::GUEST ) ) ),
			),
		);
		$r = BookingSender::send( 4662, BookingPlanner::nothing_sent( self::UUID ), $plan['ops'] );
		$this->assertSame( BookingSender::DONE, $r['end'] );
		$this->assertSame( self::pair(), $r['sent']['fx'] );
		$body = json_decode( (string) \WpStub::$requests[0]['args']['body'], true );
		$this->assertSame( array( array( 'date' => '2026-12-03', 'amount' => 8725 ), array( 'date' => '2026-12-04', 'amount' => 8725 ) ), $body['booking']['dates'] );
		$this->assertStringContainsString( '174.50 USD', $body['notes'] );
		$this->assertArrayNotHasKey( 'fx', $body, 'the rate is kept by the plugin, not sent' );
	}

	public function test_stored_pairs_are_checked(): void {
		$this->assertSame( self::pair(), Fx::stored_pair( self::pair() ) );
		$this->assertNull( Fx::stored_pair( array( 'hm' => 'USD', 'web' => 'USD' ) + self::pair() ) );
		$this->assertNull( Fx::stored_pair( array( 'web_rate' => '0' ) + self::pair() ) );
		$this->assertNull( Fx::stored_pair( array( 'hm' => 'usd' ) + self::pair() ) );
		$this->assertNull( Fx::stored_pair( null ) );
	}
}

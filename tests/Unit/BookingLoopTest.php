<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\HBook\Bookings;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\BookingPlanner;
use HostelMate\HBook\Sync\BookingSender;
use HostelMate\HBook\Sync\StatusRules;

final class BookingLoopTest extends TestCase {

	private const UUID  = '11111111-2222-4333-8444-555555555555';
	private const ROOM  = '00000000-0000-4000-8000-0000000000d1';
	private const BED   = '00000000-0000-4000-8000-000000000074';
	private const GUEST = '99999999-0000-4000-8000-000000000001';
	private const PAY   = '88888888-0000-4000-8000-000000000001';

	private const METHODS = array( 'stripe' => 'Credit Card', 'paypal' => 'Credit Card', 'other' => 'Cash' );

	/** @return array<string, mixed> */
	private static function booking( array $over = array() ): array {
		return $over + array(
			'id'        => 4662,
			'accom_id'  => 74,
			'accom_num' => 1,
			'check_in'  => '2026-12-03',
			'check_out' => '2026-12-06',
			'guests'    => 1,
			'status'    => 'new',
			'total'     => 100000, // 1,000.00 over 3 nights.
			'number'    => 'A1B2C3',
			'guest'     => array( 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'Ana@Example.com', 'phone' => '+52 1', 'country' => 'mx' ),
			'payments'  => array(),
		);
	}

	private static function target(): array {
		return array( 'room' => self::ROOM, 'bed' => self::BED );
	}

	/** @return list<string> */
	private static function ops( array $plan ): array {
		return array_column( $plan['ops'], 'op' );
	}

	public function test_status_rules(): void {
		$this->assertSame( StatusRules::RESERVED, StatusRules::wanted( 'new', false ) );
		$this->assertSame( StatusRules::CONFIRMED, StatusRules::wanted( 'confirmed', false ) );
		$this->assertSame( StatusRules::WAIT, StatusRules::wanted( 'waiting_payment', false ) );
		$this->assertSame( StatusRules::WAIT, StatusRules::wanted( 'pending', false ) );
		$this->assertSame( StatusRules::WAIT, StatusRules::wanted( 'multi_resa_pending', false ) );
		$this->assertSame( StatusRules::CANCELLED, StatusRules::wanted( 'cancelled', false ) );
		$this->assertSame( StatusRules::CANCELLED, StatusRules::wanted( null, false ) );
		$this->assertSame( StatusRules::RESERVED, StatusRules::wanted( 'some_new_status', false ) );
	}

	public function test_a_request_is_created_with_its_bed_and_an_even_split(): void {
		$plan = BookingPlanner::plan( self::booking(), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS );
		$this->assertSame( array( BookingPlanner::CREATE, BookingPlanner::SET_BED ), self::ops( $plan ) );
		$create = $plan['ops'][0]['data'];
		$this->assertSame( array( '2026-12-03' => 33333, '2026-12-04' => 33333, '2026-12-05' => 33334 ), $create['nights'] );
		$this->assertSame( 'ana@example.com', $create['guest']['email'] );
		$this->assertSame( 'MX', $create['guest']['country'] );
		$this->assertSame( 'Website', $create['channel'] );
		$this->assertStringContainsString( 'A1B2C3', $create['notes'] );
		$this->assertStringNotContainsString( 'Ana', $create['notes'] );
	}

	public function test_a_stay_that_has_ended_is_never_created_but_is_kept_up_to_date(): void {
		$ended = self::booking( array( 'check_in' => '2026-11-01', 'check_out' => '2026-11-03' ) );
		$this->assertSame( array(), self::ops( BookingPlanner::plan( $ended, BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS, '2026-11-24' ) ) );
		$sent = array( 'nights' => array( '2026-11-01' => 50000, '2026-11-02' => 50000 ) ) + self::sent_after_create();
		$ended['payments'] = array( array( 'ref' => '1', 'amount' => 100000, 'refunded' => 30000, 'gateway' => 'stripe' ) );
		$this->assertContains( BookingPlanner::ADD_PAYMENT, self::ops( BookingPlanner::plan( $ended, $sent, self::target(), false, self::METHODS, '2026-11-24' ) ) );
	}

	public function test_waiting_and_pending_bookings_are_not_sent(): void {
		foreach ( array( 'waiting_payment', 'pending', 'multi_resa_pending' ) as $status ) {
			$plan = BookingPlanner::plan( self::booking( array( 'status' => $status ) ), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS );
			$this->assertSame( array(), self::ops( $plan ), $status );
		}
	}

	private static function sent_after_create(): array {
		return array(
			'created'    => true,
			'guest_id'   => self::GUEST,
			'status'     => 'reserved',
			'room'       => self::ROOM,
			'bed'        => self::BED,
			'nights'     => array( '2026-12-03' => 33333, '2026-12-04' => 33333, '2026-12-05' => 33334 ),
			'guest_hash' => BookingPlanner::guest_hash( array( 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@example.com', 'phone' => '+52 1', 'country' => 'MX' ) ),
		) + BookingPlanner::nothing_sent( self::UUID );
	}

	public function test_nothing_to_do_when_in_step(): void {
		$this->assertSame( array(), self::ops( BookingPlanner::plan( self::booking(), self::sent_after_create(), self::target(), false, self::METHODS ) ) );
	}

	public function test_confirm_dates_bed_guest_and_payments(): void {
		$booking = self::booking(
			array(
				'status'    => 'confirmed',
				'check_out' => '2026-12-07',
				'guest'     => array( 'first_name' => 'Ana', 'last_name' => 'Ruiz Díaz', 'email' => 'ana@example.com', 'phone' => '+52 1', 'country' => 'MX' ),
				'payments'  => array( array( 'ref' => '1', 'amount' => 100000, 'refunded' => 0, 'gateway' => 'stripe' ) ),
			)
		);
		$plan = BookingPlanner::plan( $booking, self::sent_after_create(), array( 'room' => self::ROOM, 'bed' => '00000000-0000-4000-8000-000000000076' ), false, self::METHODS );
		$this->assertSame( array( BookingPlanner::FIX_NIGHTS, BookingPlanner::SET_BED, BookingPlanner::SET_STATUS, BookingPlanner::PATCH_GUEST, BookingPlanner::ADD_PAYMENT ), self::ops( $plan ) );
		$pay = $plan['ops'][4]['data'];
		$this->assertSame( array( 'p1', 100000, 'income', 'Credit Card', 'hbook:4662:p1' ), array( $pay['ref'], $pay['amount'], $pay['type'], $pay['method'], $pay['name'] ) );
	}

	public function test_refunds_are_their_own_payment_and_grow_by_patch(): void {
		$booking = self::booking( array( 'payments' => array( array( 'ref' => '1', 'amount' => 100000, 'refunded' => 20000, 'gateway' => 'paypal' ) ) ) );
		$sent    = self::sent_after_create();
		$sent['payments']['p1'] = array( 'hm_id' => self::PAY, 'amount' => 100000 );
		$plan    = BookingPlanner::plan( $booking, $sent, self::target(), false, self::METHODS );
		$this->assertSame( array( BookingPlanner::ADD_PAYMENT ), self::ops( $plan ) );
		$this->assertSame( array( 'p1r', 20000, 'outgoing' ), array( $plan['ops'][0]['data']['ref'], $plan['ops'][0]['data']['amount'], $plan['ops'][0]['data']['type'] ) );

		$sent['payments']['p1r'] = array( 'hm_id' => self::PAY, 'amount' => 20000 );
		$booking['payments'][0]['refunded'] = 50000;
		$plan = BookingPlanner::plan( $booking, $sent, self::target(), false, self::METHODS );
		$this->assertSame( array( BookingPlanner::PATCH_PAYMENT ), self::ops( $plan ) );
		$this->assertSame( 50000, $plan['ops'][0]['data']['amount'] );
	}

	public function test_cancel_decline_and_delete(): void {
		$sent = self::sent_after_create();
		foreach ( array( self::booking( array( 'status' => 'cancelled' ) ), null ) as $b ) {
			$this->assertSame( array( BookingPlanner::SET_STATUS ), self::ops( BookingPlanner::plan( $b, $sent, self::target(), false, self::METHODS ) ) );
		}
		$sent['status'] = 'cancelled';
		$this->assertSame( array(), self::ops( BookingPlanner::plan( null, $sent, self::target(), false, self::METHODS ) ) );
		// A booking that was never sent and is cancelled: nothing to do.
		$this->assertSame( array(), self::ops( BookingPlanner::plan( self::booking( array( 'status' => 'cancelled' ) ), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS ) ) );
		// Reopened in HBook after a cancel: status is set again.
		$this->assertSame( array( BookingPlanner::SET_STATUS ), self::ops( BookingPlanner::plan( self::booking(), $sent, self::target(), false, self::METHODS ) ) );
	}

	public function test_problems_stop_a_booking_before_any_call(): void {
		$this->assertSame( Messages::BOOKING_UNMATCHED, BookingPlanner::plan( self::booking(), BookingPlanner::nothing_sent( self::UUID ), null, false, self::METHODS )['problem'] );
		$no_email = self::booking( array( 'guest' => array( 'first_name' => 'Ana', 'last_name' => '', 'email' => 'not-an-email', 'phone' => '', 'country' => '' ) ) );
		$this->assertSame( Messages::BOOKING_NO_EMAIL, BookingPlanner::plan( $no_email, BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS )['problem'] );
		$bad_dates = self::booking( array( 'check_out' => '2026-12-03' ) );
		$this->assertSame( Messages::BOOKING_TOO_LONG, BookingPlanner::plan( $bad_dates, BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS )['problem'] );
	}

	public function test_single_name_gets_a_dash_and_control_characters_go(): void {
		$b    = self::booking( array( 'guest' => array( 'first_name' => "Cher\n", 'last_name' => '', 'email' => 'c@example.com', 'phone' => "1\t2", 'country' => '' ) ) );
		$plan = BookingPlanner::plan( $b, BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS );
		$this->assertSame( array( 'Cher', '-', '1 2' ), array( $plan['ops'][0]['data']['guest']['first_name'], $plan['ops'][0]['data']['guest']['last_name'], $plan['ops'][0]['data']['guest']['phone'] ) );
	}

	public function test_hbook_payment_logs_are_parsed_safely(): void {
		$json = '[{"amount":1242,"payment_method":"Stripe","gateway_info":"ch_x","online":"yes","id":1,"refunded_amount":0,"comment":"","received_on":"2026-11-23"},{"amount":"10.5","payment_method":"Cash","id":2,"refunded_amount":"2.25"},{"id":"x"},"junk"]';
		$this->assertSame(
			array(
				array( 'ref' => '1', 'amount' => 124200, 'refunded' => 0, 'gateway' => 'stripe' ),
				array( 'ref' => '2', 'amount' => 1050, 'refunded' => 225, 'gateway' => 'cash' ),
			),
			Bookings::payments( $json )
		);
		$this->assertSame( array(), Bookings::payments( 'not json' ) );
	}

	/** @param array<string, mixed> $body */
	private static function answer( int $status, array $body ): array {
		return array( 'response' => array( 'code' => $status ), 'body' => (string) json_encode( $body ) );
	}

	public function test_sender_creates_fixes_nights_and_sets_the_bed(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		$plan           = BookingPlanner::plan( self::booking(), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS );
		\WpStub::$queue = array(
			self::answer( 201, array( 'bookingId' => self::UUID, 'guest' => array( 'id' => self::GUEST ) ) ),
			self::answer( 200, array( 'dates' => array() ) ), // B1 nights fix (amounts differ).
			self::answer( 200, array( 'bed' => self::BED ) ),
		);
		$r = BookingSender::send( 4662, BookingPlanner::nothing_sent( self::UUID ), $plan['ops'] );
		$this->assertSame( BookingSender::DONE, $r['end'] );
		$this->assertTrue( $r['sent']['created'] );
		$this->assertSame( self::GUEST, $r['sent']['guest_id'] );
		$this->assertSame( self::BED, $r['sent']['bed'] );
		$this->assertCount( 3, \WpStub::$requests );
		$this->assertStringEndsWith( '/bookings', \WpStub::$requests[0]['url'] );
		$this->assertSame( 'PATCH', \WpStub::$requests[1]['args']['method'] );
		$this->assertStringContainsString( '"amount":33334', \WpStub::$requests[1]['args']['body'] );
		// The next plan has nothing left to do.
		$this->assertSame( array(), self::ops( BookingPlanner::plan( self::booking(), $r['sent'], self::target(), false, self::METHODS ) ) );
	}

	public function test_sender_adopts_after_an_id_conflict_and_stops_on_refusal(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		$plan           = BookingPlanner::plan( self::booking(), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS );
		\WpStub::$queue = array(
			self::answer( 409, array( 'code' => 'booking_id_conflict', 'message' => 'x' ) ),
			self::answer( 200, array( 'id' => self::UUID, 'status' => 'reserved', 'guest' => array( 'id' => self::GUEST ) ) ),
			self::answer( 200, array() ),
		);
		$r = BookingSender::send( 4662, BookingPlanner::nothing_sent( self::UUID ), $plan['ops'] );
		$this->assertSame( BookingSender::DONE, $r['end'] );
		$this->assertTrue( $r['sent']['created'] );
		$this->assertSame( array(), $r['sent']['nights'] ); // Unknown after adopting: fixed on the next pass.

		\WpStub::$requests = array();
		\WpStub::$queue    = array( self::answer( 409, array( 'code' => 'availability_conflict', 'message' => 'x' ) ) );
		$r = BookingSender::send( 4662, BookingPlanner::nothing_sent( self::UUID ), $plan['ops'] );
		$this->assertSame( BookingSender::REFUSED, $r['end'] );
		$this->assertFalse( $r['sent']['created'] );
		$this->assertCount( 1, \WpStub::$requests ); // Stopped at the first failure.
	}

	public function test_sender_never_sends_a_payment_twice(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		$booking        = self::booking( array( 'payments' => array( array( 'ref' => '1', 'amount' => 100000, 'refunded' => 0, 'gateway' => 'stripe' ) ) ) );
		$plan           = BookingPlanner::plan( $booking, self::sent_after_create(), self::target(), false, self::METHODS );
		\WpStub::$queue = array( self::answer( 200, array( array( 'id' => self::PAY, 'name' => 'hbook:4662:p1' ) ) ) ); // HostelMate already has it.
		$r = BookingSender::send( 4662, self::sent_after_create(), $plan['ops'] );
		$this->assertSame( BookingSender::DONE, $r['end'] );
		$this->assertSame( self::PAY, $r['sent']['payments']['p1']['hm_id'] );
		$this->assertCount( 1, \WpStub::$requests ); // Only the list, no POST.
	}

	public function test_a_to_b_to_a_sends_three_different_keys(): void {
		// Review finding 1: HostelMate replays an Idempotency-Key's answer for an hour. Confirmed → cancelled →
		// confirmed must be three different keys, or the last change is replayed and never applied.
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		$sent  = self::sent_after_create();
		$keys  = array();
		foreach ( array( 'confirmed', 'cancelled', 'confirmed' ) as $status ) {
			\WpStub::$queue = array( self::answer( 200, array() ) );
			$r               = BookingSender::send( 1, $sent, array( array( 'op' => BookingPlanner::SET_STATUS, 'data' => array( 'status' => $status ) ) ) );
			$sent            = $r['sent'];
			$last            = end( \WpStub::$requests );
			$keys[]          = $last['args']['headers']['Idempotency-Key'];
		}
		$this->assertCount( 3, array_unique( $keys ) );
		$this->assertSame( 'confirmed', $sent['status'] );
	}

	public function test_sender_end_kinds(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		$plan = BookingPlanner::plan( self::booking(), BookingPlanner::nothing_sent( self::UUID ), self::target(), false, self::METHODS );
		foreach ( array( 401 => BookingSender::STOP_ALL, 429 => BookingSender::STOP_RUN, 400 => BookingSender::INVALID, 502 => BookingSender::RETRY ) as $status => $end ) {
			\WpStub::$queue = array( self::answer( $status, array( 'code' => 'x', 'message' => 'y' ) ) );
			$this->assertSame( $end, BookingSender::send( 1, BookingPlanner::nothing_sent( self::UUID ), $plan['ops'] )['end'], (string) $status );
		}
		\WpStub::$queue = array( new \WP_Error( 'timeout', 'x' ) );
		$this->assertSame( BookingSender::RETRY, BookingSender::send( 1, BookingPlanner::nothing_sent( self::UUID ), $plan['ops'] )['end'] );
	}
}

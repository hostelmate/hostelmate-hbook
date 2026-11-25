<?php

declare(strict_types=1);

namespace HostelMate\HBook\Tests\Unit;

use HostelMate\HBook\Admin\Actions;
use HostelMate\HBook\Admin\CurrencyView;
use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;
use HostelMate\HBook\Messages;
use HostelMate\HBook\Sync\Fx;

/** The screens' currency parts: nothing while the currencies match; the checklist line; the four actions' guards. */
final class FxAdminTest extends TestCase {

	private function currencies( string $hostelmate, string $hbook ): void {
		State::set( 'property', array( 'id' => 'p', 'currency' => $hostelmate, 'rooms' => array() ) );
		\WpStub::$options['hb_currency'] = $hbook;
	}

	private function agree(): void {
		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( array( 'id' => 1, 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '20.00000000', 'fetched_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) ) ),
		);
		$this->assertSame( Messages::FX_AGREED, Fx::agree( 'USD', 'MXN', 1, time() ) );
		\WpStub::$requests = array();
	}

	public function test_same_currency_shows_nothing(): void {
		$this->currencies( 'USD', 'USD' );
		$this->assertFalse( CurrencyView::needed() );
		$this->assertSame( array(), CurrencyView::checklist_items() );
		$this->assertSame( '', CurrencyView::diagnostics_line() );
		ob_start();
		CurrencyView::offer();
		CurrencyView::rate_line();
		CurrencyView::held();
		CurrencyView::settings_section();
		$this->assertSame( '', ob_get_clean() );
		$this->assertSame( array(), \WpStub::$requests );
	}

	public function test_checklist_warns_until_converted(): void {
		$this->currencies( 'USD', 'MXN' );
		$items = CurrencyView::checklist_items();
		$this->assertCount( 1, $items );
		$this->assertFalse( $items[0]['ok'] );
		$this->assertFalse( $items[0]['required'], 'a warning, like every item but the room match' );
		$this->assertStringContainsString( 'not agreed', CurrencyView::diagnostics_line() );
		$this->agree();
		$this->assertTrue( CurrencyView::checklist_items()[0]['ok'] );
		$this->assertStringContainsString( 'fx USD>MXN rate 20.000000', CurrencyView::diagnostics_line() );
	}

	public function test_a_pair_changed_since_is_not_agreed(): void {
		$this->currencies( 'USD', 'MXN' );
		$this->agree();
		$this->currencies( 'EUR', 'MXN' );
		$this->assertFalse( CurrencyView::agreed() );
		$this->assertStringContainsString( '(other pair now)', CurrencyView::diagnostics_line() );
	}

	public function test_action_guards(): void {
		$this->currencies( 'USD', 'MXN' );
		$this->assertSame( array( Messages::CONNECT_FIRST ), Actions::fx_agree( array() ) );
		$this->assertSame( array( Messages::NOTHING_HELD ), Actions::fx_use_held( array() ) );
		$this->assertSame( array( Messages::NOTHING_HELD ), Actions::fx_keep_held( array() ) );
		$this->agree();
		$this->assertSame( array( Messages::CONFIRM_MISSING ), Actions::fx_stop( array() ) );
		$this->assertNotNull( Fx::stored() );
		$this->assertSame( array( Messages::FX_STOPPED ), Actions::fx_stop( array( 'confirm' => '1' ) ) );
		$this->assertNull( Fx::stored() );
	}

	public function test_agree_with_the_same_currency_stores_nothing(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		$this->currencies( 'USD', 'USD' );
		$this->assertSame( array( Messages::FX_SAME ), Actions::fx_agree( array() ) );
		$this->assertArrayNotHasKey( Config::OPT_FX, \WpStub::$options );
		$this->assertSame( array(), \WpStub::$requests );
	}

	public function test_using_a_held_rate_while_not_live_skips_the_brake_override(): void {
		$this->currencies( 'USD', 'MXN' );
		$this->agree();
		$stored              = Fx::stored();
		$stored['newest']    = array( 'web_rate' => '25.00000000', 'refreshed' => time() ) + $stored['newest'];
		\WpStub::$options[ Config::OPT_FX ] = $stored;
		$this->assertNotNull( Fx::held() );
		Settings::set_mode( Config::MODE_SETUP );
		$this->assertSame( array( Messages::FX_RATE_USED ), Actions::fx_use_held( array() ) );
		$this->assertSame( '25.00000000', Fx::stored()['in_use']['web_rate'] );
		$this->assertSame( 0, (int) State::get( 'brake_override_until', 0 ) );
	}

	public function test_agreeing_while_live_is_the_owners_ok_for_the_repricing(): void {
		Secret::store( 'hmk_live_0123456789abcdefABCDEF' );
		$this->currencies( 'USD', 'MXN' );
		Settings::set_mode( Config::MODE_LIVE );
		\WpStub::$next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) json_encode( array( array( 'id' => 1, 'base_currency' => 'USD', 'quote_currency' => 'MXN', 'rate' => '20.00000000', 'fetched_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ) ) ),
		);
		$m = new \ReflectionMethod( Actions::class, 'fx_agree' );
		try {
			$m->invoke( null, array() );
		} catch ( \Throwable $e ) {
			// The sync after it needs the database; only the override matters here.
			unset( $e );
		}
		$this->assertNotNull( Fx::stored() );
		$this->assertGreaterThan( time(), (int) State::get( 'brake_override_until', 0 ) );
	}
}

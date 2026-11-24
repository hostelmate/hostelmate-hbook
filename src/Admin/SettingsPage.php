<?php
/**
 * Settings: the key (replace / remove), alert email, payment methods, check before booking.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	/** Prints the page. */
	public static function render(): void {
		View::guard_page();
		$settings = Settings::all();
		echo '<div class="wrap">';
		View::heading( __( 'HB-HostelMate settings', 'hostelmate-hbook' ) );
		View::action_notices();

		self::key_section();

		echo '<h2>' . esc_html__( 'Alerts and payments', 'hostelmate-hbook' ) . '</h2>';
		View::form_open( Config::ACTION_SAVE_SETTINGS );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="hm-alert-email">' . esc_html__( 'Alert email', 'hostelmate-hbook' ) . '</label></th><td>';
		echo '<input id="hm-alert-email" name="alert_email" type="email" class="regular-text" value="' . esc_attr( $settings['alert_email'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email', '' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'Problems that need you are emailed here, at most once a day each. Empty = the site admin email.', 'hostelmate-hbook' ) . '</p></td></tr>';

		$labels = array(
			'stripe' => __( 'Paid by card with Stripe', 'hostelmate-hbook' ),
			'paypal' => __( 'Paid with PayPal', 'hostelmate-hbook' ),
			'other'  => __( 'Payments added by hand in HBook', 'hostelmate-hbook' ),
		);
		foreach ( Config::PAYMENT_GATEWAYS as $gateway ) {
			$id = 'hm-method-' . $gateway;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $gateway ] ) . '</label></th><td>';
			echo '<input id="' . esc_attr( $id ) . '" name="payment_methods[' . esc_attr( $gateway ) . ']" type="text" class="regular-text" list="hm-methods" maxlength="' . esc_attr( (string) Config::PAYMENT_METHOD_MAX_LEN ) . '" value="' . esc_attr( $settings['payment_methods'][ $gateway ] ) . '">';
			echo '</td></tr>';
		}
		echo '<datalist id="hm-methods">';
		foreach ( Config::HM_PAYMENT_METHODS as $method ) {
			echo '<option value="' . esc_attr( $method ) . '">';
		}
		echo '</datalist>';

		echo '<tr><th scope="row">' . esc_html__( 'Check before booking', 'hostelmate-hbook' ) . '</th><td><label>';
		echo '<input name="precheck" type="checkbox" value="1"' . checked( $settings['precheck'], true, false ) . '> ';
		echo esc_html__( 'Ask HostelMate if the room is still free before a guest\'s booking is saved (recommended).', 'hostelmate-hbook' ) . '</label></td></tr>';
		echo '</tbody></table>';
		View::form_close( __( 'Save settings', 'hostelmate-hbook' ) );

		echo '<h2>' . esc_html__( 'Server cron (recommended)', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'WordPress runs its timers only when someone visits the site. Ask your host to run this every 5 minutes:', 'hostelmate-hbook' ) . '</p>';
		echo '<p><code>*/5 * * * * curl -s ' . esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' &gt;/dev/null</code></p>';

		echo '<h2>' . esc_html__( 'Diagnostics for HostelMate support', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'Copy this into your message to support. It holds no key and no guest data.', 'hostelmate-hbook' ) . '</p>';
		echo '<textarea readonly rows="12" class="large-text code">' . esc_textarea( Diagnostics::text() ) . '</textarea>';

		echo '<h2>' . esc_html__( 'Remove HostelMate data from this website', 'hostelmate-hbook' ) . '</h2>';
		echo '<p>' . esc_html__( 'Deletes every price season and block this plugin wrote into HBook. Your website then uses HBook\'s own seasons again, which may be months out of date, and nothing is kept in step until you go live again.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_REMOVE_DATA );
		echo '<p><label for="hm-remove">' . esc_html__( 'Type REMOVE to confirm', 'hostelmate-hbook' ) . '</label><br><input id="hm-remove" name="confirm" type="text" autocomplete="off"></p>';
		View::form_close( __( 'Remove HostelMate data', 'hostelmate-hbook' ), 'button-link-delete' );
		echo '</div>';
	}

	/** The key: where it lives, its last 4 characters, and how to remove it. */
	private static function key_section(): void {
		echo '<h2>' . esc_html__( 'HostelMate key', 'hostelmate-hbook' ) . '</h2>';
		if ( Secret::from_constant() ) {
			echo '<p>' . esc_html__( 'The key is set in wp-config.php. Change it there.', 'hostelmate-hbook' ) . '</p>';
			return;
		}
		if ( ! Secret::has() ) {
			echo '<p>' . esc_html__( 'No key yet.', 'hostelmate-hbook' ) . ' <a href="' . esc_url( View::page_url( Config::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Connect', 'hostelmate-hbook' ) . '</a></p>';
			return;
		}
		/* translators: %s: the last 4 characters of the key */
		echo '<p>' . esc_html( sprintf( __( 'A key ending in %s is saved, encrypted.', 'hostelmate-hbook' ), Secret::hint() ) ) . '</p>';
		View::form_open( Config::ACTION_REMOVE_KEY );
		echo '<p><label><input name="confirm" type="checkbox" value="1"> ' . esc_html__( 'Yes, remove the key. Nothing will be read from or sent to HostelMate until a new key is added.', 'hostelmate-hbook' ) . '</label></p>';
		View::form_close( __( 'Remove key', 'hostelmate-hbook' ), 'button-link-delete' );
	}
}

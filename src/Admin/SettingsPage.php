<?php
/**
 * Settings: the connection, where problem emails go, how website payments appear in
 * HostelMate, and (folded away) the advanced and support parts.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Core\Secret;
use HostelMate\HBook\Core\Settings;
use HostelMate\HBook\Core\State;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	/** Prints the page. */
	public static function render(): void {
		View::guard_page();
		$settings = Settings::all();
		echo '<div class="wrap">';
		View::heading( __( 'HB-HostelMate settings', 'hostelmate-hbook' ) );
		View::action_notices();

		self::connection();
		CurrencyView::settings_section();

		View::form_open( Config::ACTION_SAVE_SETTINGS );

		echo '<h2>' . esc_html__( 'Alerts', 'hostelmate-hbook' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="hm-alert-email">' . esc_html__( 'Send problems to', 'hostelmate-hbook' ) . '</label></th><td>';
		echo '<input id="hm-alert-email" name="alert_email" type="email" class="regular-text" value="' . esc_attr( $settings['alert_email'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email', '' ) ) . '">';
		echo '<p class="description">' . esc_html__( 'If something needs you (for example HostelMate can\'t be reached), we email this address, at most once a day per problem. Leave it empty to use the site\'s admin email.', 'hostelmate-hbook' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Bookings from your website', 'hostelmate-hbook' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Check before booking', 'hostelmate-hbook' ) . '</th><td><label>';
		echo '<input name="precheck" type="checkbox" value="1"' . checked( $settings['precheck'], true, false ) . '> ';
		echo esc_html__( 'Ask HostelMate if the room is still free before a guest\'s booking is saved', 'hostelmate-hbook' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Stops two guests booking the last bed at the same time on different channels. Recommended.', 'hostelmate-hbook' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'How website payments appear in HostelMate', 'hostelmate-hbook' ) . '</h3>';
		echo '<p>' . esc_html__( 'When a guest pays on your website, the payment is added to their booking in HostelMate. Pick the payment method it is recorded as.', 'hostelmate-hbook' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$labels = array(
			'stripe' => __( 'Guest pays by card (Stripe)', 'hostelmate-hbook' ),
			'paypal' => __( 'Guest pays with PayPal', 'hostelmate-hbook' ),
			'other'  => __( 'You add a payment by hand in HBook', 'hostelmate-hbook' ),
		);
		foreach ( Config::PAYMENT_GATEWAYS as $gateway ) {
			$id      = 'hm-method-' . $gateway;
			$current = $settings['payment_methods'][ $gateway ];
			// HostelMate's methods; a name saved earlier that isn't one of them stays selectable.
			$choices = in_array( $current, Config::HM_PAYMENT_METHODS, true ) ? Config::HM_PAYMENT_METHODS : array_merge( Config::HM_PAYMENT_METHODS, array( $current ) );
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $labels[ $gateway ] ) . '</label></th><td>';
			echo '<select id="' . esc_attr( $id ) . '" name="payment_methods[' . esc_attr( $gateway ) . ']">';
			foreach ( $choices as $method ) {
				echo '<option value="' . esc_attr( $method ) . '"' . selected( $current, $method, false ) . '>' . esc_html( $method ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		View::form_close( __( 'Save settings', 'hostelmate-hbook' ) );

		self::advanced();
		echo '</div>';
	}

	/** Which property this website is connected to, and Disconnect. */
	private static function connection(): void {
		echo '<h2>' . esc_html__( 'Connection', 'hostelmate-hbook' ) . '</h2>';
		if ( Secret::from_constant() ) {
			echo '<p>' . esc_html__( 'The API key is set in wp-config.php. Change it there.', 'hostelmate-hbook' ) . '</p>';
			return;
		}
		if ( ! Secret::has() ) {
			echo '<p>' . esc_html__( 'Not connected.', 'hostelmate-hbook' ) . ' <a href="' . esc_url( View::page_url( Config::PAGE_OVERVIEW ) ) . '">' . esc_html__( 'Connect', 'hostelmate-hbook' ) . '</a></p>';
			return;
		}
		$property = State::get( 'property' );
		$name     = is_array( $property ) ? (string) ( $property['name'] ?? '' ) : '';
		/* translators: 1: HostelMate property name, 2: the last 4 characters of the key */
		echo '<p>' . esc_html( sprintf( __( 'Connected to %1$s in HostelMate, with an API key ending in %2$s.', 'hostelmate-hbook' ), '' !== $name ? $name : '?', Secret::hint() ) ) . '</p>';
		echo '<details><summary>' . esc_html__( 'Disconnect', 'hostelmate-hbook' ) . '</summary>';
		echo '<p>' . esc_html__( 'Your website stops taking prices and free beds from HostelMate, and stops sending it bookings, until you connect again.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_REMOVE_KEY );
		echo '<p><label><input name="confirm" type="checkbox" value="1" required> ' . esc_html__( 'Yes, disconnect this website from HostelMate.', 'hostelmate-hbook' ) . '</label></p>';
		View::form_close( __( 'Disconnect', 'hostelmate-hbook' ), 'button-link-delete' );
		echo '</details>';
	}

	/** Server cron, diagnostics for support, and removing the plugin's data: folded away. */
	private static function advanced(): void {
		echo '<h2>' . esc_html__( 'Advanced', 'hostelmate-hbook' ) . '</h2>';

		echo '<details><summary>' . esc_html__( 'Keep it in step even when nobody visits the site', 'hostelmate-hbook' ) . '</summary>';
		echo '<p>' . esc_html__( 'WordPress only runs its timers when someone opens the site, so on a quiet night prices can fall behind. Ask your web host to add this "cron job", which opens the site every 5 minutes:', 'hostelmate-hbook' ) . '</p>';
		echo '<p><code>*/5 * * * * curl -s ' . esc_html( site_url( 'wp-cron.php?doing_wp_cron' ) ) . ' &gt;/dev/null</code></p>';
		echo '</details>';

		echo '<details><summary>' . esc_html__( 'Information for HostelMate support', 'hostelmate-hbook' ) . '</summary>';
		echo '<p>' . esc_html__( 'If you contact HostelMate support, copy this into your message. It holds no key and no guest data.', 'hostelmate-hbook' ) . '</p>';
		echo '<textarea readonly rows="12" class="large-text code">' . esc_textarea( Diagnostics::text() ) . '</textarea>';
		echo '</details>';

		echo '<details><summary>' . esc_html__( 'Remove HostelMate data from this website', 'hostelmate-hbook' ) . '</summary>';
		echo '<p>' . esc_html__( 'Deletes every price season and block this plugin wrote into HBook. Your website then uses HBook\'s own seasons again, which may be months out of date, and nothing is kept in step until you go live again.', 'hostelmate-hbook' ) . '</p>';
		View::form_open( Config::ACTION_REMOVE_DATA );
		echo '<p><label for="hm-remove">' . esc_html__( 'Type REMOVE to confirm', 'hostelmate-hbook' ) . '</label><br><input id="hm-remove" name="confirm" type="text" autocomplete="off"></p>';
		View::form_close( __( 'Remove HostelMate data', 'hostelmate-hbook' ), 'button-link-delete' );
		echo '</details>';
	}
}

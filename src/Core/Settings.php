<?php
/**
 * The owner's settings. Checked when saved and again when read, so a tampered
 * option can only ever fall back to safe defaults.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Core;

use HostelMate\HBook\Config;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/**
	 * Safe defaults for every setting.
	 *
	 * @return array{mode: string, alert_email: string, payment_methods: array<string, string>, precheck: bool}
	 */
	public static function defaults(): array {
		return array(
			'mode'            => Config::MODE_SETUP,
			'alert_email'     => '',
			'payment_methods' => array(
				'stripe' => 'Credit Card',
				'paypal' => 'Credit Card',
				'other'  => 'Cash',
			),
			'precheck'        => true,
		);
	}

	/**
	 * All settings, each one checked; anything invalid is replaced by its default.
	 *
	 * @return array{mode: string, alert_email: string, payment_methods: array<string, string>, precheck: bool}
	 */
	public static function all(): array {
		$stored   = get_option( Config::OPT_SETTINGS, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();

		$methods = $defaults['payment_methods'];
		if ( isset( $stored['payment_methods'] ) && is_array( $stored['payment_methods'] ) ) {
			foreach ( Config::PAYMENT_GATEWAYS as $gateway ) {
				$value = $stored['payment_methods'][ $gateway ] ?? null;
				if ( self::is_payment_method( $value ) ) {
					$methods[ $gateway ] = $value;
				}
			}
		}

		return array(
			'mode'            => Validate::is_one_of( $stored['mode'] ?? null, Config::MODES ) ? $stored['mode'] : $defaults['mode'],
			'alert_email'     => Validate::is_email( $stored['alert_email'] ?? null ) ? $stored['alert_email'] : $defaults['alert_email'],
			'payment_methods' => $methods,
			'precheck'        => is_bool( $stored['precheck'] ?? null ) ? $stored['precheck'] : $defaults['precheck'],
		);
	}

	/** Current mode (Config::MODE_*). */
	public static function mode(): string {
		return self::all()['mode'];
	}

	/**
	 * Changes the mode. Only the admin actions call this.
	 *
	 * @param string $mode Config::MODE_*.
	 */
	public static function set_mode( string $mode ): void {
		if ( ! Validate::is_one_of( $mode, Config::MODES ) ) {
			throw new \InvalidArgumentException( 'Unknown mode.' );
		}
		self::save( array( 'mode' => $mode ) + self::all() );
	}

	/** Where alerts go: the setting, or the site's admin email. */
	public static function alert_email(): string {
		$email = self::all()['alert_email'];
		return '' !== $email ? $email : (string) get_option( 'admin_email', '' );
	}

	/**
	 * HostelMate payment method for money taken through an HBook gateway.
	 *
	 * @param string $gateway HBook gateway id ('stripe', 'paypal', anything else counts as 'other').
	 */
	public static function payment_method( string $gateway ): string {
		$methods = self::all()['payment_methods'];
		return $methods[ in_array( $gateway, Config::PAYMENT_GATEWAYS, true ) ? $gateway : 'other' ];
	}

	/** Whether the check before booking runs. */
	public static function precheck_on(): bool {
		return self::all()['precheck'];
	}

	/**
	 * Saves the editable settings from the Settings screen (mode is not editable here).
	 *
	 * @param array<string, mixed> $input Already unslashed form values.
	 * @return list<string> Message codes for the fields that were refused (the rest is saved).
	 */
	public static function update( array $input ): array {
		$errors  = array();
		$current = self::all();

		$email = isset( $input['alert_email'] ) && is_string( $input['alert_email'] ) ? trim( $input['alert_email'] ) : '';
		if ( '' === $email || Validate::is_email( $email ) ) {
			$current['alert_email'] = $email;
		} else {
			$errors[] = Messages::SETTINGS_BAD_EMAIL;
		}

		foreach ( Config::PAYMENT_GATEWAYS as $gateway ) {
			$value = $input['payment_methods'][ $gateway ] ?? null;
			$value = is_string( $value ) ? trim( $value ) : $value;
			if ( self::is_payment_method( $value ) ) {
				$current['payment_methods'][ $gateway ] = $value;
			} elseif ( null !== $value ) {
				$errors[] = Messages::SETTINGS_BAD_METHOD;
			}
		}

		$current['precheck'] = ! empty( $input['precheck'] );

		self::save( $current );
		return array_values( array_unique( $errors ) );
	}

	/**
	 * A HostelMate built-in method, or a property account name (any text up to the limit).
	 *
	 * @param mixed $value Anything.
	 */
	private static function is_payment_method( $value ): bool {
		return Validate::is_text( $value, Config::PAYMENT_METHOD_MAX_LEN );
	}

	/**
	 * Writes the option (autoloaded: it is read on most requests).
	 *
	 * @param array<string, mixed> $values Full settings.
	 */
	private static function save( array $values ): void {
		update_option( Config::OPT_SETTINGS, $values, true );
	}
}

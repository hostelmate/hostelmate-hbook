<?php
/**
 * The only way in for admin actions. Every action is POST-only, needs its own
 * nonce and the plugin's capability, and receives only the fields declared for it.
 * A handler can't forget a check, because handlers are only ever called from here.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;

defined( 'ABSPATH' ) || exit;

final class ActionRouter {

	/**
	 * Action → [handler, fields it may read, page to return to]. The single list.
	 *
	 * @return array<string, array{0: callable(array<string, mixed>): list<string>, 1: list<string>, 2: string}>
	 */
	public static function routes(): array {
		return array(
			Config::ACTION_CONNECT       => array( array( Actions::class, 'connect' ), array( 'api_key' ), Config::PAGE_OVERVIEW ),
			Config::ACTION_REFRESH_ROOMS => array( array( Actions::class, 'refresh_rooms' ), array(), Config::PAGE_OVERVIEW ),
			Config::ACTION_REMOVE_KEY    => array( array( Actions::class, 'remove_key' ), array( 'confirm' ), Config::PAGE_SETTINGS ),
			Config::ACTION_SAVE_SETTINGS => array( array( Actions::class, 'save_settings' ), array( 'alert_email', 'payment_methods', 'precheck' ), Config::PAGE_SETTINGS ),
			Config::ACTION_SAVE_MAPPING  => array( array( Actions::class, 'save_mapping' ), array( 'mapping' ), Config::PAGE_ROOMS ),
			Config::ACTION_CHECK_NOW     => array( array( Actions::class, 'check_now' ), array(), Config::PAGE_OVERVIEW ),
			Config::ACTION_GO_LIVE       => array( array( Actions::class, 'go_live' ), array( 'accept_warnings' ), Config::PAGE_OVERVIEW ),
			Config::ACTION_PAUSE         => array( array( Actions::class, 'pause' ), array(), Config::PAGE_OVERVIEW ),
			Config::ACTION_RESUME        => array( array( Actions::class, 'resume' ), array(), Config::PAGE_OVERVIEW ),
			Config::ACTION_APPLY_HELD    => array( array( Actions::class, 'apply_held' ), array(), Config::PAGE_OVERVIEW ),
			Config::ACTION_DISCARD_HELD  => array( array( Actions::class, 'discard_held' ), array(), Config::PAGE_OVERVIEW ),
			Config::ACTION_REMOVE_DATA   => array( array( Actions::class, 'remove_data' ), array( 'confirm' ), Config::PAGE_SETTINGS ),
			Config::ACTION_CANCEL_COPIES => array( array( Actions::class, 'cancel_copies' ), array( 'confirm' ), Config::PAGE_OVERVIEW ),
			Config::ACTION_UNDO_COPIES   => array( array( Actions::class, 'undo_copies' ), array(), Config::PAGE_OVERVIEW ),
		);
	}

	/** Runs one admin action and redirects back with its result codes. */
	public static function handle(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'This action only accepts a form submission.', 'hostelmate-hbook' ), '', array( 'response' => 405 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the name only picks which nonce to verify, just below.
		$action = isset( $_POST['hm_action'] ) ? sanitize_key( wp_unslash( $_POST['hm_action'] ) ) : '';
		$routes = self::routes();
		if ( ! isset( $routes[ $action ] ) ) {
			wp_die( esc_html__( 'Unknown action.', 'hostelmate-hbook' ), '', array( 'response' => 400 ) );
		}
		check_admin_referer( Config::NONCE_PREFIX . $action );
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'hostelmate-hbook' ), '', array( 'response' => 403 ) );
		}

		list( $handler, $fields, $page ) = $routes[ $action ];
		$input                           = array();
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				// Raw (unslashed) values: each handler checks its own fields with Core\Validate.
				$input[ $field ] = wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			}
		}

		$codes = $handler( $input );
		$args  = array(
			'page'      => $page,
			'hm_notice' => implode( ',', $codes ),
		);
		// During setup there is one page (Menu::register): every action returns to it,
		// and a check stays on step 3 so its result is seen before moving on.
		if ( ! Setup::done() ) {
			$args['page'] = Config::PAGE_OVERVIEW;
			if ( Config::ACTION_CHECK_NOW === $action ) {
				$args['step'] = Setup::CHECK;
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}

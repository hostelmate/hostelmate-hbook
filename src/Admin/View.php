<?php
/**
 * Output helpers for the admin screens. Every value printed by the plugin goes
 * through esc_html / esc_attr / esc_url here or in the page that calls these.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Admin;

use HostelMate\HBook\Config;
use HostelMate\HBook\Messages;

defined( 'ABSPATH' ) || exit;

final class View {

	/** Stops the page for anyone without the plugin's capability. */
	public static function guard_page(): void {
		if ( ! current_user_can( Config::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to open this page.', 'hostelmate-hbook' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Opens a POST form for one ActionRouter action, with its nonce.
	 *
	 * @param string $action Config::ACTION_*.
	 */
	public static function form_open( string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( Config::ADMIN_POST_ACTION ) . '">';
		echo '<input type="hidden" name="hm_action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( Config::NONCE_PREFIX . $action );
	}

	/**
	 * Submit button and the end of the form.
	 *
	 * @param string $label Button text (already translated).
	 * @param string $style CSS class: button-primary, button-secondary or button-link-delete.
	 */
	public static function form_close( string $label, string $style = 'button-primary' ): void {
		$style = in_array( $style, array( 'button-primary', 'button-secondary', 'button-link-delete' ), true ) ? $style : 'button-secondary';
		echo '<p><button type="submit" class="button ' . esc_attr( $style ) . '">' . esc_html( $label ) . '</button></p></form>';
	}

	/**
	 * A WordPress admin notice.
	 *
	 * @param string $type error, warning, success or info.
	 * @param string $text Plain text (escaped here).
	 */
	public static function notice( string $type, string $text ): void {
		$type = in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . '"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Notices handed over by ActionRouter's redirect. Only known codes are shown;
	 * anything else in the URL is ignored.
	 */
	public static function action_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only; each code is checked against a fixed list.
		$raw = isset( $_GET['hm_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['hm_notice'] ) ) : '';
		foreach ( array_unique( explode( ',', $raw ) ) as $code ) {
			if ( in_array( $code, Messages::NOTICE_CODES, true ) ) {
				self::notice( in_array( $code, Messages::SUCCESS_CODES, true ) ? 'success' : 'error', Messages::text( $code ) );
			}
		}
	}

	/**
	 * Link to one of the plugin's pages.
	 *
	 * @param string $page Config::PAGE_*.
	 */
	public static function page_url( string $page ): string {
		return add_query_arg( 'page', $page, admin_url( 'admin.php' ) );
	}
}

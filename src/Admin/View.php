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

	/**
	 * The page heading.
	 *
	 * @param string $title Translated title.
	 */
	public static function heading( string $title ): void {
		echo '<h1>' . esc_html( $title ) . '</h1>';
	}

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
	 * @param string $style button-primary, button-hero (large primary), button-secondary or button-link-delete.
	 */
	public static function form_close( string $label, string $style = 'button-primary' ): void {
		$style = in_array( $style, array( 'button-primary', 'button-hero', 'button-secondary', 'button-link-delete' ), true ) ? $style : 'button-secondary';
		// button-hero: WordPress's large primary button, for the one main action of a setup step.
		$class = 'button-hero' === $style ? 'button-primary button-hero' : $style;
		echo '<p><button type="submit" class="button ' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></p></form>';
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
	 * @param string               $page Config::PAGE_*.
	 * @param array<string, string> $args Extra query arguments.
	 */
	public static function page_url( string $page, array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * A small table, or one line when empty.
	 *
	 * @param string             $title Heading ('' for none).
	 * @param list<string>       $heads Column headings.
	 * @param list<list<string>> $rows  Cells (plain text).
	 */
	public static function table( string $title, array $heads, array $rows ): void {
		if ( '' !== $title ) {
			echo '<h3>' . esc_html( $title ) . '</h3>';
		}
		if ( array() === $rows ) {
			echo '<p class="description">' . esc_html__( 'None.', 'hostelmate-hbook' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $heads as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $cells ) {
			echo '<tr>';
			foreach ( $cells as $cell ) {
				echo '<td>' . esc_html( $cell ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Stored summary lines → plain text cells (lists are joined), whatever was stored.
	 *
	 * @param array<mixed> $lines  Lines from the stored summary.
	 * @param list<string> $fields Which fields, in column order.
	 * @return list<list<string>>
	 */
	public static function cells( array $lines, array $fields ): array {
		$out = array();
		foreach ( $lines as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$row = array();
			foreach ( $fields as $field ) {
				$value = $line[ $field ] ?? '';
				$row[] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : ( is_scalar( $value ) ? (string) $value : '' );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * A link styled as a button.
	 *
	 * @param string $url   Where it goes.
	 * @param string $label Text (already translated).
	 * @param string $style button-primary or button-secondary.
	 */
	public static function link_button( string $url, string $label, string $style = 'button-primary' ): void {
		$style = 'button-primary' === $style ? 'button-primary' : 'button-secondary';
		echo '<a class="button ' . esc_attr( $style ) . ( 'button-primary' === $style ? ' button-hero' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}
}

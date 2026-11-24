<?php
/**
 * HBook's accommodations and their units: published, main-language posts only.
 * Units are the rows of hb_accom_num_name (their numbers are not always 1..n:
 * a room with one unit may have unit 5), which is what HBook books against (database-actions.php:840-851).
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\HBook;

use HostelMate\HBook\Integrations\Language;

defined( 'ABSPATH' ) || exit;

/**
 * @phpstan-import-type UnitsMap from \HostelMate\HBook\Types
 */
final class Units {

	/**
	 * Accommodation id → name and unit numbers, ordered by name.
	 *
	 * @return UnitsMap
	 */
	public static function all(): array {
		global $wpdb;
		$posts = get_posts(
			array(
				'post_type'        => 'hb_accommodation',
				'post_status'      => 'publish',
				'numberposts'      => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'lang'             => '', // Polylang: every language; filtered just below.
			)
		);
		$table = Schema::table( 'accom_num_name' );
		$rows  = $wpdb->get_results( "SELECT accom_id, accom_num FROM `{$table}` ORDER BY accom_id, accom_num", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- identifier from Schema only.
		$units = array();
		foreach ( (array) $rows as $row ) {
			$units[ (int) $row['accom_id'] ][] = (int) $row['accom_num'];
		}

		$out = array();
		foreach ( $posts as $post ) {
			$id = (int) $post->ID;
			if ( ! Language::is_main_language( $id ) || empty( $units[ $id ] ) ) {
				continue;
			}
			$title      = html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES, 'UTF-8' );
			$out[ $id ] = array(
				'id'    => $id,
				'title' => $title,
				'name'  => self::short_name( $title ),
				'units' => $units[ $id ],
			);
		}
		return $out;
	}

	/**
	 * The part of a title before its first "|" ("Sunrise | Test Hostel, Beach Town" → "Sunrise"),
	 * with any kind of space trimmed (titles use hair and non-breaking spaces).
	 *
	 * @param string $title Accommodation title.
	 */
	public static function short_name( string $title ): string {
		$first = explode( '|', $title, 2 )[0];
		$name  = preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $first ) ?? $first;
		return '' === $name ? $title : $name;
	}
}

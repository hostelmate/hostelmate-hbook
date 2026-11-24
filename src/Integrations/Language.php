<?php
/**
 * Multilingual sites. HBook keeps every booking, rate and block on the main-language
 * accommodation post (hbook/utils/utils.php:3290, database-actions.php:572-581), so the
 * plugin only ever works with main-language posts.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook\Integrations;

defined( 'ABSPATH' ) || exit;

final class Language {

	/**
	 * True when the post is in the site's main language (or the site has one language).
	 *
	 * @param int $post_id Accommodation post id.
	 */
	public static function is_main_language( int $post_id ): bool {
		if ( function_exists( 'pll_get_post_language' ) && function_exists( 'pll_default_language' ) ) {
			$lang = pll_get_post_language( $post_id );
			// A post Polylang knows no language for is treated as main language, like HBook does.
			return false === $lang || '' === $lang || pll_default_language() === $lang;
		}
		if ( has_filter( 'wpml_default_language' ) ) {
			$default = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			$main_id = apply_filters( 'wpml_object_id', $post_id, 'hb_accommodation', true, $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
			return (int) $main_id === $post_id;
		}
		return true;
	}
}

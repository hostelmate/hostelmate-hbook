<?php
/**
 * Every name and every limit, declared once. Nothing else in the plugin
 * spells an option key, table name, hook name or limit by hand.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook;

defined( 'ABSPATH' ) || exit;

final class Config {

	public const SLUG        = 'hostelmate-hbook';
	public const MENU_SLUG   = 'hostelmate-hbook';
	public const VERSION     = '1.1.0';
	public const TEXT_DOMAIN = 'hostelmate-hbook';

	/** Who may see and use the plugin's screens and actions. */
	public const CAPABILITY = 'manage_options';

	public const MIN_PHP = '8.2';
	public const MIN_WP  = '6.5';

	/** Version of the plugin's own tables; raise it when Db::tables() changes. */
	public const DB_VERSION = 2; // 2: hostelmate_bookings.failing_since.

	// Options. Only SETTINGS is autoloaded.
	public const OPT_SETTINGS = 'hostelmate_hbook_settings';
	public const OPT_KEY      = 'hostelmate_hbook_key';
	public const OPT_MAPPING  = 'hostelmate_hbook_mapping';
	public const OPT_STATE    = 'hostelmate_hbook_state';
	public const OPT_ALERTS   = 'hostelmate_hbook_alerts';
	public const OPT_FX       = 'hostelmate_hbook_fx'; // Only ever written while the two currencies differ (Sync\Fx).

	/** @var list<string> */
	public const OPTIONS = array( self::OPT_SETTINGS, self::OPT_KEY, self::OPT_MAPPING, self::OPT_STATE, self::OPT_ALERTS, self::OPT_FX );

	// Own tables, without the WordPress prefix.
	public const TABLE_OWNED    = 'hostelmate_owned';
	public const TABLE_BOOKINGS = 'hostelmate_bookings';
	public const TABLE_LOG      = 'hostelmate_log';

	/** @var list<string> */
	public const TABLES = array( self::TABLE_OWNED, self::TABLE_BOOKINGS, self::TABLE_LOG );

	// Cron events.
	public const CRON_SYNC     = 'hostelmate_hbook_sync';
	public const CRON_BOOKINGS = 'hostelmate_hbook_bookings';
	public const CRON_DAILY    = 'hostelmate_hbook_daily';

	/** @var list<string> */
	public const CRON_HOOKS = array( self::CRON_SYNC, self::CRON_BOOKINGS, self::CRON_DAILY );

	public const CRON_SCHEDULE     = 'hostelmate_hbook_5min';
	public const SYNC_EVERY        = 300;
	public const LATE_AFTER        = 1200;
	public const VERY_LATE_AFTER   = 3600;
	public const PREVIEW_FRESH_FOR = 1800;

	// Named locks (MySQL GET_LOCK), made unique per site by Db::lock_name().
	public const LOCK_SYNC     = 'sync';
	public const LOCK_BOOKINGS = 'bookings';

	// Log.
	public const LOG_KEEP_DAYS   = 30;
	public const LOG_KEEP_ROWS   = 10000;
	public const LOG_DETAIL_MAX  = 190;
	public const LOG_LEVEL_INFO  = 'info';
	public const LOG_LEVEL_WARN  = 'warning';
	public const LOG_LEVEL_ERROR = 'error';

	// HostelMate API. The base can only be changed from wp-config.php (our tests), never from the screens.
	public const API_BASE            = 'https://api.hostelmate.co/api/v1/client/';
	public const API_BASE_CONSTANT   = 'HOSTELMATE_HBOOK_API_BASE';
	public const API_CLIENT_DIR      = 'client/';              // The base ends with it; the rates list sits next to it.
	public const API_RATES_LIST_PATH = 'currency-rates/list'; // Public, read-only (backend api/req/currency_rates.py).
	public const API_KEY_CONSTANT    = 'HOSTELMATE_HBOOK_API_KEY';
	public const API_KEY_MIN_LEN     = 20;
	public const API_KEY_MAX_LEN     = 200;
	public const HTTP_TIMEOUT        = 15;
	public const HTTP_MAX_BYTES      = 8388608; // 8 MB.
	public const HTTP_JSON_DEPTH     = 64;

	/** Most nights one GET /rates call may cover (backend/api/client/utils.py:244-259). */
	public const RATES_MAX_NIGHTS = 366;

	// Modes the plugin can be in.
	public const MODE_SETUP   = 'setup';
	public const MODE_PREVIEW = 'preview';
	public const MODE_LIVE    = 'live';
	public const MODE_PAUSED  = 'paused';

	/** @var list<string> */
	public const MODES = array( self::MODE_SETUP, self::MODE_PREVIEW, self::MODE_LIVE, self::MODE_PAUSED );

	/** HostelMate's built-in payment methods (API/constants.py:28). Property accounts are also accepted. */
	public const HM_PAYMENT_METHODS = array( 'Cash', 'Bank Transfer', 'Credit Card', 'POS', 'OTA' );

	/** HBook gateways the settings map to a HostelMate payment method. */
	public const PAYMENT_GATEWAYS = array( 'stripe', 'paypal', 'other' );

	public const PAYMENT_METHOD_MAX_LEN = 100;

	// Alerts.
	public const ALERT_EMAIL_EVERY    = DAY_IN_SECONDS;
	public const ALERT_EMAILS_PER_DAY = 10;
	public const ALERT_EMAIL_MAX_REFS = 50; // Booking numbers or rooms listed per problem in one email.
	public const ALERT_LEVEL_RED      = 'red';
	public const ALERT_LEVEL_AMBER    = 'amber';
	public const ALERT_REF_MAX_LEN    = 64;
	public const ALERT_MAX_OPEN       = 200;

	// Admin screens and actions. Every action posts to admin-post.php?action=ADMIN_POST_ACTION.
	public const ADMIN_POST_ACTION = 'hostelmate_hbook';
	public const NONCE_PREFIX      = 'hostelmate_hbook_';
	public const PAGE_OVERVIEW     = 'hostelmate-hbook';
	public const PAGE_ROOMS        = 'hostelmate-hbook-rooms';
	public const PAGE_ACTIVITY     = 'hostelmate-hbook-activity';
	public const PAGE_SETTINGS     = 'hostelmate-hbook-settings';

	public const ACTION_CONNECT       = 'connect';
	public const ACTION_REMOVE_KEY    = 'remove_key';
	public const ACTION_REFRESH_ROOMS = 'refresh_rooms';
	public const ACTION_SAVE_SETTINGS = 'save_settings';
	public const ACTION_SAVE_MAPPING  = 'save_mapping';
	public const ACTION_CHECK_NOW     = 'check_now';
	public const ACTION_GO_LIVE       = 'go_live';
	public const ACTION_PAUSE         = 'pause';
	public const ACTION_RESUME        = 'resume';
	public const ACTION_APPLY_HELD    = 'apply_held';
	public const ACTION_DISCARD_HELD  = 'discard_held';
	public const ACTION_REMOVE_DATA   = 'remove_data';
	public const ACTION_CANCEL_COPIES = 'cancel_copies';
	public const ACTION_UNDO_COPIES   = 'undo_copies';
	public const ACTION_FX_AGREE      = 'fx_agree';
	public const ACTION_FX_STOP       = 'fx_stop';
	public const ACTION_FX_USE_HELD   = 'fx_use_held';
	public const ACTION_FX_KEEP_HELD  = 'fx_keep_held';

	/** Problems from the last Rooms save, kept briefly for the page that follows. */
	public const TRANSIENT_ISSUES = 'hostelmate_hbook_issues_';

	public const ACTIVITY_PAGE_SIZE = 100;

	// Marks on the rows the plugin writes into HBook (with the ids in TABLE_OWNED, two checks before any delete).
	public const BLOCK_UID_PREFIX = 'hostelmate-';
	public const BLOCK_COMMENT    = 'HostelMate';
	public const SEASON_NAME      = 'HostelMate auto';

	// Sync horizon and HBook limits.
	public const HORIZON_PAD_DAYS    = 7;
	public const HORIZON_CAP_DAYS    = 730;  // HostelMate's furthest night (backend/api/client/utils.py:244-259).
	public const MAX_ACCOMS_PER_RATE = 40;   // HBook reads rate links with GROUP_CONCAT (1,024 chars by default).

	// Big-change brake (plan 6.7).
	public const BRAKE_WINDOW_DAYS     = 60;
	public const BRAKE_SHARE_PCT       = 40;
	public const BRAKE_MIN_ROOM_NIGHTS = 50;
	public const BRAKE_PRICE_JUMP_PCT  = 50;
	public const MIN_SANE_PRICE_MINOR  = 100; // 1.00 in any currency: anything lower is held.
	public const STARTING_PRICE_DAYS   = 90;  // "Price starting at" = lowest price in this many nights (D9).

	// Booking loop (plan 7).
	public const BOOKING_LOOKBACK_DAYS = 30;       // Website bookings that ended up to 30 days ago are still kept in step.
	public const BOOKING_RUN_SECONDS   = 20;       // Time budget of one booking run.
	public const BOOKING_SOON_SECONDS  = 5;        // Budget at the end of a page request.
	public const BOOKING_CLAIM_SECONDS = 120;
	public const BOOKING_RETRY_AFTER   = array( 60, 300, 900, 3600 ); // Then every hour.
	public const BOOKING_ALERT_AFTER   = 1800;     // Failing for 30 minutes → red alert.
	public const HM_CHANNEL            = 'Website'; // Platform name the property sets up in HostelMate.
	public const HM_NOTES_MAX          = 10000;    // backend/api/compose/payload.py MAX_NOTES.
	public const HM_GUEST_NAME_MAX     = 200;      // Our own cap (HostelMate allows 500).
	public const HM_GUEST_PHONE_MAX    = 50;
	public const HM_PAYMENT_NAME_MAX   = 200;
	public const HM_MAX_NIGHTS         = 400;
	public const HM_NIGHT_MAX_MINOR    = 999999999;   // 9,999,999.99 a night (backend api/client/constants.py:90).
	public const HM_PAYMENT_MAX_MINOR  = 9999999999;  // 99,999,999.99 a payment (PAYMENT_MAX_AMOUNT).

	// Currency conversion, only when HostelMate and HBook use different currencies (todo/hbook-plugin-currency-plan.md).
	public const FX_IGNORE_PCT    = 1;      // A new rate that moved less than this is not used (prices don't shift daily).
	public const FX_HOLD_PCT      = 10;     // One that moved more than this waits for the owner's OK.
	public const FX_MAX_AGE       = 259200; // 72 h: HostelMate's last refresh older than this stops conversion.
	public const FX_REFRESH_AFTER = 86400;  // Fetch again once HostelMate's last refresh is a day old...
	public const FX_RETRY_AFTER   = 3600;   // ...and the last attempt is an hour old.
	public const HBOOK_MAX_MINOR  = 99999999999999; // The largest amount HBook stores (its decimal columns: 14 digits, 2 after the point).

	// Check before booking (plan 8).
	public const PRECHECK_TIMEOUT      = 3;
	public const PRECHECK_PER_IP       = 5;   // Per minute.
	public const PRECHECK_PER_SITE     = 30;  // Per minute.
	public const PRECHECK_MAX_ROOMS    = 10;  // Accommodation ids in one booking request.
	public const PRECHECK_LOGS_PER_MIN = 10;  // Log rows the public check may write per minute.
	public const HBOOK_CREATE_ACTION   = 'hb_create_resa'; // HBook's booking AJAX action (hbook.php:135-146).

	/**
	 * Absolute path of a file inside the plugin.
	 *
	 * @param string $relative Path relative to the plugin folder, e.g. "languages".
	 */
	public static function path( string $relative = '' ): string {
		return dirname( HOSTELMATE_HBOOK_FILE ) . ( '' === $relative ? '' : '/' . ltrim( $relative, '/' ) );
	}
}

<?php
/**
 * Every user-visible text, and every log code, declared once.
 * Codes are stable strings stored in the log; texts are translatable.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

namespace HostelMate\HBook;

defined( 'ABSPATH' ) || exit;

final class Messages {

	// Requirements.
	public const REQ_PHP       = 'req_php';
	public const REQ_WP        = 'req_wp';
	public const REQ_MULTISITE = 'req_multisite_network';

	// Plugin's own health.
	public const GUARD_CAUGHT = 'guard_caught';
	public const DB_UPGRADED  = 'db_upgraded';

	// Settings screen.
	public const SETTINGS_BAD_EMAIL  = 'settings_bad_email';
	public const SETTINGS_BAD_METHOD = 'settings_bad_method';

	// Alert emails.
	public const ALERT_EMAIL_FOOTER = 'alert_email_footer';
	public const ALERT_EMAIL_MANY   = 'alert_email_many';

	// Admin action results (shown once after the action).
	public const CONNECTED           = 'connected';
	public const CONNECT_BAD_KEY     = 'connect_bad_key';
	public const CONNECT_REFUSED     = 'connect_refused';
	public const CONNECT_PLAN        = 'connect_plan';
	public const CONNECT_UNREACHABLE = 'connect_unreachable';
	public const CONNECT_BAD_ANSWER  = 'connect_bad_answer';
	public const KEY_REMOVED         = 'key_removed';
	public const KEY_IN_CONFIG       = 'key_in_config';
	public const ROOMS_REFRESHED     = 'rooms_refreshed';
	public const SETTINGS_SAVED      = 'settings_saved';
	public const CONFIRM_MISSING     = 'confirm_missing';
	public const MAPPING_SAVED       = 'mapping_saved';
	public const MAPPING_NOT_SAVED   = 'mapping_not_saved';
	public const CONNECT_FIRST       = 'connect_first';

	// Sync runs (log codes; the *_ALERT ones are also alerts).
	public const SYNC_NO_KEY         = 'sync_no_key';
	public const SYNC_SCHEMA         = 'sync_schema';
	public const SYNC_NO_MAPPING     = 'sync_no_mapping';
	public const SYNC_CURRENCY       = 'sync_currency';
	public const SYNC_HBOOK_UNTESTED = 'sync_hbook_untested';
	public const SYNC_TIMEZONE       = 'sync_timezone';
	public const SYNC_PLAN           = 'sync_plan';
	public const SYNC_KEY_REFUSED    = 'sync_key_refused';
	public const SYNC_BAD_ANSWER     = 'sync_bad_answer';
	public const SYNC_UNREACHABLE    = 'sync_unreachable';
	public const SYNC_FOREIGN_HIGH   = 'sync_foreign_high';
	public const SYNC_MISMATCH       = 'sync_mismatch';
	public const SYNC_HELD           = 'sync_held';
	public const SYNC_WRITE_FAILED   = 'sync_write_failed';
	public const SYNC_NO_CHANGE      = 'sync_no_change';
	public const SYNC_APPLIED        = 'sync_applied';
	public const PREVIEW_READY       = 'preview_ready';
	public const PREVIEW_LARGE       = 'preview_large';
	public const SYNC_BUSY           = 'sync_busy';
	public const SYNC_LATE           = 'sync_late';
	public const SYNC_VERY_LATE      = 'sync_very_late';
	public const GO_LIVE_DONE        = 'go_live_done';
	public const GO_LIVE_BLOCKED     = 'go_live_blocked';
	public const PAUSED              = 'paused';
	public const RESUMED             = 'resumed';
	public const HELD_APPLIED        = 'held_applied';
	public const HELD_DISCARDED      = 'held_discarded';
	public const DATA_REMOVED        = 'data_removed';
	public const REMOVE_CONFIRM      = 'remove_confirm';

	// Booking loop.
	public const BOOKING_SENT      = 'booking_sent';
	public const BOOKING_UPDATED   = 'booking_updated';
	public const BOOKING_CANCELLED = 'booking_cancelled';
	public const BOOKING_REFUSED   = 'booking_refused';
	public const BOOKING_FAILED    = 'booking_failed';
	public const BOOKING_RETRY     = 'booking_retry';
	public const BOOKING_BED_KEPT  = 'booking_bed_kept';
	public const BOOKING_NO_EMAIL  = 'booking_no_email';
	public const BOOKING_UNMATCHED = 'booking_unmatched';
	public const BOOKING_TOO_LONG  = 'booking_too_long';
	public const BOOKING_NO_SOURCE = 'booking_no_source';

	// Check before booking.
	public const GUEST_JUST_BOOKED = 'guest_just_booked';
	public const GUEST_MIN_STAY    = 'guest_min_stay';
	public const GUEST_MAX_STAY    = 'guest_max_stay';
	public const PRECHECK_REFUSED  = 'precheck_refused';
	public const PRECHECK_SKIPPED  = 'precheck_skipped';

	// Owner tools.
	public const COPIES_CANCELLED    = 'copies_cancelled';
	public const COPIES_NOT_PROVEN   = 'copies_not_proven';
	public const COPIES_RESTORED     = 'copies_restored';
	public const KEY_WEAK_SALTS      = 'key_weak_salts';
	public const SYNC_WRONG_PROPERTY = 'sync_wrong_property';
	public const NOT_PAUSED          = 'not_paused';
	public const NOTHING_HELD        = 'nothing_held';

	// Room matching problems (errors stop a save; the last three are warnings).
	public const MAP_UNKNOWN_ROOM      = 'map_unknown_room';
	public const MAP_UNKNOWN_UNIT      = 'map_unknown_unit';
	public const MAP_UNIT_TWICE        = 'map_unit_twice';
	public const MAP_SPLIT_ACCOM       = 'map_split_accom';
	public const MAP_WHOLE_ROOM_NO_BED = 'map_whole_room_no_bed';
	public const MAP_BED_REQUIRED      = 'map_bed_required';
	public const MAP_UNKNOWN_BED       = 'map_unknown_bed';
	public const MAP_BED_TWICE         = 'map_bed_twice';
	public const MAP_NO_CHANNEL_ID     = 'map_no_channel_id';
	public const MAP_FAKE_NUMBERS      = 'map_fake_numbers';
	public const MAP_ROOM_OFF          = 'map_room_off';
	public const MAP_HBOOK_UNMATCHED   = 'map_hbook_unmatched';
	public const MAP_HM_UNMATCHED      = 'map_hm_unmatched';
	public const MAP_BROKEN            = 'map_broken';

	// Currency conversion (only while the two currencies differ).
	public const FX_AGREED           = 'fx_agreed';
	public const FX_SAME             = 'fx_same';
	public const FX_STOPPED          = 'fx_stopped';
	public const FX_UNREACHABLE      = 'fx_unreachable';
	public const FX_NO_RATE          = 'fx_no_rate';
	public const FX_TOO_OLD          = 'fx_too_old';
	public const FX_PAIR_CHANGED     = 'fx_pair_changed';
	public const FX_HELD             = 'fx_held';
	public const FX_RATE_USED        = 'fx_rate_used';
	public const FX_RATE_KEPT        = 'fx_rate_kept';
	public const FX_FETCH_FAILED     = 'fx_fetch_failed';
	public const FX_TOO_BIG          = 'fx_too_big';
	public const FX_BOOKING_TOO_BIG  = 'fx_booking_too_big';
	public const FX_BOOKING_CURRENCY = 'fx_booking_currency';

	/** @var list<string> Notice codes shown as success (the rest of NOTICE_CODES are errors). */
	public const SUCCESS_CODES = array( self::CONNECTED, self::KEY_REMOVED, self::ROOMS_REFRESHED, self::SETTINGS_SAVED, self::MAPPING_SAVED, self::PREVIEW_READY, self::SYNC_APPLIED, self::SYNC_NO_CHANGE, self::GO_LIVE_DONE, self::RESUMED, self::HELD_APPLIED, self::DATA_REMOVED, self::COPIES_CANCELLED, self::COPIES_RESTORED, self::FX_AGREED, self::FX_STOPPED, self::FX_RATE_USED, self::FX_RATE_KEPT );

	/** @var list<string> Codes that may be shown after an action (anything else in the URL is ignored). */
	public const NOTICE_CODES = array(
		self::CONNECTED,
		self::CONNECT_BAD_KEY,
		self::CONNECT_REFUSED,
		self::CONNECT_PLAN,
		self::CONNECT_UNREACHABLE,
		self::CONNECT_BAD_ANSWER,
		self::KEY_REMOVED,
		self::KEY_IN_CONFIG,
		self::ROOMS_REFRESHED,
		self::SETTINGS_SAVED,
		self::SETTINGS_BAD_EMAIL,
		self::SETTINGS_BAD_METHOD,
		self::CONFIRM_MISSING,
		self::MAPPING_SAVED,
		self::MAPPING_NOT_SAVED,
		self::CONNECT_FIRST,
		self::PREVIEW_READY,
		self::SYNC_APPLIED,
		self::SYNC_NO_CHANGE,
		self::SYNC_BUSY,
		self::SYNC_NO_KEY,
		self::SYNC_SCHEMA,
		self::SYNC_NO_MAPPING,
		self::SYNC_CURRENCY,
		self::SYNC_KEY_REFUSED,
		self::SYNC_PLAN,
		self::SYNC_BAD_ANSWER,
		self::SYNC_UNREACHABLE,
		self::SYNC_HELD,
		self::SYNC_WRITE_FAILED,
		self::GO_LIVE_DONE,
		self::GO_LIVE_BLOCKED,
		self::PAUSED,
		self::RESUMED,
		self::HELD_APPLIED,
		self::HELD_DISCARDED,
		self::DATA_REMOVED,
		self::REMOVE_CONFIRM,
		self::COPIES_CANCELLED,
		self::COPIES_NOT_PROVEN,
		self::COPIES_RESTORED,
		self::KEY_WEAK_SALTS,
		self::SYNC_WRONG_PROPERTY,
		self::NOT_PAUSED,
		self::NOTHING_HELD,
		self::FX_AGREED,
		self::FX_SAME,
		self::FX_STOPPED,
		self::FX_UNREACHABLE,
		self::FX_NO_RATE,
		self::FX_TOO_OLD,
		self::FX_RATE_USED,
		self::FX_RATE_KEPT,
	);

	/**
	 * The text for a code. Unknown codes return the code itself, so nothing is ever blank.
	 *
	 * @param string $code One of the constants above.
	 */
	public static function text( string $code ): string {
		switch ( $code ) {
			case self::REQ_PHP:
				/* translators: %s: PHP version, e.g. 8.2 */
				return sprintf( __( 'HB-HostelMate needs PHP %s or newer.', 'hostelmate-hbook' ), Config::MIN_PHP );
			case self::REQ_WP:
				/* translators: %s: WordPress version, e.g. 6.5 */
				return sprintf( __( 'HB-HostelMate needs WordPress %s or newer.', 'hostelmate-hbook' ), Config::MIN_WP );
			case self::REQ_MULTISITE:
				return __( 'HB-HostelMate connects one website to one property. Activate it on each site, not network-wide.', 'hostelmate-hbook' );
			case self::GUARD_CAUGHT:
				return __( 'An error inside HB-HostelMate was caught and logged. The website kept working.', 'hostelmate-hbook' );
			case self::DB_UPGRADED:
				return __( 'HB-HostelMate updated its tables.', 'hostelmate-hbook' );
			case self::SETTINGS_BAD_EMAIL:
				return __( 'That alert email address is not valid. The old one was kept.', 'hostelmate-hbook' );
			case self::SETTINGS_BAD_METHOD:
				return __( 'A payment method must be 1 to 100 characters of plain text. The old one was kept.', 'hostelmate-hbook' );
			case self::ALERT_EMAIL_MANY:
				/* translators: %d: number of problems */
				return __( '%d problems need your attention', 'hostelmate-hbook' );
			case self::ALERT_EMAIL_FOOTER:
				return __( 'Open HB-HostelMate in your WordPress admin to see what to do:', 'hostelmate-hbook' );
			case self::CONNECTED:
				return __( 'Connected to HostelMate.', 'hostelmate-hbook' );
			case self::CONNECT_BAD_KEY:
				return __( 'That doesn\'t look like a HostelMate key. Copy it again from HostelMate and paste it here.', 'hostelmate-hbook' );
			case self::CONNECT_REFUSED:
				return __( 'HostelMate didn\'t accept this key. Check you copied the whole key, or make a new one in HostelMate.', 'hostelmate-hbook' );
			case self::CONNECT_PLAN:
				return __( 'HostelMate refused the connection. Your plan may not include the API: check your plan in HostelMate.', 'hostelmate-hbook' );
			case self::CONNECT_UNREACHABLE:
				return __( 'Couldn\'t reach HostelMate. Your host may block outgoing connections; try again in a minute.', 'hostelmate-hbook' );
			case self::CONNECT_BAD_ANSWER:
				return __( 'HostelMate answered in a way this plugin doesn\'t understand. Nothing was saved. Please contact HostelMate support.', 'hostelmate-hbook' );
			case self::KEY_REMOVED:
				return __( 'The key was removed. Nothing will be sent to or read from HostelMate.', 'hostelmate-hbook' );
			case self::KEY_IN_CONFIG:
				return __( 'The key is set in wp-config.php, so it can only be changed there.', 'hostelmate-hbook' );
			case self::ROOMS_REFRESHED:
				return __( 'Rooms and beds were read again from HostelMate.', 'hostelmate-hbook' );
			case self::SETTINGS_SAVED:
				return __( 'Settings saved.', 'hostelmate-hbook' );
			case self::CONFIRM_MISSING:
				return __( 'Tick the box to confirm, then try again.', 'hostelmate-hbook' );
			case self::MAPPING_SAVED:
				return __( 'Rooms saved.', 'hostelmate-hbook' );
			case self::MAPPING_NOT_SAVED:
				return __( 'Rooms not saved: fix the problems listed below and save again.', 'hostelmate-hbook' );
			case self::CONNECT_FIRST:
				return __( 'Connect to HostelMate first, so the plugin knows your rooms.', 'hostelmate-hbook' );
			case self::MAP_UNKNOWN_ROOM:
				return __( 'This HostelMate room no longer exists. Read the rooms from HostelMate again.', 'hostelmate-hbook' );
			case self::MAP_UNKNOWN_UNIT:
				return __( 'This website room or bed no longer exists in HBook. Save the rooms again to drop it.', 'hostelmate-hbook' );
			case self::MAP_UNIT_TWICE:
				return __( 'This website room or bed is matched twice.', 'hostelmate-hbook' );
			case self::MAP_SPLIT_ACCOM:
				return __( 'All beds of one website room must be matched to the same HostelMate room, because HBook has one price per room.', 'hostelmate-hbook' );
			case self::MAP_WHOLE_ROOM_NO_BED:
				return __( 'This HostelMate room is sold whole: pick it as a whole room, not a bed.', 'hostelmate-hbook' );
			case self::MAP_BED_REQUIRED:
				return __( 'Pick the bed in HostelMate for this website bed.', 'hostelmate-hbook' );
			case self::MAP_UNKNOWN_BED:
				return __( 'This bed is not in the chosen HostelMate room.', 'hostelmate-hbook' );
			case self::MAP_BED_TWICE:
				return __( 'This HostelMate bed is matched to two website rooms or beds. Each HostelMate bed can be matched once.', 'hostelmate-hbook' );
			case self::MAP_NO_CHANNEL_ID:
				return __( 'HostelMate would refuse website bookings for this room: it isn\'t set up for bookings from outside HostelMate yet. Ask HostelMate support to set it up.', 'hostelmate-hbook' );
			case self::MAP_FAKE_NUMBERS:
				return __( 'This HostelMate room shows more (or fewer) beds than it really has (fake capacity), so the website could sell beds HostelMate then refuses. Turn fake capacity off for this room in HostelMate.', 'hostelmate-hbook' );
			case self::SYNC_NO_KEY:
				return __( 'No HostelMate key: nothing is kept in step.', 'hostelmate-hbook' );
			case self::SYNC_SCHEMA:
				return __( 'HBook\'s tables are not what this plugin expects (HBook may have been updated). Nothing was changed on your website.', 'hostelmate-hbook' );
			case self::SYNC_NO_MAPPING:
				return __( 'No rooms are matched yet.', 'hostelmate-hbook' );
			case self::SYNC_CURRENCY:
				return __( 'HostelMate and HBook use different currencies. Nothing is changed on your website, and website bookings wait, until they match.', 'hostelmate-hbook' );
			case self::FX_AGREED:
				return __( 'Currencies are converted now, at HostelMate\'s daily rate.', 'hostelmate-hbook' );
			case self::FX_SAME:
				return __( 'HostelMate and your website use the same currency, so nothing needs converting.', 'hostelmate-hbook' );
			case self::FX_STOPPED:
				return __( 'Currencies are no longer converted. While they differ, nothing is changed on your website and website bookings wait.', 'hostelmate-hbook' );
			case self::FX_UNREACHABLE:
				return __( 'Couldn\'t get today\'s exchange rate from HostelMate. Nothing was changed. Try again in a few minutes.', 'hostelmate-hbook' );
			case self::FX_NO_RATE:
				return __( 'HostelMate has no exchange rate for one of the two currencies, so they can\'t be converted. Use the same currency in HostelMate and HBook.', 'hostelmate-hbook' );
			case self::FX_TOO_OLD:
				return __( 'HostelMate\'s exchange rate is more than 3 days old, so nothing is changed on your website and website bookings wait until it is updated. Please contact HostelMate support.', 'hostelmate-hbook' );
			case self::FX_PAIR_CHANGED:
				return __( 'The currency changed in HostelMate or in HBook since you agreed to convert. Nothing is changed on your website and website bookings wait. Agree to the new currencies, or stop converting.', 'hostelmate-hbook' );
			case self::FX_HELD:
				return __( 'The exchange rate moved by more than 10%. Your website keeps the old rate until you choose.', 'hostelmate-hbook' );
			case self::FX_RATE_USED:
				return __( 'A new exchange rate is used for prices and new bookings.', 'hostelmate-hbook' );
			case self::FX_RATE_KEPT:
				return __( 'The old exchange rate is kept.', 'hostelmate-hbook' );
			case self::FX_TOO_BIG:
				return __( 'A converted price is larger than HBook can store, so nothing was changed on your website. Check the prices in HostelMate.', 'hostelmate-hbook' );
			case self::FX_BOOKING_TOO_BIG:
				return __( 'Converted into HostelMate\'s currency, this website booking\'s amounts are larger than HostelMate accepts, so it wasn\'t sent. Enter it in HostelMate by hand.', 'hostelmate-hbook' );
			case self::FX_BOOKING_CURRENCY:
				return __( 'This website booking was sent to HostelMate in a currency HostelMate no longer uses, so the plugin no longer changes it. Update it in HostelMate by hand.', 'hostelmate-hbook' );
			case self::FX_FETCH_FAILED:
				return __( 'Couldn\'t get the exchange rate from HostelMate. The stored rate is used meanwhile.', 'hostelmate-hbook' );
			case self::SYNC_HBOOK_UNTESTED:
				return __( 'HBook was updated to a version this plugin hasn\'t been tested on yet. Its tables still look the same, so everything keeps running. Tell HostelMate support if anything looks wrong.', 'hostelmate-hbook' );
			case self::SYNC_TIMEZONE:
				return __( 'WordPress and HostelMate use different time zones. When HostelMate\'s date is already the next day, your website can\'t sell the night that is still "tonight" for it. Use the same zone in both: WordPress\'s is in Settings → General; to set or change HostelMate\'s, ask HostelMate support.', 'hostelmate-hbook' );
			case self::SYNC_PLAN:
				return __( 'Your HostelMate plan doesn\'t include the API (it is on Lite), so nothing is kept in step. Change the plan in HostelMate.', 'hostelmate-hbook' );
			case self::SYNC_KEY_REFUSED:
				return __( 'HostelMate no longer accepts the key. Nothing is kept in step until a working key is added.', 'hostelmate-hbook' );
			case self::SYNC_BAD_ANSWER:
				return __( 'HostelMate sent prices this plugin doesn\'t understand. Nothing was changed on your website. Please contact HostelMate support.', 'hostelmate-hbook' );
			case self::SYNC_UNREACHABLE:
				return __( 'Couldn\'t reach HostelMate. Your website keeps the last prices and dates until it can.', 'hostelmate-hbook' );
			case self::SYNC_FOREIGN_HIGH:
				return __( 'Another plugin sets its own prices in HBook (for example automatic last-minute prices), so HBook could show either price. Switch that off.', 'hostelmate-hbook' );
			case self::SYNC_MISMATCH:
				return __( 'HBook holds bookings or blocks HostelMate doesn\'t know about, so the same bed could be sold twice on other channels.', 'hostelmate-hbook' );
			case self::SYNC_HELD:
				return __( 'A very large change from HostelMate is waiting. Check it, then apply it or not.', 'hostelmate-hbook' );
			case self::SYNC_WRITE_FAILED:
				return __( 'Saving prices and dates in HBook failed, so nothing was changed. It will be tried again.', 'hostelmate-hbook' );
			case self::SYNC_NO_CHANGE:
				return __( 'Checked HostelMate: nothing changed.', 'hostelmate-hbook' );
			case self::SYNC_APPLIED:
				return __( 'Prices and dates updated from HostelMate.', 'hostelmate-hbook' );
			case self::PREVIEW_READY:
				return __( 'Check done: see what going live would change.', 'hostelmate-hbook' );
			case self::PREVIEW_LARGE:
				return __( 'This is a large change from what your website shows today. Going live applies it, because you have checked it here. After that, a change this large waits for your OK.', 'hostelmate-hbook' );
			case self::SYNC_LATE:
				return __( 'HostelMate hasn\'t been checked for over 20 minutes. WordPress runs its timers only when someone visits: add a server cron job (see Settings).', 'hostelmate-hbook' );
			case self::SYNC_VERY_LATE:
				return __( 'HostelMate hasn\'t been checked for over an hour, so prices and dates on your website may be out of date. Add a server cron job (see Settings).', 'hostelmate-hbook' );
			case self::GO_LIVE_DONE:
				return __( 'Live: your website now follows HostelMate.', 'hostelmate-hbook' );
			case self::GO_LIVE_BLOCKED:
				return __( 'Not live yet. Match at least one room, and if the checklist shows warnings, tick the box to go live anyway.', 'hostelmate-hbook' );
			case self::PAUSED:
				return __( 'Paused: nothing is written to your website or sent to HostelMate. Website bookings will NOT reach HostelMate while paused.', 'hostelmate-hbook' );
			case self::RESUMED:
				return __( 'Running again.', 'hostelmate-hbook' );
			case self::HELD_APPLIED:
				return __( 'The held change will be applied on this check.', 'hostelmate-hbook' );
			case self::HELD_DISCARDED:
				return __( 'The held change was not applied. If HostelMate still says the same on the next check, it will be held again.', 'hostelmate-hbook' );
			case self::DATA_REMOVED:
				return __( 'All HostelMate prices and blocks were removed from your website. It now uses HBook\'s own seasons again.', 'hostelmate-hbook' );
			case self::REMOVE_CONFIRM:
				return __( 'Type REMOVE to confirm.', 'hostelmate-hbook' );
			case self::BOOKING_SENT:
				return __( 'Website booking sent to HostelMate.', 'hostelmate-hbook' );
			case self::BOOKING_UPDATED:
				return __( 'Website booking updated in HostelMate.', 'hostelmate-hbook' );
			case self::BOOKING_CANCELLED:
				return __( 'Website booking cancelled in HostelMate.', 'hostelmate-hbook' );
			case self::BOOKING_REFUSED:
				return __( 'HostelMate refused a website booking: the bed was most likely sold on another channel a moment earlier. Decline the request, or move the guest.', 'hostelmate-hbook' );
			case self::BOOKING_FAILED:
				return __( 'A website booking could not be sent to HostelMate. Check it in HBook; it is tried again when it changes.', 'hostelmate-hbook' );
			case self::BOOKING_RETRY:
				return __( 'HostelMate couldn\'t be reached for a website booking: it will be tried again.', 'hostelmate-hbook' );
			case self::BOOKING_BED_KEPT:
				return __( 'The matched bed was taken in HostelMate, so HostelMate kept its own bed in the same room.', 'hostelmate-hbook' );
			case self::BOOKING_NO_EMAIL:
				return __( 'A website booking has no valid guest email, which HostelMate needs. Add it in HBook.', 'hostelmate-hbook' );
			case self::BOOKING_NO_SOURCE:
				return __( 'Your HostelMate property has no source called "Website", so website bookings are saved there as "ClientAPI". Add a source called Website in HostelMate to see them as website bookings.', 'hostelmate-hbook' );
			case self::BOOKING_UNMATCHED:
				return __( 'Website bookings on rooms that aren\'t matched stay in HBook only: HostelMate doesn\'t get them. That\'s fine for rooms HostelMate doesn\'t sell; otherwise match the room.', 'hostelmate-hbook' );
			case self::BOOKING_TOO_LONG:
				return __( 'A website booking has no nights or more nights than HostelMate accepts. Check its dates in HBook.', 'hostelmate-hbook' );
			case self::GUEST_JUST_BOOKED:
				return __( 'Sorry, this was just booked. Please pick other dates or another room or bed.', 'hostelmate-hbook' );
			case self::GUEST_MIN_STAY:
				/* translators: %d: number of nights */
				return __( 'For these dates this room needs a stay of at least %d nights. Please pick other dates.', 'hostelmate-hbook' );
			case self::GUEST_MAX_STAY:
				/* translators: %d: number of nights */
				return __( 'For these dates this room can be booked for at most %d nights. Please pick other dates.', 'hostelmate-hbook' );
			case self::PRECHECK_REFUSED:
				return __( 'A website booking was stopped before saving: HostelMate no longer had that room free.', 'hostelmate-hbook' );
			case self::PRECHECK_SKIPPED:
				return __( 'The check before booking was skipped (HostelMate didn\'t answer in time, or too many checks this minute). The booking went ahead.', 'hostelmate-hbook' );
			case self::COPIES_CANCELLED:
				return __( 'The iCal copies were cancelled in HBook. HostelMate\'s blocks now stand for them. Undo is available below.', 'hostelmate-hbook' );
			case self::COPIES_NOT_PROVEN:
				return __( 'Not yet: the last check must be from the last 30 minutes and show no mismatches, which proves HostelMate has every one of these bookings.', 'hostelmate-hbook' );
			case self::COPIES_RESTORED:
				return __( 'The iCal copies are back as they were.', 'hostelmate-hbook' );
			case self::KEY_WEAK_SALTS:
				return __( 'This site\'s wp-config.php has no strong secret keys (salts), so a stored key could be read from a database copy. Put the key in wp-config.php as HOSTELMATE_HBOOK_API_KEY, or add salts to wp-config.php first.', 'hostelmate-hbook' );
			case self::SYNC_WRONG_PROPERTY:
				return __( 'The key now belongs to a different HostelMate property than the one the rooms were matched to. Nothing was changed. Read the rooms again and match them.', 'hostelmate-hbook' );
			case self::NOT_PAUSED:
				return __( 'Only a paused site can be resumed. Use the setup steps to go live.', 'hostelmate-hbook' );
			case self::NOTHING_HELD:
				return __( 'There is no held change.', 'hostelmate-hbook' );
			case self::SYNC_BUSY:
				return __( 'A check is already running. Try again in a minute.', 'hostelmate-hbook' );
			case self::MAP_HBOOK_UNMATCHED:
				return __( 'Some HBook rooms or beds aren\'t matched to HostelMate. The website sells them on its own, and HostelMate doesn\'t hear about their bookings. Match them on the Rooms page, unless they aren\'t in HostelMate at all.', 'hostelmate-hbook' );
			case self::MAP_BROKEN:
				return __( 'Some rooms are matched to a room or bed that no longer exists (it was removed in HostelMate or in HBook). Those rooms aren\'t kept in step until you fix them on the Rooms page.', 'hostelmate-hbook' );
			case self::MAP_HM_UNMATCHED:
				return __( 'Some HostelMate rooms aren\'t matched to a website room, so the website doesn\'t sell them. Match them on the Rooms page if the website should.', 'hostelmate-hbook' );
			case self::MAP_ROOM_OFF:
				return __( 'This HostelMate room is switched off, so the website can\'t sell it. Switch it on in HostelMate, or leave it unmatched.', 'hostelmate-hbook' );
		}
		return $code;
	}
}

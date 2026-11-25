=== HB-HostelMate ===
Tags: hostel, booking, availability, prices, reservations
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps HBook's prices and availability in step with HostelMate, and sends website bookings to HostelMate.

== Description ==
Connect your HBook website to your HostelMate property:

* Prices and free beds from HostelMate are written into HBook every 5 minutes.
* Bookings made on your website go to HostelMate, with their payments.
* Before a guest's booking is saved, HostelMate is asked whether the room is still free.
* If HostelMate and your website use different currencies, you can have the plugin convert between them at HostelMate's daily exchange rate.

Nothing on your website changes until you go live at the end of the setup, after a preview.
The plugin makes outgoing connections to api.hostelmate.co only, and adds no public pages.

== Requirements ==
* PHP 8.2 or newer, WordPress 6.5 or newer, and HBook.
* At least 128 MB of PHP memory for WordPress's scheduled tasks (WordPress's own recommendation).
* A HostelMate plan that includes the client API (every plan except Lite).
* In HostelMate: an API key (User Management → API Access), and a booking platform named "Website" (Settings → Booking Platforms). Without it, website bookings are filed under ClientAPI.
* The same time zone in WordPress (Settings → General) and in HostelMate. If your HostelMate property has none set, HostelMate counts it as UTC; HostelMate support can set it.

== External services ==
This plugin connects to the HostelMate API (api.hostelmate.co), the system your property uses to manage its bookings. Everything the plugin does depends on it.

Nothing is sent until you enter your HostelMate API key in the plugin's settings. After that:

* Every 5 minutes, the plugin asks for your property's rooms, prices and free beds for the coming nights. No guest data is sent.
* When a guest books on your website, the plugin sends the stay's dates before the booking is saved, to check the room is still free. No guest data is sent.
* When a booking is made, changed or cancelled on your website, the plugin sends its dates, room, number of guests, nightly prices, status, payments and HBook booking number, with the guest's name, email address, phone number and country.
* Only if HostelMate and your website use different currencies and you choose to convert them: about once a day, the plugin reads HostelMate's public list of exchange rates (api.hostelmate.co/api/v1/currency-rates/list). Only the plugin's version number is sent with it: nothing about your site or your guests, and not your API key.

Every other request carries your API key and the plugin's version number.

This service is provided by HostelMate: [customer agreement](https://hostelmate.co/customer-agreement), [privacy policy](https://hostelmate.co/privacy-policy).

== Changelog ==
= 1.1.1 =
* Matching rooms: a HostelMate bed already picked for another website room or bed is greyed out, with the name of the row that has it. Beds of one website room are only offered in the HostelMate room its other beds use. Several website rooms can still share a HostelMate whole room.
* If a save is refused, you stay on the matching step with your choices on screen, and a bed picked twice names every row that has it. Before, a refused save during setup could jump to step 3, without the table.

= 1.1.0 =
* Guided setup in four steps: connect, match rooms, check, go live. Until you go live, the plugin has one menu item.
* Going live needs at least one matched room. Every other checklist item is a warning you can accept with a tick.
* Plain wording on every screen. The Overview lists each kind of problem once, and Settings is grouped by topic.
* Currency conversion: if HostelMate and your website use different currencies, you can agree to convert at HostelMate's daily exchange rate. Each website booking keeps the rate it was first sent with. Without your agreement, nothing is changed and website bookings wait.
* Time zones: a HostelMate property with no time zone counts as UTC, as in HostelMate. When HostelMate's date is ahead of your website's, prices and availability keep syncing (before, every sync stopped until the dates matched again).
* A currency problem is now reported once, not again on every sync.

= 1.0.0 =
* First public release.

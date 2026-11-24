=== HostelMate for HBook ===
Tags: hostel, booking, availability, prices, reservations
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keeps HBook's prices and availability in step with HostelMate, and sends website bookings to HostelMate.

== Description ==
Connect your HBook website to your HostelMate property:

* Prices and free beds from HostelMate are written into HBook every 5 minutes.
* Bookings made on your website go to HostelMate, with their payments.
* Before a guest's booking is saved, HostelMate is asked whether the room is still free.

Nothing on your website changes until you go live at the end of the setup, after a preview.
The plugin makes outgoing connections to api.hostelmate.co only, and adds no public pages.

== Requirements ==
* PHP 8.2 or newer, WordPress 6.5 or newer, and HBook.
* At least 128 MB of PHP memory for WordPress's scheduled tasks (WordPress's own recommendation).
* A HostelMate plan that includes the client API.

== External services ==
This plugin connects to the HostelMate API (api.hostelmate.co), the system your property uses to manage its bookings. Everything the plugin does depends on it.

Nothing is sent until you enter your HostelMate API key in the plugin's settings. After that:

* Every 5 minutes, the plugin asks for your property's rooms, prices and free beds for the coming nights. No guest data is sent.
* When a guest books on your website, the plugin sends the stay's dates before the booking is saved, to check the room is still free. No guest data is sent.
* When a booking is made, changed or cancelled on your website, the plugin sends its dates, room, number of guests, nightly prices, status, payments and HBook booking number, with the guest's name, email address, phone number and country.

Every request carries your API key and the plugin's version number.

This service is provided by HostelMate: [customer agreement](https://hostelmate.co/customer-agreement), [privacy policy](https://hostelmate.co/privacy-policy).

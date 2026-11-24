<?php
/**
 * Plugin Name:       HostelMate for HBook
 * Description:       Keeps HBook's prices and availability in step with HostelMate, and sends website bookings to HostelMate.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            HostelMate
 * Author URI:        https://hostelmate.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hostelmate-hbook
 * Domain Path:       /languages
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'HOSTELMATE_HBOOK_FILE', __FILE__ );

require_once __DIR__ . '/src/autoload.php';

\HostelMate\HBook\Plugin::boot();

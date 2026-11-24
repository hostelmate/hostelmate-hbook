<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * @package HostelMate\HBook
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// The main file may already be loaded in this request (it defines the same constant).
defined( 'HOSTELMATE_HBOOK_FILE' ) || define( 'HOSTELMATE_HBOOK_FILE', __DIR__ . '/hostelmate-hbook.php' );

require_once __DIR__ . '/src/autoload.php';

\HostelMate\HBook\Core\Uninstaller::run();

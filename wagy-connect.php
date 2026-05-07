<?php

/**
 * Plugin Name:       Wagy Connect
 * Description:       A comprehensive WordPress security and messaging suite: WhatsApp 2FA, security notifications, custom login URL, message logs, bulk broadcast, and Fluent Forms integration — powered by the self-hosted WAGY API.
 * Version:           0.0.1
 * Author:            Akah
 * Author URI:        https://www.subarkah.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wagy-connect
 * Domain Path:       /languages
 */


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Autoloader */
require_once __DIR__ . '/includes/Autoloader.php';
\Wagy\Autoloader::init('Wagy\\', __DIR__ . '/includes/');

/** Load functions */
require_once __DIR__ . '/functions.php';
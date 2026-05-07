<?php

namespace Wagy\Security;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles automatic updates from GitHub using plugin-update-checker library.
 */
class GitHubUpdater {

    /**
     * Initialize the updater.
     */
    public static function init() {
        if ( ! is_admin() ) {
            return;
        }

        $puc_path = dirname( dirname( __FILE__ ) ) . '/Libraries/plugin-update-checker/plugin-update-checker.php';

        if ( file_exists( $puc_path ) ) {
            require_once $puc_path;

            $update_checker = PucFactory::buildUpdateChecker(
                'https://github.com/akah-martono/wagy-connect',
                dirname( dirname( dirname( __FILE__ ) ) ) . '/wagy-connect.php',
                'wagy-connect'
            );

            // Set the branch that contains the stable release.
            // By default, it looks for the latest GitHub Release.
            // $update_checker->setBranch('main');
        }
    }
}

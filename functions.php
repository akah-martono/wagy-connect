<?php

use Wagy\Admin\SettingsPage;
use Wagy\Admin\StatusPage;
use Wagy\Admin\MessagesLogPage;
use Wagy\Admin\BroadcastPage;
use Wagy\Admin\IntegrationsPage;
use Wagy\Integrations\FluentForms;
use Wagy\Integrations\WooCommerce;
use Wagy\Security\CustomLoginUrl;
use Wagy\Security\TwoFactorAuth;
use Wagy\Security\SecurityNotifications;
use Wagy\Security\SystemUpdateNotifier;
use Wagy\Security\GitHubUpdater;
use Wagy\Wagy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}


/** Admin Pages & Access Control */
\Wagy\Admin\AccessControl::init();
GitHubUpdater::init();

new StatusPage();
if(Wagy::is_configured()) {
    new MessagesLogPage();
    new BroadcastPage();
}
new IntegrationsPage();
new SettingsPage();

/** Security Features */
if ( get_option( 'wagy_custom_login_slug' ) ) new CustomLoginUrl();
if ( get_option( 'wagy_2fa_enabled' ) ) new TwoFactorAuth();
if ( get_option( 'wagy_admin_wa_number' ) || get_option( 'wagy_admin_email' ) ) {
    new SecurityNotifications();
    new SystemUpdateNotifier();
}

/** Invalidate status cache on core settings change */
foreach ( [ 'wagy_base_url', 'wagy_device_id', 'wagy_token' ] as $wagy_connect_option ) {
    add_action( "update_option_{$wagy_connect_option}", function() {
        \Wagy\Wagy::invalidate_status_cache();
    } );
}
unset( $wagy_connect_option );

/** WooCommerce Integration */
add_action( 'plugins_loaded', function() {
    new WooCommerce();
} );

/** FluentForms Integration */
add_action('fluentform/loaded', function (\FluentForm\Framework\Foundation\Application $app) {
    new FluentForms($app);
}, 10, 1);

/** Admin Notices */
add_action( 'admin_notices', function() {
    $capability = get_option( 'wagy_capability', 'manage_options' );

    // Skip admin notices for users who don't have the capability to access Wagy pages.
    if ( ! current_user_can( $capability ) ) {
        return;
    }

    $screen = get_current_screen();
    
    // Show unconfigured warning to admins on all pages except settings. 
    if ( current_user_can( 'manage_options' ) && strpos( $screen->id, 'wagy-settings' ) === false && !\Wagy\Wagy::is_configured() ) {
        echo '<div class="notice notice-warning inline"><p>'
            . esc_html__( 'Wagy has not been configured yet. Please go to Settings to set up your API credentials.', 'wagy-connect' )
            . ' <a href="' . esc_url( admin_url( 'admin.php?page=wagy-settings' ) ) . '">'
            . esc_html__( 'Go to Settings →', 'wagy-connect' )
            . '</a></p></div>';
        return; 
    }

    // Only show notices on Wagy plugin pages.
    if ( strpos( $screen->id, 'wagy' ) === false ) {
        return; 
    }

    $status = \Wagy\Wagy::get_connection_status_cached();
        
    if ( $status['status'] !== 'success' ) {
        echo '<div class="notice notice-error"><p>'
            . esc_html__( 'Cannot connect to Wagy API server. Please check your connection or credentials.', 'wagy-connect' )
            . ( ! empty( $status['message'] ) ? ' &mdash; ' . esc_html( $status['message'] ) : '' )
            . '</p></div>';
        return;
    }

    // Show reconnect prompt on other Wagy pages if logged out.
    if ( empty( $status['data']['logged_in'] ) ) {
        echo '<div class="notice notice-warning"><p>'
            . '<strong>' . esc_html__( 'Wagy WhatsApp Device Disconnected.', 'wagy-connect' ) . '</strong> '
            . esc_html__( 'Your WhatsApp session has ended.', 'wagy-connect' )
            . ' <a href="' . esc_url( admin_url( 'admin.php?page=wagy-status' ) ) . '" class="button button-small" style="margin-left: 10px;">' . esc_html__( 'Reconnect / View QR', 'wagy-connect' ) . '</a>'
            . '</p></div>';
    }
} );

// Redirect to settings page if Wagy is not configured.
add_action( 'admin_init', function() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing, no data mutation.
    $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
    if ( ! Wagy::is_configured() && 'wagy-status' === $page ) {
        wp_safe_redirect( admin_url( 'admin.php?page=wagy-settings' ) );
        exit;
    }
} );

/** Developer Action Hook */
add_action( 'wagy_send_message', function( $recipient, $message, $media_url = '', $args = [] ) {
    $payload = array_merge( $args, [
        'phone'   => $recipient,
        'message' => $message,
    ] );
    if ( ! empty( $media_url ) ) {
        $payload['media_url'] = $media_url;
    }
    
    // Allow modification via filter before sending.
    $payload = apply_filters( 'wagy_message_payload', $payload );
    \Wagy\Wagy::send_message( $payload );
}, 10, 4 );
<?php
namespace Wagy\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;

class SystemUpdateNotifier {

    public function __construct() {
        add_action( 'wagy_check_updates_event', [ $this, 'handle_available_updates_cron' ] );
        add_action( 'upgrader_process_complete', [ $this, 'handle_completed_updates' ], 10, 2 );
        add_action( 'update_option_wagy_notify_updates_available', [ $this, 'reschedule_cron' ], 10, 3 );
        
        // Ensure cron is scheduled if option is set but cron is missing
        $this->ensure_cron_scheduled();
    }

    private function ensure_cron_scheduled() {
        $interval = get_option( 'wagy_notify_updates_available', 'disabled' );
        if ( $interval !== 'disabled' && ! wp_next_scheduled( 'wagy_check_updates_event' ) ) {
            wp_schedule_event( time(), $interval, 'wagy_check_updates_event' );
        }
    }

    public function reschedule_cron( $old_value, $new_value, $option_name ) {
        wp_clear_scheduled_hook( 'wagy_check_updates_event' );
        if ( $new_value !== 'disabled' ) {
            wp_schedule_event( time(), $new_value, 'wagy_check_updates_event' );
        }
    }

    public function handle_available_updates_cron() {
        $interval = get_option( 'wagy_notify_updates_available', 'disabled' );
        if ( $interval === 'disabled' ) {
            return;
        }

        // Force WordPress to check for updates
        wp_update_plugins();
        wp_update_themes();
        wp_version_check();

        $plugin_updates = get_site_transient( 'update_plugins' );
        $theme_updates  = get_site_transient( 'update_themes' );
        $core_updates   = get_site_transient( 'update_core' );

        $updates_list = [];
        $total_updates = 0;

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( ! empty( $plugin_updates->response ) ) {
            foreach ( $plugin_updates->response as $file => $plugin ) {
                // Get plugin name if available, else use slug
                $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
                $name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : $plugin->slug;
                $updates_list[] = "- 🔌 Plugin: {$name} (v{$plugin->new_version})";
                $total_updates++;
            }
        }

        if ( ! empty( $theme_updates->response ) ) {
            foreach ( $theme_updates->response as $stylesheet => $theme ) {
                $theme_obj = wp_get_theme( $stylesheet );
                $name = $theme_obj->exists() ? $theme_obj->get( 'Name' ) : $stylesheet;
                $new_version = is_array($theme) && isset($theme['new_version']) ? $theme['new_version'] : 'baru';
                $updates_list[] = "- 🎨 Theme: {$name} (v{$new_version})";
                $total_updates++;
            }
        }

        if ( ! empty( $core_updates->updates ) ) {
            foreach ( $core_updates->updates as $core ) {
                if ( $core->response === 'upgrade' ) {
                    $updates_list[] = "- ⚙️ Core: WordPress (v{$core->version})";
                    $total_updates++;
                }
            }
        }

        if ( $total_updates > 0 ) {
            $template = get_option( 'wagy_notify_updates_available_template', '' );
            if ( empty( $template ) ) {
                return;
            }

            $message = str_replace(
                [ '{site_name}', '{site_url}', '{total_updates}', '{update_list}' ],
                [ get_bloginfo( 'name' ), site_url(), $total_updates, implode( "\n", $updates_list ) ],
                $template
            );

            $this->send_notification( $message );
        }
    }

    public function handle_completed_updates( $upgrader_object, $options ) {
        if ( ! get_option( 'wagy_notify_updates_completed' ) ) {
            return;
        }

        if ( ! isset( $options['action'] ) || $options['action'] !== 'update' ) {
            return;
        }

        $updates_list = [];

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if ( $options['type'] === 'plugin' && ! empty( $options['plugins'] ) ) {
            foreach ( $options['plugins'] as $plugin_file ) {
                $plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
                $name = ! empty( $plugin_data['Name'] ) ? $plugin_data['Name'] : dirname($plugin_file);
                $updates_list[] = "- 🔌 Plugin: {$name}";
            }
        } elseif ( $options['type'] === 'theme' && ! empty( $options['themes'] ) ) {
            foreach ( $options['themes'] as $theme_slug ) {
                $theme_obj = wp_get_theme( $theme_slug );
                $name = $theme_obj->exists() ? $theme_obj->get( 'Name' ) : $theme_slug;
                $updates_list[] = "- 🎨 Theme: {$name}";
            }
        } elseif ( $options['type'] === 'core' || $options['type'] === 'translation' ) {
            $updates_list[] = "- ⚙️ Core/Translation Updated";
        }

        if ( empty( $updates_list ) ) {
            return;
        }

        $template = get_option( 'wagy_notify_updates_completed_template', '' );
        if ( empty( $template ) ) {
            return;
        }

        $message = str_replace(
            [ '{site_name}', '{site_url}', '{update_list}' ],
            [ get_bloginfo( 'name' ), site_url(), implode( "\n", $updates_list ) ],
            $template
        );

        $this->send_notification( $message );
    }

    private function send_notification( $message ) {
        $wa_number  = get_option( 'wagy_admin_wa_number' );
        $email      = get_option( 'wagy_admin_email' );

        $is_connected = Wagy::is_configured() && Wagy::is_logged_in();

        if ( $is_connected && $wa_number ) {
            Wagy::send_message( [
                'phone'   => $wa_number,
                'message' => $message,
            ] );
        } elseif ( $email ) {
            wp_mail(
                $email,
                '[' . get_bloginfo( 'name' ) . '] System Update Notification',
                $message
            );
        }
    }
}

<?php
namespace Wagy\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;

/**
 * Sends real-time WhatsApp (or email fallback) security alerts to the admin.
 *
 * Listens for key WordPress events — successful logins, password changes,
 * new user registrations, and failed login attempts — and dispatches
 * notifications based on the plugin's notification settings.
 *
 * @package Wagy\Security
 */
class SecurityNotifications {

    /**
     * Registers WordPress hooks for all supported notification events.
     */
    public function __construct() {
        add_action( 'wp_login',          [ $this, 'notify_new_login' ],         10, 2 );
        add_action( 'profile_update',    [ $this, 'notify_password_changed' ],  10, 2 );
        add_action( 'after_password_reset', [ $this, 'notify_password_reset' ], 10, 2 );
        add_action( 'user_register',     [ $this, 'notify_new_user' ],          10, 1 );
        add_action( 'wp_login_failed',   [ $this, 'track_failed_login' ],       10, 1 );
    }

    /**
     * Retrieves the configured admin WhatsApp number for receiving alerts.
     *
     * @return string The phone number stored in 'wagy_admin_wa_number', or empty string if not set.
     */
    private function get_admin_wa() {
        return get_option( 'wagy_admin_wa_number' );
    }

    /**
     * Checks whether a user's role is included in the monitored roles list.
     *
     * Only users with monitored roles will trigger notification events.
     *
     * @param \WP_User $user The user to check.
     * @return bool True if at least one of the user's roles is in the monitored list; false otherwise.
     */
    private function is_role_monitored( $user ) {
        if ( ! $user instanceof \WP_User ) {
            return false;
        }
        $monitored_roles = get_option( 'wagy_notify_roles', [] );
        if ( empty( $monitored_roles ) ) {
            return false;
        }
        foreach ( $user->roles as $role ) {
            if ( in_array( $role, $monitored_roles, true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Dispatches a security notification via WhatsApp or email.
     *
     * Attempts to send via WhatsApp first. If WhatsApp is unavailable (offline
     * or not configured), falls back to sending the message via WordPress email.
     *
     * @param string $message The notification message body to send.
     * @return void
     */
    private function send_notification( $message ) {
        $wa_number  = $this->get_admin_wa();
        $email      = get_option( 'wagy_admin_email' );
        $sent_via_wa = false;

        if ( ! empty( $wa_number ) && Wagy::is_logged_in() ) {
            $response = Wagy::send_message( [
                'phone'   => $wa_number,
                'message' => $message,
            ] );
            if ( isset( $response['status'] ) && $response['status'] === 'success' ) {
                $sent_via_wa = true;
            }
        }

        // Fall back to email if WhatsApp delivery was not successful.
        if ( ! $sent_via_wa && ! empty( $email ) ) {
            $subject = sprintf(
                /* translators: %s: site name */
                __( 'WAGY Security Alert - %s', 'wagy-connect' ),
                get_bloginfo( 'name' )
            );
            wp_mail( $email, $subject, $message );
        }
    }

    /**
     * Resolves the client's IP address from common proxy-aware server headers.
     *
     * Checks headers in order of specificity: client IP, forwarded-for chains,
     * then falls back to REMOTE_ADDR.
     *
     * @return string The best-guess client IP address, or 'UNKNOWN' if unavailable.
     */
    private function get_client_ip() {
        $headers = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                // X_FORWARDED_FOR can be a comma-separated list; take the first one.
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return 'UNKNOWN';
    }

    /**
     * Sends a notification when a monitored user successfully logs in.
     *
     * Triggered by the 'wp_login' action. Uses the template stored in
     * 'wagy_notify_new_login_template', supporting {username}, {role}, {time}, and {ip}.
     *
     * @param string   $user_login The username of the logged-in user.
     * @param \WP_User $user       The WP_User object of the logged-in user.
     * @return void
     */
    public function notify_new_login( $user_login, $user ) {
        if ( ! get_option( 'wagy_notify_new_login' ) ) {
            return;
        }
        if ( ! $this->is_role_monitored( $user ) ) {
            return;
        }

        $template   = get_option(
            'wagy_notify_new_login_template',
            "*SECURITY ALERT: New Login*\nUser: {username}\nRole: {role}\nTime: {time}\nIP: {ip}"
        );
        $role_names = array_map( function ( $r ) {
            return wp_roles()->roles[ $r ]['name'] ?? $r;
        }, $user->roles );

        $message = str_replace(
            [ '{username}', '{role}', '{time}', '{ip}' ],
            [ $user_login, implode( ', ', $role_names ), current_time( 'mysql' ), $this->get_client_ip() ],
            $template
        );

        $this->send_notification( $message );
    }

    /**
     * Sends a notification when a monitored user changes their password via the profile page.
     *
     * Triggered by the 'profile_update' action. Only fires when the submitted
     * profile form contains matching pass1/pass2 fields (indicating a password change).
     *
     * @param int      $user_id      The ID of the updated user.
     * @param \WP_User $old_user_data The user data before the update.
     * @return void
     */
    public function notify_password_changed( $user_id, $old_user_data ) {
        if ( ! get_option( 'wagy_notify_password_changed' ) ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $this->is_role_monitored( $user ) ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification
        $pass1 = isset( $_POST['pass1'] ) ? sanitize_text_field( wp_unslash( $_POST['pass1'] ) ) : '';
        $pass2 = isset( $_POST['pass2'] ) ? sanitize_text_field( wp_unslash( $_POST['pass2'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification

        // Only notify if a password was actually submitted and confirmed.
        if ( ! empty( $pass1 ) && ! empty( $pass2 ) && $pass1 === $pass2 ) {
            $this->trigger_password_changed_notification( $user );
        }
    }

    /**
     * Sends a notification when a monitored user resets their password via the lost-password flow.
     *
     * Triggered by the 'after_password_reset' action.
     *
     * @param \WP_User $user     The user whose password was reset.
     * @param string   $new_pass The new plaintext password (not used; handled by WP core).
     * @return void
     */
    public function notify_password_reset( $user, $new_pass ) {
        if ( ! get_option( 'wagy_notify_password_changed' ) ) {
            return;
        }
        if ( ! $this->is_role_monitored( $user ) ) {
            return;
        }

        $this->trigger_password_changed_notification( $user );
    }

    /**
     * Builds and sends the password-changed notification message.
     *
     * Shared by both notify_password_changed() and notify_password_reset().
     * Uses the template stored in 'wagy_notify_password_changed_template',
     * supporting {username}, {role}, and {time}.
     *
     * @param \WP_User $user The user whose password was changed or reset.
     * @return void
     */
    private function trigger_password_changed_notification( $user ) {
        $template   = get_option(
            'wagy_notify_password_changed_template',
            "*SECURITY ALERT: Password Changed*\nUser: {username}\nRole: {role}\nTime: {time}"
        );
        $role_names = array_map( function ( $r ) {
            return wp_roles()->roles[ $r ]['name'] ?? $r;
        }, $user->roles );

        $message = str_replace(
            [ '{username}', '{role}', '{time}' ],
            [ $user->user_login, implode( ', ', $role_names ), current_time( 'mysql' ) ],
            $template
        );

        $this->send_notification( $message );
    }

    /**
     * Sends a notification when a new user registers.
     *
     * Triggered by the 'user_register' action. Only fires if the new user's
     * assigned role is in the monitored roles list. Uses the template stored in
     * 'wagy_notify_new_user_template', supporting {username}, {email}, and {time}.
     *
     * @param int $user_id The ID of the newly registered user.
     * @return void
     */
    public function notify_new_user( $user_id ) {
        if ( ! get_option( 'wagy_notify_new_user' ) ) {
            return;
        }

        $user = get_userdata( $user_id );
        if ( ! $this->is_role_monitored( $user ) ) {
            return;
        }

        $template = get_option(
            'wagy_notify_new_user_template',
            "*SECURITY ALERT: New User Registered*\nUsername: {username}\nEmail: {email}\nTime: {time}"
        );

        $message = str_replace(
            [ '{username}', '{email}', '{time}' ],
            [ $user->user_login, $user->user_email, current_time( 'mysql' ) ],
            $template
        );

        $this->send_notification( $message );
    }

    /**
     * Tracks failed login attempts and sends a brute-force alert when the threshold is hit.
     *
     * Triggered by the 'wp_login_failed' action. Counts attempts per IP using
     * a 15-minute transient. Sends exactly one notification when the configured
     * threshold is reached (to avoid alert spam on continued attempts).
     *
     * @param string $username The username that was attempted.
     * @return void
     */
    public function track_failed_login( $username ) {
        if ( ! get_option( 'wagy_notify_failed_login' ) ) {
            return;
        }

        $ip             = $this->get_client_ip();
        $transient_name = 'wagy_failed_login_' . md5( $ip );

        // Increment the attempt counter, initializing to 0 if not yet set.
        $attempts = get_transient( $transient_name ) ?: 0;
        $attempts++;

        // Track attempts for 15 minutes (resets after the window expires).
        set_transient( $transient_name, $attempts, 15 * MINUTE_IN_SECONDS );

        $threshold = (int) get_option( 'wagy_notify_failed_login_threshold', 5 );
        if ( $threshold <= 0 ) {
            $threshold = 5;
        }

        // Notify exactly when the threshold is first reached.
        if ( $attempts === $threshold ) {
            $template = get_option(
                'wagy_notify_failed_login_template',
                "*SECURITY WARNING: Failed Login Threshold Exceeded*\nUsername Attempted: {username}\nIP: {ip}\nAttempts: {attempts}\nTime: {time}"
            );

            $message = str_replace(
                [ '{username}', '{ip}', '{attempts}', '{time}' ],
                [ $username, $ip, $attempts, current_time( 'mysql' ) ],
                $template
            );

            $this->send_notification( $message );
        }
    }
}

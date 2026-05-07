<?php
namespace Wagy\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;

/**
 * Registers and renders the "Settings" admin sub-page for the Wagy plugin.
 *
 * Handles two tabs of settings:
 * - "Wagy": Core API credentials (Base URL, Device ID, Token) and role access.
 * - "Security": Custom Login URL, 2FA configuration, and WhatsApp security notification settings.
 *
 * All settings are registered with the WordPress Settings API for proper sanitization
 * and nonce verification on save.
 *
 * @package Wagy\Admin
 */
final class SettingsPage {

    /**
     * Hooks into WordPress to register menu and settings.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_wagy_update_owner', [ $this, 'ajax_update_owner' ] );
    }

    /**
     * Handles the AJAX request to update device owner info.
     *
     * Validates nonce, sanitizes input, calls Wagy::update_owner(),
     * and returns a JSON response.
     *
     * @return void
     */
    public function ajax_update_owner(): void {
        check_ajax_referer( 'wagy_update_owner_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wagy-connect' ) ] );
        }

        $email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $whatsapp = preg_replace( '/[^0-9]/', '', sanitize_text_field( wp_unslash( $_POST['whatsapp'] ?? '' ) ) );

        if ( empty( $email ) && empty( $whatsapp ) ) {
            wp_send_json_error( [ 'message' => __( 'At least one of Email or WhatsApp is required.', 'wagy-connect' ) ] );
        }

        $result = Wagy::update_owner( [ 'email' => $email, 'whatsapp' => $whatsapp ] );

        if ( isset( $result['status'] ) && $result['status'] === 'success' ) {
            wp_send_json_success( [ 'message' => __( 'Owner info updated successfully.', 'wagy-connect' ) ] );
        } else {
            wp_send_json_error( [ 'message' => $result['message'] ?? __( 'Failed to update owner info.', 'wagy-connect' ) ] );
        }
    }

    /**
     * Registers the "Settings" sub-menu page under the Wagy menu.
     *
     * Only accessible by users with the 'manage_options' capability.
     *
     * @return void
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wagy-status',
            __( 'Wagy Settings', 'wagy-connect' ),
            __( 'Settings', 'wagy-connect' ),
            'manage_options',
            'wagy-settings',
            [ $this, 'settings_page_html' ]
        );
    }

    /**
     * Registers all plugin options with the WordPress Settings API.
     *
     * Groups all options under 'wagy_settings_group'. The API token is
     * encrypted via Wagy::encrypt() before being stored.
     *
     * @return void
     */
    public function register_settings() {
        // --- Core API Settings ---
        register_setting( 'wagy_settings_group', 'wagy_base_url',   [ 'sanitize_callback' => 'sanitize_url' ] );
        register_setting( 'wagy_settings_group', 'wagy_device_id',  [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'wagy_settings_group', 'wagy_token', [
            'sanitize_callback' => [ '\Wagy\Wagy', 'encrypt' ],
        ] );
        register_setting( 'wagy_settings_group', 'wagy_access_control', [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => function( $val ) {
                if ( ! is_array( $val ) ) return [];
                foreach ( $val as $page => &$data ) {
                    $data['roles'] = array_map( 'sanitize_text_field', $data['roles'] ?? [] );
                    // sanitize comma separated usernames
                    if ( isset( $data['viewers'] ) && ! is_array( $data['viewers'] ) ) {
                        $data['viewers'] = array_filter( array_map( 'sanitize_user', array_map( 'trim', explode( ',', $data['viewers'] ) ) ) );
                    }
                    if ( isset( $data['managers'] ) && ! is_array( $data['managers'] ) ) {
                        $data['managers'] = array_filter( array_map( 'sanitize_user', array_map( 'trim', explode( ',', $data['managers'] ) ) ) );
                    }
                    // Auto-add current user login to managers if strict mode is enabled to prevent self-lockout
                    $current_user_login = wp_get_current_user()->user_login;
                    if ( ! empty( $data['strict'] ) && ! in_array( $current_user_login, $data['managers'] ?? [], true ) ) {
                        $data['managers'][] = $current_user_login;
                    }
                }
                return $val;
            },
        ] );

        // --- Security: Custom Login & Admin Block ---
        register_setting( 'wagy_settings_group', 'wagy_custom_login_slug',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'wagy_settings_group', 'wagy_admin_block_message',  [ 'sanitize_callback' => 'sanitize_text_field' ] );

        // --- Security: Two-Factor Authentication ---
        register_setting( 'wagy_settings_group', 'wagy_2fa_enabled', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'wagy_settings_group', 'wagy_2fa_roles', [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => function( $val ) {
                return is_array( $val ) ? array_map( 'sanitize_text_field', $val ) : [];
            },
        ] );
        register_setting( 'wagy_settings_group', 'wagy_2fa_role_methods', [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => function( $val ) {
                if ( ! is_array( $val ) ) {
                    return [];
                }
                $allowed = [ 'wa_fallback_email', 'email_only' ];
                $clean   = [];
                foreach ( $val as $role => $method ) {
                    $clean[ sanitize_key( $role ) ] = in_array( $method, $allowed, true ) ? $method : 'wa_fallback_email';
                }
                return $clean;
            },
        ] );
        register_setting( 'wagy_settings_group', 'wagy_2fa_message', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );

        // --- Security: WhatsApp Notification Settings ---
        register_setting( 'wagy_settings_group', 'wagy_admin_wa_number', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'wagy_settings_group', 'wagy_admin_email',     [ 'sanitize_callback' => 'sanitize_email' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_roles', [
            'type'              => 'array',
            'default'           => [],
            'sanitize_callback' => function( $val ) {
                return is_array( $val ) ? array_map( 'sanitize_text_field', $val ) : [];
            },
        ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_new_login',           [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_new_login_template',   [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_password_changed',     [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_password_changed_template', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_new_user',             [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_new_user_template',    [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_failed_login',         [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_failed_login_threshold', [
            'type'              => 'integer',
            'default'           => 5,
            'sanitize_callback' => 'absint',
        ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_failed_login_template', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );

        // --- System Update Notifications ---
        register_setting( 'wagy_settings_group', 'wagy_notify_updates_available', [ 'sanitize_callback' => 'sanitize_text_field' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_updates_available_template', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_updates_completed', [ 'sanitize_callback' => 'absint' ] );
        register_setting( 'wagy_settings_group', 'wagy_notify_updates_completed_template', [ 'sanitize_callback' => 'sanitize_textarea_field' ] );
    }

    /**
     * Renders the full HTML for the Settings page, including two tabs.
     *
     * Reads all current option values from the database and populates
     * form fields. Tab switching is handled client-side with JavaScript.
     *
     * @return void
     */
    public function settings_page_html() {
        // --- Core Settings ---
        $base_url   = get_option( 'wagy_base_url' );
        $device_id  = get_option( 'wagy_device_id' );
        $token      = Wagy::decrypt( get_option( 'wagy_token' ) );
        $capability = get_option( 'wagy_capability', 'manage_options' );

        // --- Security: Custom Login ---
        $custom_login_slug        = get_option( 'wagy_custom_login_slug' );
        $wagy_admin_block_message = get_option( 'wagy_admin_block_message', __( 'Access denied.', 'wagy-connect' ) );

        // --- Security: 2FA ---
        $wagy_2fa_enabled      = get_option( 'wagy_2fa_enabled' );
        $wagy_2fa_roles        = get_option( 'wagy_2fa_roles', [] );
        $wagy_2fa_role_methods = get_option( 'wagy_2fa_role_methods', [] );
        $wagy_2fa_message      = get_option( 'wagy_2fa_message', "Your OTP code is: {otp}\nDo not share this code with anyone." );

        // --- Notification Settings ---
        $wagy_admin_wa_number = get_option( 'wagy_admin_wa_number' );
        $wagy_admin_email     = get_option( 'wagy_admin_email' );
        $wagy_notify_roles    = get_option( 'wagy_notify_roles', [] );

        $wagy_notify_new_login          = get_option( 'wagy_notify_new_login' );
        $wagy_notify_new_login_template = get_option(
            'wagy_notify_new_login_template',
            "*SECURITY ALERT: New Login*\nUser: {username}\nRole: {role}\nTime: {time}\nIP: {ip}"
        );

        $wagy_notify_password_changed          = get_option( 'wagy_notify_password_changed' );
        $wagy_notify_password_changed_template = get_option(
            'wagy_notify_password_changed_template',
            "*SECURITY ALERT: Password Changed*\nUser: {username}\nRole: {role}\nTime: {time}"
        );

        $wagy_notify_new_user          = get_option( 'wagy_notify_new_user' );
        $wagy_notify_new_user_template = get_option(
            'wagy_notify_new_user_template',
            "*SECURITY ALERT: New User Registered*\nUsername: {username}\nEmail: {email}\nTime: {time}"
        );

        $wagy_notify_failed_login           = get_option( 'wagy_notify_failed_login' );
        $wagy_notify_failed_login_threshold = get_option( 'wagy_notify_failed_login_threshold', 5 );
        $wagy_notify_failed_login_template  = get_option(
            'wagy_notify_failed_login_template',
            "*SECURITY WARNING: Failed Login Threshold Exceeded*\nUsername Attempted: {username}\nIP: {ip}\nAttempts: {attempts}\nTime: {time}"
        );

        $wagy_notify_updates_available = get_option( 'wagy_notify_updates_available', 'disabled' );
        $wagy_notify_updates_available_template = get_option(
            'wagy_notify_updates_available_template',
            "🔔 *Update Tersedia untuk {site_name}*\n\nTerdapat {total_updates} pembaruan yang tersedia:\n{update_list}\n\nSegera login ke {site_url}/wp-admin untuk melakukan update."
        );

        $wagy_notify_updates_completed = get_option( 'wagy_notify_updates_completed' );
        $wagy_notify_updates_completed_template = get_option(
            'wagy_notify_updates_completed_template',
            "✅ *Update Selesai: {site_name}*\n\nBerhasil memperbarui:\n{update_list}\n\nSilakan cek kondisi website Anda saat ini."
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Wagy Settings', 'wagy-connect' ); ?></h1>

            <h2 class="nav-tab-wrapper" id="wagy-tabs">
                <a href="#wagy"     class="nav-tab nav-tab-active" data-tab="wagy"    ><?php esc_html_e( 'Wagy', 'wagy-connect' ); ?></a>
                <a href="#security" class="nav-tab"                data-tab="security"><?php esc_html_e( 'Security', 'wagy-connect' ); ?></a>
                <a href="#owner"    class="nav-tab"                data-tab="owner"   ><?php esc_html_e( 'Owner Info', 'wagy-connect' ); ?></a>
                <a href="#access"   class="nav-tab"                data-tab="access"  ><?php esc_html_e( 'Access Control', 'wagy-connect' ); ?></a>
            </h2>

            <form action="options.php" method="post">
                <?php settings_fields( 'wagy_settings_group' ); ?>

                <!-- ======== TAB: WAGY CORE ======== -->
                <table class="form-table wagy-tab-content" id="tab-wagy">
                    <!-- API Settings -->
                    <tr>
                        <th scope="row" colspan="2"><h3><?php esc_html_e( 'API Settings', 'wagy-connect' ); ?></h3><hr></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Base URL', 'wagy-connect' ); ?></th>
                        <td>
                            <input type="text" name="wagy_base_url" value="<?php echo esc_attr( $base_url ); ?>" class="regular-text" required />
                            <p class="description"><?php esc_html_e( 'The full URL of your WAGY API server (e.g. https://wagy.example.com).', 'wagy-connect' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Device ID', 'wagy-connect' ); ?></th>
                        <td>
                            <input type="text" name="wagy_device_id" value="<?php echo esc_attr( $device_id ); ?>" class="regular-text" required />
                            <p class="description"><?php esc_html_e( 'Your registered WhatsApp device ID on the WAGY server.', 'wagy-connect' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Client Token', 'wagy-connect' ); ?></th>
                        <td>
                            <input type="password" name="wagy_token" value="<?php echo esc_attr( $token ); ?>" class="regular-text" required />
                            <p class="description"><?php esc_html_e( 'The API bearer token for authenticating with the WAGY server. Stored encrypted.', 'wagy-connect' ); ?></p>
                        </td>
                    </tr>
                    <!-- Role Based Access Removed - Now handled in Access Control Tab -->
                </table>

                <!-- ======== TAB: SECURITY ======== -->
                <table class="form-table wagy-tab-content" id="tab-security" style="display:none;">

                    <!-- Basic Security -->
                    <tr>
                        <th scope="row" colspan="2"><h3><?php esc_html_e( 'Basic Security', 'wagy-connect' ); ?></h3><hr></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Custom Login Slug', 'wagy-connect' ); ?></th>
                        <td>
                            <code><?php echo esc_url( site_url( '/' ) ); ?></code>
                            <input type="text" name="wagy_custom_login_slug" value="<?php echo esc_attr( $custom_login_slug ); ?>" class="regular-text" placeholder="wp-login.php" />
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: example slug */
                                    esc_html__( 'Leave blank to use the default login page. Example: %s', 'wagy-connect' ),
                                    '<code>my-login</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Admin Block Message', 'wagy-connect' ); ?></th>
                        <td>
                            <input type="text" name="wagy_admin_block_message" value="<?php echo esc_attr( $wagy_admin_block_message ); ?>" class="large-text" />
                            <p class="description"><?php esc_html_e( 'Message shown to unauthenticated visitors who try to access /wp-admin directly.', 'wagy-connect' ); ?></p>
                        </td>
                    </tr>

                    <!-- Two-Factor Authentication -->
                    <tr>
                        <th scope="row" colspan="2"><br><h3><?php esc_html_e( 'Two-Factor Authentication (2FA)', 'wagy-connect' ); ?></h3><hr></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Enable 2FA', 'wagy-connect' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wagy_2fa_enabled" name="wagy_2fa_enabled" value="1" <?php checked( $wagy_2fa_enabled, '1' ); ?> />
                                <?php esc_html_e( 'Enable WAGY Two-Factor Authentication', 'wagy-connect' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr class="wagy-2fa-setting">
                        <th scope="row"><?php esc_html_e( '2FA Required Roles', 'wagy-connect' ); ?></th>
                        <td>
                            <table class="widefat" style="max-width:520px;">
                                <thead>
                                    <tr>
                                        <th style="width:30px;"></th>
                                        <th><?php esc_html_e( 'Role', 'wagy-connect' ); ?></th>
                                        <th><?php esc_html_e( 'OTP Delivery Method', 'wagy-connect' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php
                                $all_roles = wp_roles()->get_names();
                                foreach ( $all_roles as $role_key => $role_name ) {
                                    $is_checked    = in_array( $role_key, (array) $wagy_2fa_roles, true );
                                    $role_method   = $wagy_2fa_role_methods[ $role_key ] ?? 'wa_fallback_email';
                                    $row_id        = 'wagy_2fa_role_' . esc_attr( $role_key );
                                    echo '<tr>';
                                    echo '<td><input type="checkbox" id="' . esc_attr( $row_id ) . '" name="wagy_2fa_roles[]" value="' . esc_attr( $role_key ) . '" ' . checked( $is_checked, true, false ) . ' /></td>';
                                    echo '<td><label for="' . esc_attr( $row_id ) . '">' . esc_html( $role_name ) . '</label></td>';
                                    echo '<td>';
                                    echo '<select name="wagy_2fa_role_methods[' . esc_attr( $role_key ) . ']" style="min-width:260px;">';
                                    echo '<option value="wa_fallback_email"' . selected( $role_method, 'wa_fallback_email', false ) . '>' . esc_html__( 'WhatsApp (fallback to email)', 'wagy-connect' ) . '</option>';
                                    echo '<option value="email_only"' . selected( $role_method, 'email_only', false ) . '>' . esc_html__( 'Email only', 'wagy-connect' ) . '</option>';
                                    echo '</select>';
                                    echo '</td>';
                                    echo '</tr>';
                                }
                                ?>
                                </tbody>
                            </table>
                            <p class="description"><?php esc_html_e( 'Check a role to require 2FA, then choose how the OTP is delivered for that role.', 'wagy-connect' ); ?></p>
                        </td>
                    </tr>
                    <tr class="wagy-2fa-setting">
                        <th scope="row"><?php esc_html_e( '2FA Message Template', 'wagy-connect' ); ?></th>
                        <td>
                            <textarea name="wagy_2fa_message" rows="4" class="large-text"><?php echo esc_textarea( $wagy_2fa_message ); ?></textarea>
                            <p class="description">
                                <?php
                                printf(
                                    /* translators: %s: placeholder token */
                                    esc_html__( 'Use %s to insert the 6-digit OTP code.', 'wagy-connect' ),
                                    '<code>{otp}</code>'
                                );
                                ?>
                            </p>
                        </td>
                    </tr>

                    <!-- WhatsApp Security Notifications -->
                    <tr>
                        <th scope="row" colspan="2"><br><h3><?php esc_html_e( 'WhatsApp Security Notifications', 'wagy-connect' ); ?></h3><hr></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Admin WhatsApp Number', 'wagy-connect' ); ?></th>
                        <td>
                            <input type="text" name="wagy_admin_wa_number" value="<?php echo esc_attr( $wagy_admin_wa_number ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. 628123456789', 'wagy-connect' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Admin WhatsApp number to receive security alerts. Use country code without +.', 'wagy-connect' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Admin Email (Fallback)', 'wagy-connect' ); ?></th>
                        <td>
                            <input type="email" name="wagy_admin_email" value="<?php echo esc_attr( $wagy_admin_email ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'admin@example.com', 'wagy-connect' ); ?>" />
                            <p class="description"><?php esc_html_e( 'Fallback email address used when WAGY is offline or not connected.', 'wagy-connect' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Monitored Roles', 'wagy-connect' ); ?></th>
                        <td>
                            <fieldset>
                                <?php
                                $roles = wp_roles()->get_names();
                                foreach ( $roles as $role_key => $role_name ) {
                                    echo '<label style="display:block; margin-bottom: 5px;">';
                                    echo '<input type="checkbox" name="wagy_notify_roles[]" value="' . esc_attr( $role_key ) . '" ';
                                    checked( in_array( $role_key, (array) $wagy_notify_roles, true ), true );
                                    echo ' /> ';
                                    echo esc_html( $role_name );
                                    echo '</label>';
                                }
                                ?>
                            </fieldset>
                            <p class="description"><?php esc_html_e( 'Select which user roles to monitor for login and password change events.', 'wagy-connect' ); ?></p>
                        </td>
                    </tr>

                    <!-- Notify: New Login -->
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Notify on New Login', 'wagy-connect' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wagy_notify_new_login" name="wagy_notify_new_login" value="1" <?php checked( $wagy_notify_new_login, '1' ); ?> />
                                <?php esc_html_e( 'Send a message when a monitored user logs in.', 'wagy-connect' ); ?>
                            </label>
                            <div class="wagy-notify-new-login-setting" style="margin-top: 10px;">
                                <textarea name="wagy_notify_new_login_template" rows="4" class="large-text"><?php echo esc_textarea( $wagy_notify_new_login_template ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Available tokens: {username}, {role}, {ip}, {time}', 'wagy-connect' ); ?></p>
                            </div>
                        </td>
                    </tr>

                    <!-- Notify: Password Changed -->
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Notify on Password Change', 'wagy-connect' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wagy_notify_password_changed" name="wagy_notify_password_changed" value="1" <?php checked( $wagy_notify_password_changed, '1' ); ?> />
                                <?php esc_html_e( 'Send a message when a monitored user changes their password.', 'wagy-connect' ); ?>
                            </label>
                            <div class="wagy-notify-password-changed-setting" style="margin-top: 10px;">
                                <textarea name="wagy_notify_password_changed_template" rows="4" class="large-text"><?php echo esc_textarea( $wagy_notify_password_changed_template ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Available tokens: {username}, {role}, {time}', 'wagy-connect' ); ?></p>
                            </div>
                        </td>
                    </tr>

                    <!-- Notify: New User -->
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Notify on New User Registration', 'wagy-connect' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wagy_notify_new_user" name="wagy_notify_new_user" value="1" <?php checked( $wagy_notify_new_user, '1' ); ?> />
                                <?php esc_html_e( 'Send a message when a new user registers.', 'wagy-connect' ); ?>
                            </label>
                            <div class="wagy-notify-new-user-setting" style="margin-top: 10px;">
                                <textarea name="wagy_notify_new_user_template" rows="4" class="large-text"><?php echo esc_textarea( $wagy_notify_new_user_template ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Available tokens: {username}, {email}, {time}', 'wagy-connect' ); ?></p>
                            </div>
                        </td>
                    </tr>

                    <!-- Notify: Failed Login (Brute-Force) -->
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Notify on Brute-Force Attempts', 'wagy-connect' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wagy_notify_failed_login" name="wagy_notify_failed_login" value="1" <?php checked( $wagy_notify_failed_login, '1' ); ?> />
                                <?php esc_html_e( 'Send an alert when the failed login threshold is exceeded.', 'wagy-connect' ); ?>
                            </label>
                            <div class="wagy-notify-failed-login-setting" style="margin-top: 10px;">
                                <label style="display:block; margin-bottom: 5px;">
                                    <?php esc_html_e( 'Failure threshold:', 'wagy-connect' ); ?>
                                    <input type="number" name="wagy_notify_failed_login_threshold" value="<?php echo esc_attr( $wagy_notify_failed_login_threshold ); ?>" class="small-text" min="1" />
                                    <?php esc_html_e( 'attempts', 'wagy-connect' ); ?>
                                </label>
                                <textarea name="wagy_notify_failed_login_template" rows="4" class="large-text"><?php echo esc_textarea( $wagy_notify_failed_login_template ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Available tokens: {username}, {ip}, {attempts}, {time}', 'wagy-connect' ); ?></p>
                            </div>
                        </td>
                    </tr>

                    <!-- System Update Notifications -->
                    <tr>
                        <th scope="row" colspan="2"><br><h3><?php esc_html_e( 'System Update Notifications', 'wagy-connect' ); ?></h3><hr></th>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Available Updates', 'wagy-connect' ); ?></th>
                        <td>
                            <select name="wagy_notify_updates_available" id="wagy_notify_updates_available">
                                <option value="disabled" <?php selected( $wagy_notify_updates_available, 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'wagy-connect' ); ?></option>
                                <option value="hourly" <?php selected( $wagy_notify_updates_available, 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'wagy-connect' ); ?></option>
                                <option value="twicedaily" <?php selected( $wagy_notify_updates_available, 'twicedaily' ); ?>><?php esc_html_e( 'Twice Daily', 'wagy-connect' ); ?></option>
                                <option value="daily" <?php selected( $wagy_notify_updates_available, 'daily' ); ?>><?php esc_html_e( 'Daily', 'wagy-connect' ); ?></option>
                                <option value="weekly" <?php selected( $wagy_notify_updates_available, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wagy-connect' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Check and notify about available plugin, theme, and core updates.', 'wagy-connect' ); ?></p>
                            <div class="wagy-notify-updates-available-setting" style="margin-top: 10px;">
                                <textarea name="wagy_notify_updates_available_template" rows="5" class="large-text"><?php echo esc_textarea( $wagy_notify_updates_available_template ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Available tokens: {site_name}, {site_url}, {total_updates}, {update_list}', 'wagy-connect' ); ?></p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Completed Updates', 'wagy-connect' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" id="wagy_notify_updates_completed" name="wagy_notify_updates_completed" value="1" <?php checked( $wagy_notify_updates_completed, '1' ); ?> />
                                <?php esc_html_e( 'Send a message when an update successfully completes (manual or auto-update).', 'wagy-connect' ); ?>
                            </label>
                            <div class="wagy-notify-updates-completed-setting" style="margin-top: 10px;">
                                <textarea name="wagy_notify_updates_completed_template" rows="5" class="large-text"><?php echo esc_textarea( $wagy_notify_updates_completed_template ); ?></textarea>
                                <p class="description"><?php esc_html_e( 'Available tokens: {site_name}, {site_url}, {update_list}', 'wagy-connect' ); ?></p>
                            </div>
                        </td>
                    </tr>

                </table>

                <!-- ======== TAB: ACCESS CONTROL ======== -->
                <?php
                $wagy_access_control = get_option( 'wagy_access_control', [] );
                $all_roles = wp_roles()->get_names();
                ?>
                <table class="form-table wagy-tab-content" id="tab-access" style="display:none;">
                    <tr>
                        <th scope="row" colspan="2">
                            <h3><?php esc_html_e( 'Page Access Control', 'wagy-connect' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Configure who can access each Wagy feature.', 'wagy-connect' ); ?></p>
                            <hr>
                        </th>
                    </tr>
                    <?php
                    if ( class_exists( '\Wagy\Admin\AccessControl' ) ) {
                        $pages = \Wagy\Admin\AccessControl::get_pages();
                        foreach ( $pages as $page_slug => $page_name ) :
                            $page_data = $wagy_access_control[ $page_slug ] ?? [];
                            $is_strict = ! empty( $page_data['strict'] );
                            $selected_roles = $page_data['roles'] ?? ['administrator'];
                            $viewers = implode( ', ', $page_data['viewers'] ?? [] );
                            $managers = implode( ', ', $page_data['managers'] ?? [] );
                            $can_manage = \Wagy\Admin\AccessControl::current_user_can_manage( $page_slug );
                        ?>
                        <tr style="border-bottom: 1px solid #ccc;">
                            <th scope="row">
                                <strong><?php echo esc_html( $page_name ); ?></strong>
                            </th>
                            <td style="padding-bottom: 20px;">
                                <?php if ( ! $can_manage ) : ?>
                                    <div class="notice notice-warning inline"><p>
                                        <?php esc_html_e( 'This page is locked by Strict Mode. You do not have permission to change these settings.', 'wagy-connect' ); ?>
                                    </p></div>
                                <?php else : ?>
                                    <!-- Strict Mode Toggle -->
                                    <label style="display:block; margin-bottom: 10px;">
                                        <input type="checkbox" class="wagy-strict-toggle" data-target="<?php echo esc_attr( $page_slug ); ?>" name="wagy_access_control[<?php echo esc_attr( $page_slug ); ?>][strict]" value="1" <?php checked( $is_strict, true ); ?> />
                                        <strong><?php esc_html_e( 'Enable Strict Mode', 'wagy-connect' ); ?></strong>
                                        <span class="description"><?php esc_html_e( '(Overrides Role Base. Only specific users can view or manage)', 'wagy-connect' ); ?></span>
                                    </label>
                                    
                                    <div id="wagy-access-<?php echo esc_attr( $page_slug ); ?>-standard" style="<?php echo $is_strict ? 'display:none;' : ''; ?>">
                                        <p><strong><?php esc_html_e( 'Allowed Roles:', 'wagy-connect' ); ?></strong></p>
                                        <div style="max-height: 100px; overflow-y: auto; border: 1px solid #ccc; padding: 5px; margin-bottom: 10px; background: #fff;">
                                            <?php foreach ( $all_roles as $role_key => $role_name ) : ?>
                                                <label style="display:block;">
                                                    <input type="checkbox" name="wagy_access_control[<?php echo esc_attr( $page_slug ); ?>][roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $selected_roles, true ) ); ?> />
                                                    <?php echo esc_html( $role_name ); ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div id="wagy-access-<?php echo esc_attr( $page_slug ); ?>-users">
                                        <p><strong><?php esc_html_e( 'Allowed Specific Users (Usernames, comma separated):', 'wagy-connect' ); ?></strong></p>
                                        <input type="text" class="regular-text" name="wagy_access_control[<?php echo esc_attr( $page_slug ); ?>][viewers]" value="<?php echo esc_attr( $viewers ); ?>" placeholder="e.g. admin, budi" />
                                    </div>
                                    
                                    <div id="wagy-access-<?php echo esc_attr( $page_slug ); ?>-managers" style="<?php echo $is_strict ? '' : 'display:none;'; ?> margin-top: 10px; padding: 10px; background: #fff8e5; border-left: 4px solid #dba617;">
                                        <p><strong><?php esc_html_e( 'Allowed Managers (Usernames, comma separated):', 'wagy-connect' ); ?></strong></p>
                                        <input type="text" class="regular-text" name="wagy_access_control[<?php echo esc_attr( $page_slug ); ?>][managers]" value="<?php echo esc_attr( $managers ); ?>" placeholder="e.g. admin, manager_keuangan" />
                                        <p class="description"><?php esc_html_e( 'Warning: Your own Username will be automatically added to prevent self-lockout.', 'wagy-connect' ); ?></p>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php 
                        endforeach; 
                    }
                    ?>
                </table>

                <!-- ======== TAB: OWNER INFO ======== -->
                <?php
                // Fetch current owner info from the API.
                $owner_data = [];
                if ( get_option( 'wagy_base_url' ) && get_option( 'wagy_device_id' ) && get_option( 'wagy_token' ) ) {
                    $status_resp = Wagy::get_device_status();
                    if ( isset( $status_resp['data']['owner'] ) ) {
                        $owner_data = $status_resp['data']['owner'];
                    }
                }
                ?>
                <div class="wagy-tab-content" id="tab-owner" style="display:none;">
                    <div class="card" style="max-width:600px;padding:20px;margin-top:16px;">
                        <h3><?php esc_html_e( 'Device Owner Information', 'wagy-connect' ); ?></h3>
                        <p class="description" style="margin-bottom:16px;">
                            <?php esc_html_e( 'This information is used by the Wagy server to send inactivity and logout notifications to the device owner.', 'wagy-connect' ); ?>
                        </p>
                        <div id="wagy-owner-notice" style="display:none;"></div>
                        <table class="form-table" style="margin:0;">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Owner Email', 'wagy-connect' ); ?></th>
                                <td>
                                    <input type="email" id="wagy_owner_email"
                                        class="regular-text"
                                        placeholder="<?php esc_attr_e( 'owner@example.com', 'wagy-connect' ); ?>"
                                        value="<?php echo esc_attr( $owner_data['email'] ?? '' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Used for email notifications about inactivity or logout events.', 'wagy-connect' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Owner WhatsApp', 'wagy-connect' ); ?></th>
                                <td>
                                    <input type="text" id="wagy_owner_whatsapp"
                                        class="regular-text"
                                        placeholder="<?php esc_attr_e( 'e.g. 628123456789', 'wagy-connect' ); ?>"
                                        value="<?php echo esc_attr( $owner_data['whatsapp'] ?? '' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Used for WhatsApp notifications. Country code without +.', 'wagy-connect' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <p style="margin-top:16px;">
                            <button type="button" id="wagy-save-owner" class="button button-primary">
                                <?php esc_html_e( 'Save Owner Info', 'wagy-connect' ); ?>
                            </button>
                            <span id="wagy-owner-spinner" class="spinner" style="float:none;vertical-align:middle;margin-left:4px;"></span>
                        </p>
                    </div>
                </div>

                <!-- Tab Switching & Toggle Script -->
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    /**
                     * Toggles visibility of target elements based on a checkbox state.
                     *
                     * @param {string} checkboxId     - The ID of the controlling checkbox.
                     * @param {string} targetSelector - CSS selector for elements to show/hide.
                     */
                    function setupToggle(checkboxId, targetSelector) {
                        var checkbox = document.getElementById(checkboxId);
                        var targets  = document.querySelectorAll(targetSelector);
                        if (!checkbox) return;

                        function applyToggle() {
                            targets.forEach(function (el) {
                                el.style.display = checkbox.checked
                                    ? (el.tagName === 'TR' ? 'table-row' : 'block')
                                    : 'none';
                            });
                        }
                        checkbox.addEventListener('change', applyToggle);
                        applyToggle();
                    }

                    setupToggle('wagy_2fa_enabled',           '.wagy-2fa-setting');
                    setupToggle('wagy_notify_new_login',      '.wagy-notify-new-login-setting');
                    setupToggle('wagy_notify_password_changed', '.wagy-notify-password-changed-setting');
                    setupToggle('wagy_notify_new_user',       '.wagy-notify-new-user-setting');
                    setupToggle('wagy_notify_failed_login',   '.wagy-notify-failed-login-setting');
                    setupToggle('wagy_notify_updates_completed', '.wagy-notify-updates-completed-setting');

                    var updatesAvailableSelect = document.getElementById('wagy_notify_updates_available');
                    var updatesAvailableTarget = document.querySelector('.wagy-notify-updates-available-setting');
                    if (updatesAvailableSelect && updatesAvailableTarget) {
                        function applyUpdatesAvailableToggle() {
                            updatesAvailableTarget.style.display = updatesAvailableSelect.value === 'disabled' ? 'none' : 'block';
                        }
                        updatesAvailableSelect.addEventListener('change', applyUpdatesAvailableToggle);
                        applyUpdatesAvailableToggle();
                    }

                    // Strict Mode Toggle logic
                    document.querySelectorAll('.wagy-strict-toggle').forEach(function(toggle) {
                        toggle.addEventListener('change', function() {
                            var target = this.getAttribute('data-target');
                            var isStrict = this.checked;
                            var standardDiv = document.getElementById('wagy-access-' + target + '-standard');
                            var managersDiv = document.getElementById('wagy-access-' + target + '-managers');
                            
                            if (standardDiv) standardDiv.style.display = isStrict ? 'none' : 'block';
                            if (managersDiv) managersDiv.style.display = isStrict ? 'block' : 'none';
                        });
                    });

                    /**
                     * Switches the active tab and updates the URL hash.
                     *
                     * @param {string} tabId - The data-tab value of the tab to activate.
                     */
                    function switchTab(tabId) {
                        document.querySelectorAll('.nav-tab').forEach(function (t) {
                            t.classList.remove('nav-tab-active');
                        });
                        document.querySelectorAll('.wagy-tab-content').forEach(function (c) {
                            c.style.display = 'none';
                        });

                        var activeTab     = document.querySelector('.nav-tab[data-tab="' + tabId + '"]');
                        var activeContent = document.getElementById('tab-' + tabId);

                        if (activeTab && activeContent) {
                            activeTab.classList.add('nav-tab-active');
                            activeContent.style.display = '';
                            history.replaceState(null, null, '#' + tabId);
                        }

                        // Hide Save Settings button on Owner Info tab.
                        var submitWrap = document.getElementById('wagy-submit-wrap');
                        if (submitWrap) {
                            submitWrap.style.display = tabId === 'owner' ? 'none' : '';
                        }
                    }

                    document.querySelectorAll('.nav-tab').forEach(function (tab) {
                        tab.addEventListener('click', function (e) {
                            e.preventDefault();
                            switchTab(this.getAttribute('data-tab'));
                        });
                    });

                    // Restore active tab from URL hash on page load.
                    var hash = window.location.hash.substring(1);
                    if (hash && document.getElementById('tab-' + hash)) {
                        switchTab(hash);
                    }
                });
                    // Owner Info AJAX (Poin 4)
                    var ownerBtn     = document.getElementById('wagy-save-owner');
                    var ownerSpinner = document.getElementById('wagy-owner-spinner');
                    var ownerNotice  = document.getElementById('wagy-owner-notice');

                    if (ownerBtn) {
                        ownerBtn.addEventListener('click', function() {
                            var email    = document.getElementById('wagy_owner_email').value.trim();
                            var whatsapp = document.getElementById('wagy_owner_whatsapp').value.trim();

                            ownerNotice.style.display = 'none';
                            ownerSpinner.classList.add('is-active');
                            ownerBtn.disabled = true;

                            var data = new FormData();
                            data.append('action', 'wagy_update_owner');
                            data.append('nonce', '<?php echo esc_js( wp_create_nonce( 'wagy_update_owner_nonce' ) ); ?>');
                            data.append('email', email);
                            data.append('whatsapp', whatsapp);

                            fetch(ajaxurl, { method: 'POST', body: data })
                                .then(function(r) { return r.json(); })
                                .then(function(res) {
                                    ownerSpinner.classList.remove('is-active');
                                    ownerBtn.disabled = false;
                                    var cls = res.success ? 'notice-success' : 'notice-error';
                                    var msg = res.success ? res.data.message : res.data.message;
                                    ownerNotice.className = 'notice ' + cls + ' inline';
                                    ownerNotice.innerHTML = '<p>' + msg + '</p>';
                                    ownerNotice.style.display = 'block';
                                })
                                .catch(function() {
                                    ownerSpinner.classList.remove('is-active');
                                    ownerBtn.disabled = false;
                                    ownerNotice.className = 'notice notice-error inline';
                                    ownerNotice.innerHTML = '<p><?php echo esc_js( __( 'Request failed. Please try again.', 'wagy-connect' ) ); ?></p>';
                                    ownerNotice.style.display = 'block';
                                });
                        });
                    }
                </script>

                <div id="wagy-submit-wrap"><?php submit_button( __( 'Save Settings', 'wagy-connect' ) ); ?></div>
            </form>
        </div>
        <?php
    }
}
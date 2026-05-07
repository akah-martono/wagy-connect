<?php
namespace Wagy\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;

/**
 * Handles Two-Factor Authentication (2FA) via WhatsApp OTP for WordPress login.
 *
 * When 2FA is enabled and a user with a required role logs in, this class
 * intercepts the login, generates a time-limited OTP, sends it via WhatsApp
 * (or email as a fallback), and presents an OTP entry form before granting access.
 *
 * @package Wagy\Security
 */
class TwoFactorAuth {

    /**
     * Registers WordPress hooks for 2FA functionality.
     *
     * - Adds a WhatsApp number field to user profile pages.
     * - Intercepts the authentication flow to inject OTP verification.
     * - Registers the custom login action for the OTP form.
     */
    public function __construct() {
        // Display WhatsApp number field on user profiles.
        add_action( 'show_user_profile', [ $this, 'user_profile_fields' ] );
        add_action( 'edit_user_profile', [ $this, 'user_profile_fields' ] );

        // Save the WhatsApp number when a profile is updated.
        add_action( 'personal_options_update', [ $this, 'save_user_profile_fields' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_user_profile_fields' ] );

        // Intercept authentication at priority 99 (after WP's own checks at 20).
        add_filter( 'authenticate', [ $this, 'intercept_login' ], 99, 3 );

        // Handle GET ?action=wagy_2fa to render the OTP form.
        add_action( 'login_form_wagy_2fa', [ $this, 'render_otp_page' ] );
    }

    /**
     * Renders the WhatsApp number input field on user profile pages.
     *
     * This number is used to send the OTP via WhatsApp during 2FA.
     *
     * @param \WP_User $user The user object being edited.
     * @return void
     */
    public function user_profile_fields( $user ) {
        $wa_number = get_user_meta( $user->ID, 'wagy_2fa_whatsapp', true );
        ?>
        <h3><?php esc_html_e( 'WAGY Two-Factor Authentication', 'wagy-connect' ); ?></h3>
        <table class="form-table">
            <tr>
                <th>
                    <label for="wagy_2fa_whatsapp"><?php esc_html_e( 'WhatsApp Number', 'wagy-connect' ); ?></label>
                </th>
                <td>
                    <input
                        type="text"
                        name="wagy_2fa_whatsapp"
                        id="wagy_2fa_whatsapp"
                        value="<?php echo esc_attr( $wa_number ); ?>"
                        class="regular-text"
                    /><br />
                    <span class="description">
                        <?php esc_html_e( 'Enter your number with country code (e.g. 628123456789). Leave blank to use email as fallback.', 'wagy-connect' ); ?>
                    </span>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Saves the WhatsApp number from the user profile form.
     *
     * Validates the current user can edit the target user before saving.
     *
     * @param int $user_id The ID of the user being updated.
     * @return false|void Returns false if the current user lacks permission; otherwise saves and returns void.
     */
    public function save_user_profile_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return false;
        }
        if ( isset( $_POST['wagy_2fa_whatsapp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            update_user_meta(
                $user_id,
                'wagy_2fa_whatsapp',
                sanitize_text_field( wp_unslash( $_POST['wagy_2fa_whatsapp'] ) ) // phpcs:ignore WordPress.Security.NonceVerification
            );
        }
    }

    /**
     * Intercepts the WordPress authentication filter to inject OTP verification.
     *
     * If 2FA is enabled and the authenticated user belongs to a required role,
     * this method generates an OTP, stores it in a transient, sends it to the user,
     * and redirects them to the OTP entry form instead of completing the login.
     *
     * @param \WP_User|\WP_Error|null $user     The user object (or error/null) returned from prior auth filters.
     * @param string                  $username The submitted username.
     * @param string                  $password The submitted password.
     * @return \WP_User|\WP_Error|null Returns the user unmodified if 2FA is not needed; never returns on redirect.
     */
    public function intercept_login( $user, $username, $password ) {
        if ( ! get_option( 'wagy_2fa_enabled' ) ) {
            return $user;
        }
        if ( is_wp_error( $user ) || empty( $user ) ) {
            return $user;
        }

        $roles_required  = get_option( 'wagy_2fa_roles', [] );
        $role_methods    = get_option( 'wagy_2fa_role_methods', [] );
        $needs_2fa       = false;
        $matched_method  = 'wa_fallback_email'; // default

        foreach ( $user->roles as $role ) {
            if ( in_array( $role, $roles_required, true ) ) {
                $needs_2fa      = true;
                $matched_method = $role_methods[ $role ] ?? 'wa_fallback_email';
                break;
            }
        }

        if ( ! $needs_2fa ) {
            return $user;
        }

        // Generate a 6-digit OTP and a unique 32-character session token.
        $otp   = wp_rand( 100000, 999999 );
        $token = wp_generate_password( 32, false );

        // Store the session data in a transient valid for 5 minutes.
        set_transient( 'wagy_2fa_' . $token, [
            'user_id'  => $user->ID,
            'otp'      => $otp,
            'expires'  => time() + 300,
            'attempts' => 0,
        ], 300 );

        // Deliver the OTP to the user using the role-configured method.
        $this->send_otp( $user, $otp, $matched_method );

        // Redirect the user to the OTP verification form.
        $redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url(); // phpcs:ignore WordPress.Security.NonceVerification
        $url         = add_query_arg( [
            'action'      => 'wagy_2fa',
            'token'       => $token,
            'redirect_to' => rawurlencode( $redirect_to ),
        ], wp_login_url() );

        wp_safe_redirect( $url );
        exit;
    }

    /**
     * Sends the OTP code to the user via the role-configured delivery method.
     *
     * @param \WP_User $user   The user receiving the OTP.
     * @param int      $otp    The 6-digit one-time password to deliver.
     * @param string   $method Delivery method: 'wa_fallback_email' or 'email_only'.
     * @return void
     */
    private function send_otp( $user, $otp, string $method = 'wa_fallback_email' ) {
        $message_template = get_option(
            'wagy_2fa_message',
            "Your OTP code is: {otp}\nDo not share this code with anyone."
        );
        $message = str_replace( '{otp}', $otp, $message_template );

        $sent_via_wa = false;

        // Attempt WhatsApp delivery only when method allows it.
        if ( 'wa_fallback_email' === $method ) {
            $wa_number = get_user_meta( $user->ID, 'wagy_2fa_whatsapp', true );

            if ( ! empty( $wa_number ) && Wagy::is_logged_in() ) {
                $response = Wagy::send_message( [
                    'phone'      => $wa_number,
                    'message'    => $message,
                    'expires_in' => 300, // 5 minutes for 2FA
                ] );

                if ( isset( $response['status'] ) && $response['status'] === 'success' ) {
                    $sent_via_wa = true;
                }
            }
        }

        // Send via email if WA was not used/failed, or if method is email_only.
        if ( ! $sent_via_wa ) {
            wp_mail(
                $user->user_email,
                __( 'Your Login OTP Code', 'wagy-connect' ),
                $message
            );
        }
    }

    /**
     * Renders the OTP verification form and handles form submission.
     *
     * Called via the 'login_form_wagy_2fa' action hook (GET ?action=wagy_2fa).
     * Validates the session token, checks OTP attempts, and logs the user in on success.
     * After 3 failed attempts, the session is invalidated.
     *
     * @return void Always exits after rendering or redirecting.
     */
    public function render_otp_page() {
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

        if ( empty( $token ) ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        $session = get_transient( 'wagy_2fa_' . $token );

        // Token missing or expired.
        if ( ! $session || $session['expires'] < time() ) {
            login_header(
                __( 'OTP Expired', 'wagy-connect' ),
                '<p class="message">' . esc_html__( 'Your OTP code has expired. Please log in again to request a new one.', 'wagy-connect' ) . '</p>'
            );
            echo '<p><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Back to Login', 'wagy-connect' ) . '</a></p>';
            login_footer();
            exit;
        }

        $error = '';

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            $input_otp = isset( $_POST['wagy_otp'] ) ? sanitize_text_field( wp_unslash( $_POST['wagy_otp'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

            if ( (string) $input_otp === (string) $session['otp'] ) {
                // OTP is correct — complete login.
                delete_transient( 'wagy_2fa_' . $token );

                $user = get_userdata( $session['user_id'] );
                wp_set_auth_cookie( $session['user_id'], false );
                do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

                $redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url(); // phpcs:ignore WordPress.Security.NonceVerification
                wp_safe_redirect( $redirect_to );
                exit;
            } else {
                // Incorrect OTP — increment attempt counter.
                $session['attempts'] = isset( $session['attempts'] ) ? $session['attempts'] + 1 : 1;

                if ( $session['attempts'] >= 3 ) {
                    // Too many failures — invalidate the session.
                    delete_transient( 'wagy_2fa_' . $token );
                    login_header(
                        __( 'Too Many Attempts', 'wagy-connect' ),
                        '<p class="message">' . esc_html__( 'You have entered an incorrect code 3 times. Please log in again to request a new OTP.', 'wagy-connect' ) . '</p>'
                    );
                    echo '<p><a href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Back to Login', 'wagy-connect' ) . '</a></p>';
                    login_footer();
                    exit;
                } else {
                    $remaining       = 3 - $session['attempts'];
                    $remaining_time  = $session['expires'] - time();
                    if ( $remaining_time > 0 ) {
                        set_transient( 'wagy_2fa_' . $token, $session, $remaining_time );
                    }
                    $error = new \WP_Error(
                        'invalid_otp',
                        sprintf(
                            /* translators: %d: number of remaining attempts */
                            __( 'Incorrect OTP code. You have %d attempt(s) remaining.', 'wagy-connect' ),
                            $remaining
                        )
                    );
                }
            }
        }

        login_header( __( 'Two-Factor Authentication', 'wagy-connect' ), '', $error );

        $redirect_to = ! empty( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url(); // phpcs:ignore WordPress.Security.NonceVerification
        ?>
        <form name="loginform" id="loginform" action="<?php echo esc_url( add_query_arg( [ 'action' => 'wagy_2fa', 'token' => $token ], wp_login_url() ) ); ?>" method="post">
            <p>
                <label for="wagy_otp">
                    <?php esc_html_e( 'OTP Code (6 digits)', 'wagy-connect' ); ?><br />
                    <input type="text" name="wagy_otp" id="wagy_otp" class="input" value="" size="20" autocomplete="off" required autofocus />
                </label>
            </p>
            <p style="margin-bottom: 20px; font-size: 13px; color: #666;">
                <?php esc_html_e( 'An OTP has been sent to your WhatsApp or email address.', 'wagy-connect' ); ?>
            </p>
            <p class="submit">
                <input type="submit" name="wp-submit" id="wp-submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Verify', 'wagy-connect' ); ?>" />
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
            </p>
        </form>
        <?php
        login_footer();
        exit;
    }
}

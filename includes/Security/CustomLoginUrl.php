<?php
namespace Wagy\Security;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Implements a custom login URL to hide the default wp-login.php endpoint.
 *
 * When a custom slug is configured, this class:
 * - Redirects any direct access to wp-login.php back to the homepage.
 * - Serves the WordPress login page at the configured custom slug instead.
 * - Blocks unauthenticated direct access to /wp-admin/ with a configurable error message.
 * - Rewrites all internal wp_login_url() and site_url() references to use the custom slug.
 *
 * @package Wagy\Security
 */
class CustomLoginUrl {

    /**
     * The custom login slug (e.g. 'my-login') configured by the admin.
     *
     * @var string
     */
    private $slug;

    /**
     * Reads the configured slug and registers all necessary hooks.
     *
     * If no slug is configured, no hooks are registered and the class
     * has no effect on the login flow.
     */
    public function __construct() {
        $this->slug = get_option( 'wagy_custom_login_slug' );
        if ( empty( $this->slug ) ) {
            return;
        }

        add_action( 'init', [ $this, 'handle_request' ], 1 );
        add_filter( 'login_url',          [ $this, 'filter_login_url' ], 10, 3 );
        add_filter( 'site_url',           [ $this, 'filter_site_url' ], 10, 4 );
        add_filter( 'network_site_url',   [ $this, 'filter_site_url' ], 10, 3 );
        add_filter( 'wp_redirect',        [ $this, 'filter_wp_redirect' ], 10, 2 );
    }

    /**
     * Handles incoming requests to enforce the custom login URL rules.
     *
     * On every request, this method:
     * 1. Blocks guests from accessing /wp-admin/ (excluding admin-ajax.php and admin-post.php).
     * 2. Redirects any direct access to wp-login.php to the homepage.
     * 3. Serves the WordPress login page when the request matches the configured slug.
     *
     * @return void
     */
    public function handle_request() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        $path        = wp_parse_url( $request_uri, PHP_URL_PATH );
        $path        = trim( (string) $path, '/' );

        // Strip the site's base path (for WordPress in a subdirectory).
        $site_path = wp_parse_url( home_url(), PHP_URL_PATH );
        if ( $site_path ) {
            $site_path = trim( $site_path, '/' );
            if ( ! empty( $site_path ) && strpos( $path, $site_path ) === 0 ) {
                $path = substr( $path, strlen( $site_path ) );
                $path = trim( $path, '/' );
            }
        }

        // Block guest access to /wp-admin/ (allow AJAX and POST handler endpoints).
        if (
            ( strpos( $path, 'wp-admin' ) === 0 || strpos( $path, '/wp-admin' ) !== false ) &&
            ! is_user_logged_in() &&
            strpos( $path, 'admin-ajax.php' ) === false &&
            strpos( $path, 'admin-post.php' ) === false
        ) {
            $block_msg = get_option( 'wagy_admin_block_message', __( 'Access denied.', 'wagy-connect' ) );
            wp_die( esc_html( $block_msg ), esc_html__( 'Forbidden', 'wagy-connect' ), [ 'response' => 403 ] );
        }

        // Block and redirect any direct access to wp-login.php.
        $is_wp_login = strpos( $request_uri, 'wp-login.php' ) !== false;
        if ( $is_wp_login ) {
            $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
            // Allow actions that legitimately use wp-login.php directly.
            if ( in_array( $action, [ 'postpass', 'logout', 'confirmaction' ], true ) ) {
                return;
            }
            wp_safe_redirect( home_url() );
            exit;
        }

        // Serve wp-login.php transparently at the custom slug.
        if ( $path === $this->slug ) {
            // Rewrite REQUEST_URI so wp-login.php processes form submissions correctly.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Internal rewrite, not output.
            $_SERVER['REQUEST_URI'] = str_replace( $this->slug, 'wp-login.php', $_SERVER['REQUEST_URI'] );

            // phpcs:ignore WordPress.PHP.NoSilencedErrors
            @require_once ABSPATH . 'wp-login.php';
            exit;
        }
    }

    /**
     * Filters the login URL returned by wp_login_url() to use the custom slug.
     *
     * @param string    $login_url    The original login URL.
     * @param string    $redirect     The redirect URL (unused).
     * @param bool|null $force_reauth Whether to force re-authentication (unused).
     * @return string The filtered login URL pointing to the custom slug.
     */
    public function filter_login_url( $login_url, $redirect, $force_reauth ) {
        return $this->replace_wp_login( $login_url );
    }

    /**
     * Filters site_url() calls that reference wp-login.php to use the custom slug.
     *
     * Non-login URLs are returned unchanged.
     *
     * @param string      $url     The original URL.
     * @param string      $path    The path portion of the URL.
     * @param string|null $scheme  The URL scheme (unused).
     * @param int|null    $blog_id The blog ID for multisite (unused).
     * @return string The filtered URL.
     */
    public function filter_site_url( $url, $path, $scheme, $blog_id = null ) {
        if ( strpos( $url, 'wp-login.php' ) !== false ) {
            return $this->replace_wp_login( $url );
        }
        return $url;
    }

    /**
     * Filters wp_redirect() calls to ensure redirects to wp-login.php use the custom slug.
     *
     * @param string $location The redirect URL.
     * @param int    $status   The HTTP status code (unused).
     * @return string The filtered redirect URL.
     */
    public function filter_wp_redirect( $location, $status ) {
        return $this->replace_wp_login( $location );
    }

    /**
     * Replaces the 'wp-login.php' segment in a URL with the configured custom slug.
     *
     * If the URL does not contain 'wp-login.php', it is returned unchanged.
     *
     * @param string $url The URL to process.
     * @return string The URL with 'wp-login.php' replaced by the custom slug.
     */
    private function replace_wp_login( $url ) {
        if ( strpos( $url, 'wp-login.php' ) !== false ) {
            $url = str_replace( 'wp-login.php', $this->slug, $url );
        }
        return $url;
    }
}

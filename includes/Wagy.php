<?php
namespace Wagy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Core API client class for the WAGY WhatsApp Gateway.
 *
 * Handles all communication with the external WAGY API server,
 * including sending messages, retrieving device status, quota info,
 * and message logs. Also provides encryption utilities for securing
 * the API token in the database.
 *
 * @package Wagy
 */
class Wagy {

    /**
     * Cached plugin settings to avoid redundant database reads.
     *
     * @var array
     */
    protected static $settings = [];

    /**
     * Retrieves and caches the plugin's core API settings from the database.
     *
     * @return array {
     *     @type string $base_url  The WAGY API server base URL.
     *     @type string $device_id The registered WhatsApp device ID.
     *     @type string $token     The decrypted API bearer token.
     * }
     */
    protected static function get_settings(): array {
        if ( empty( self::$settings ) ) {
            self::$settings = [
                'base_url'  => get_option( 'wagy_base_url' ),
                'device_id' => get_option( 'wagy_device_id' ),
                'token'     => self::decrypt( get_option( 'wagy_token' ) ),
            ];
        }
        return self::$settings;
    }

    /**
     * Normalizes a wp_remote_* response into a standard array format.
     *
     * On WP_Error, returns an error array. On a valid JSON body, returns
     * the decoded array directly. Otherwise wraps the raw body in an error array.
     *
     * @param \WP_Error|array $response The raw response from wp_remote_*.
     * @return array Normalized response with at least a 'status' key.
     */
    protected static function handle_response( $response ): array {
        if ( is_wp_error( $response ) ) {
            return [
                'status'  => 'error',
                'message' => $response->get_error_message(),
            ];
        }

        $code     = wp_remote_retrieve_response_code( $response );
        $body     = wp_remote_retrieve_body( $response );
        $arr_body = json_decode( $body, true );

        if ( $code === 402 ) {
            return [
                'status'  => 'error',
                'code'    => 'payment_required',
                'message' => __( 'Quota exhausted. Please top up or activate a voucher.', 'wagy-connect' ),
            ];
        }

        if ( is_array( $arr_body ) ) {
            return $arr_body;
        }

        return [
            'status'  => 'error',
            'message' => $body,
        ];
    }

    /**
     * Encrypts a plain-text string using AES-256-CBC with WordPress secret keys.
     *
     * Prefixes the result with 'ENC_' to distinguish encrypted values from plain text.
     * If the value is already encrypted, it is returned as-is.
     *
     * @param string $string The plain-text value to encrypt.
     * @return string The encrypted string prefixed with 'ENC_', or the original if already encrypted.
     */
    public static function encrypt( $string ) {
        if ( empty( $string ) || strpos( $string, 'ENC_' ) === 0 ) {
            return $string;
        }
        $key       = \wp_salt( 'auth' );
        $iv        = substr( hash( 'sha256', \wp_salt( 'secure_auth' ) ), 0, 16 );
        $encrypted = openssl_encrypt( $string, 'AES-256-CBC', $key, 0, $iv );
        return 'ENC_' . $encrypted;
    }

    /**
     * Decrypts a value previously encrypted by self::encrypt().
     *
     * If the string does not start with 'ENC_', it is returned as-is
     * (treated as a plain-text legacy value).
     *
     * @param string $string The encrypted string to decrypt.
     * @return string The decrypted plain-text value.
     */
    public static function decrypt( $string ) {
        if ( empty( $string ) || strpos( $string, 'ENC_' ) !== 0 ) {
            return $string;
        }
        $data = substr( $string, 4 );
        $key  = \wp_salt( 'auth' );
        $iv   = substr( hash( 'sha256', \wp_salt( 'secure_auth' ) ), 0, 16 );
        return openssl_decrypt( $data, 'AES-256-CBC', $key, 0, $iv );
    }

    /**
     * Fetches the WhatsApp QR code image from the WAGY API.
     *
     * Used on the Status page when the device is not yet logged in.
     * The returned data contains a base64-encoded QR code image.
     *
     * @return array Normalized API response. On success, 'data.qr_code' holds the base64 image.
     */
    public static function get_qr_code(): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url ) {
            return [
                'status'  => 'error',
                'message' => __( 'Base URL is not configured.', 'wagy-connect' ),
            ];
        }

        $url      = "{$base_url}/{$device_id}/auth/qr/json";
        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ] );

        return self::handle_response( $response );
    }

    /**
     * Retrieves the current connection status of the WhatsApp device.
     *
     * Checks whether the device is connected and whether a WhatsApp account
     * is currently logged in.
     *
     * @return array Normalized API response. On success, 'data.connected' and 'data.logged_in' are booleans.
     */
    public static function get_device_status(): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url ) {
            return [
                'status'  => 'error',
                'message' => __( 'Base URL is not configured.', 'wagy-connect' ),
            ];
        }

        $url      = "{$base_url}/{$device_id}/status";
        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ] );

        return self::handle_response( $response );
    }

    /**
     * Retrieves the current message quota details for the device.
     *
     * Returns both FREE (monthly-resetting) and PRO (invoice-based) quota information.
     *
     * @return array Normalized API response. On success, 'data' contains 'free' and 'pro' quota objects.
     */
    public static function get_device_quota(): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url ) {
            return [
                'status'  => 'error',
                'message' => __( 'Base URL is not configured.', 'wagy-connect' ),
            ];
        }

        $url      = "{$base_url}/{$device_id}/quota";
        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ] );

        return self::handle_response( $response );
    }

    /**
     * Sends a WhatsApp message via the WAGY API.
     *
     * @param array $args {
     *     Required and optional message arguments.
     *
     *     @type string $phone           Required. The recipient phone number (e.g. 628123456789).
     *     @type string $message         Required. The message body text.
     *     @type string $media_url       Optional. A public URL to a media file to attach.
     *     @type string $media_type      Optional. Override media type (image, video, document, audio).
     *     @type int    $expires_in      Optional. Message expiry in seconds. Defaults to 86400 (24h).
     *     @type string $expires_at      Optional. Explicit ISO 8601 expiry timestamp (overrides expires_in).
     *     @type int    $retry_interval  Optional. Retry interval in seconds. Defaults to 5.
     * }
     * @return array Normalized API response. On success, 'data' contains the created message object.
     */
    public static function send_message( array $args ): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url ) {
            return [
                'status'  => 'error',
                'message' => __( 'Base URL is not configured.', 'wagy-connect' ),
            ];
        }

        if ( ! $device_id || ! $token ) {
            return [
                'status'  => 'error',
                'message' => __( 'Device ID or Token is not configured.', 'wagy-connect' ),
            ];
        }

        $payload = [];

        // Phone (recipient) is required.
        $phone = $args['phone'] ?? $args['recipient'] ?? '';
        if ( ! empty( $phone ) ) {
            $payload['phone'] = $phone;
        } else {
            return [
                'status'  => 'error',
                'message' => __( 'Phone number is required.', 'wagy-connect' ),
            ];
        }

        // Message body is required.
        if ( ! empty( $args['message'] ) ) {
            $payload['message'] = $args['message'];
        } else {
            return [
                'status'  => 'error',
                'message' => __( 'Message body is required.', 'wagy-connect' ),
            ];
        }

        // Optional media attachment and type override.
        if ( ! empty( $args['media_url'] ) ) {
            $payload['media_url'] = $args['media_url'];
        }
        if ( ! empty( $args['media_type'] ) ) {
            $payload['media_type'] = $args['media_type'];
        }

        // Message expiry — explicit timestamp takes priority over duration.
        $expires_at = $args['expires_at'] ?? $args['expired_at'] ?? '';
        if ( ! empty( $expires_at ) ) {
            $payload['expires_at'] = $expires_at;
        } else {
            $expires_in            = intval( $args['expires_in'] ?? $args['expired_in'] ?? 0 );
            $payload['expires_in'] = $expires_in > 0 ? $expires_in : 24 * HOUR_IN_SECONDS;
        }

        // Retry interval — defaults to 5 seconds.
        $retry_interval            = intval( $args['retry_interval'] ?? 0 );
        $payload['retry_interval'] = $retry_interval > 0 ? $retry_interval : 5;

        $response = wp_remote_post( "{$base_url}/{$device_id}/send", [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ] );

        return self::handle_response( $response );
    }

    /**
     * Retrieves a paginated, filterable list of messages from the WAGY API.
     *
     * @param array $args {
     *     Optional filter and pagination arguments.
     *
     *     @type int    $page       Page number. Defaults to 1.
     *     @type int    $limit      Results per page. Defaults to 20.
     *     @type string $status     Filter by message status (PENDING, SENT, FAILED, EXPIRED).
     *     @type string $recipient  Filter by recipient phone number.
     *     @type string $start_date Filter by start date (Y-m-d). Converted to UTC automatically.
     *     @type string $end_date   Filter by end date (Y-m-d). Converted to UTC automatically.
     * }
     * @return array Normalized API response. On success, 'data' is an array of message objects and 'meta' holds pagination info.
     */
    public static function get_messages( $args = [] ): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url ) {
            return [
                'status'  => 'error',
                'message' => __( 'Base URL is not configured.', 'wagy-connect' ),
            ];
        }

        if ( ! $device_id || ! $token ) {
            return [
                'status'  => 'error',
                'message' => __( 'Device ID or Token is not configured.', 'wagy-connect' ),
            ];
        }

        // Convert date filters from the site's local timezone to UTC.
        if ( isset( $args['start_date'] ) ) {
            $args['start_date'] = self::convert_wptz_to_utc( $args['start_date'], [ 00, 00, 00 ] );
        }

        if ( isset( $args['end_date'] ) ) {
            $args['end_date'] = self::convert_wptz_to_utc( $args['end_date'], [ 23, 59, 59 ] );
        }

        $query    = http_build_query( $args );
        $url      = "{$base_url}/{$device_id}/messages?" . $query;
        $response = wp_remote_get( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ] );

        return self::handle_response( $response );
    }

    /**
     * Fetches device status with a 5-minute transient cache.
     *
     * Used by admin notices to avoid hitting the API on every page load.
     * Cache is invalidated automatically after 5 minutes.
     *
     * @param bool $force_refresh If true, bypass cache and fetch fresh data.
     * @return array Normalized API response (same structure as get_device_status()).
     */
    public static function get_connection_status_cached( bool $force_refresh = false ): array {
        $settings  = self::get_settings();
        $device_id = $settings['device_id'];
        $cache_key = 'wagy_status_cache_' . md5( $device_id );

        if ( ! $force_refresh ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $result = self::get_device_status();
        set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        return $result;
    }

    /**
     * Invalidates the cached device connection status.
     *
     * Should be called after a successful QR scan or logout event.
     *
     * @return void
     */
    public static function invalidate_status_cache(): void {
        // Resolve the cache key from current settings before resetting them.
        $settings  = self::get_settings();
        $device_id = $settings['device_id'];
        delete_transient( 'wagy_status_cache_' . md5( $device_id ) );

        // Also reset the static settings cache so subsequent calls
        // re-read fresh values from the database (important after option changes).
        self::$settings = [];
    }

    /**
     * Checks whether the WAGY API token is valid by verifying the device status.
     *
     * @return bool True if the API responds with a 'success' status.
     */
    public static function is_token_valid(): bool {
        $status = self::get_device_status();
        return $status['status'] === 'success';
    }

    /**
     * Checks whether the WhatsApp device is connected and logged in.
     *
     * This is used internally before attempting to send notifications
     * to decide whether to use WhatsApp or fall back to email.
     *
     * @return bool True if the device is connected and a WhatsApp account is logged in.
     */
    public static function is_logged_in(): bool {
        $status = self::get_device_status();
        return $status['status'] === 'success' && $status['data']['logged_in'];
    }

    /**
     * Checks if Wagy is configured by verifying raw option values exist.
     *
     * Intentionally does NOT decrypt the token to avoid calling wp_salt()
     * before WordPress has fully loaded pluggable.php.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        return ! empty( get_option( 'wagy_base_url' ) )
            && ! empty( get_option( 'wagy_device_id' ) )
            && ! empty( get_option( 'wagy_token' ) );
    }

    /**
     * Updates the owner information (email and/or WhatsApp) for the device.
     *
     * Calls PUT /:device_id/owner on the WAGY API.
     *
     * @param array $args {
     *     @type string $email     Optional. New owner email address.
     *     @type string $whatsapp  Optional. New owner WhatsApp number (e.g. 628123456789).
     * }
     * @return array Normalized API response.
     */
    public static function update_owner( array $args ): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url || ! $device_id || ! $token ) {
            return [
                'status'  => 'error',
                'message' => __( 'Wagy API is not configured.', 'wagy-connect' ),
            ];
        }

        $payload = array_filter( [
            'email'     => $args['email'] ?? '',
            'whatsapp'  => $args['whatsapp'] ?? '',
        ] );

        if ( empty( $payload ) ) {
            return [
                'status'  => 'error',
                'message' => __( 'At least one of email or whatsapp is required.', 'wagy-connect' ),
            ];
        }

        $response = wp_remote_request( "{$base_url}/{$device_id}/owner", [
            'method'  => 'PUT',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ] );

        return self::handle_response( $response );
    }

    /**
     * Redeems a PRO voucher code to add quota to the device.
     *
     * Calls POST /:device_id/redeem on the WAGY API.
     *
     * @param string $code The voucher code to redeem.
     * @return array Normalized API response.
     */
    public static function redeem_voucher( string $code ): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url || ! $device_id || ! $token ) {
            return [
                'status'  => 'error',
                'message' => __( 'Wagy API is not configured.', 'wagy-connect' ),
            ];
        }

        $response = wp_remote_post( "{$base_url}/{$device_id}/redeem", [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'code' => $code ] ),
            'timeout' => 15,
        ] );

        return self::handle_response( $response );
    }

    /**
     * Cancels a pending message.
     *
     * Calls DELETE /:device_id/message/:id on the WAGY API.
     *
     * @param int $id The message ID.
     * @return array Normalized API response.
     */
    public static function cancel_message( int $id ): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url || ! $device_id || ! $token ) {
            return [
                'status'  => 'error',
                'message' => __( 'Wagy API is not configured.', 'wagy-connect' ),
            ];
        }

        $response = wp_remote_request( "{$base_url}/{$device_id}/messages/{$id}", [
            'method'  => 'DELETE',
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ] );

        return self::handle_response( $response );
    }

    /**
     * Retrieves a single message.
     *
     * Calls GET /:device_id/message/:id on the WAGY API.
     *
     * @param int $id The message ID.
     * @return array Normalized API response.
     */
    public static function get_message( int $id ): array {
        $settings  = self::get_settings();
        $base_url  = $settings['base_url'];
        $device_id = $settings['device_id'];
        $token     = $settings['token'];

        if ( ! $base_url || ! $device_id || ! $token ) {
            return [
                'status'  => 'error',
                'message' => __( 'Wagy API is not configured.', 'wagy-connect' ),
            ];
        }

        $response = wp_remote_get( "{$base_url}/{$device_id}/messages/{$id}", [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
        ] );

        return self::handle_response( $response );
    }

    /**
     * Converts a date/time string from the WordPress site timezone to UTC (ISO 8601).
     *
     * Useful for sending date filters to the WAGY API, which expects UTC timestamps.
     *
     * @param string $input A date string parseable by DateTimeImmutable (e.g. '2024-01-15').
     * @param array  $time  Optional [hours, minutes, seconds] to force a specific time of day.
     * @return string ISO 8601 UTC timestamp (e.g. '2024-01-15T17:00:00Z'), or original on failure.
     */
    public static function convert_wptz_to_utc( $input, array $time = [] ): string {
        try {
            $date = new \DateTimeImmutable( $input, wp_timezone() );
            if ( ! empty( $time ) ) {
                $date = $date->setTime( ...$time );
            }
            $date = $date->setTimezone( new \DateTimeZone( 'UTC' ) );
            return $date->format( 'Y-m-d\TH:i:s\Z' );
        } catch ( \Exception $e ) {
            return $input;
        }
    }

    /**
     * Converts a UTC date/time string to the WordPress site's local timezone.
     *
     * Used when displaying timestamps from the API in the admin UI.
     *
     * @param string $input A UTC date/time string (e.g. '2024-01-15T17:00:00Z').
     * @return string Formatted date/time in the site's local timezone (e.g. '2024-01-16 00:00:00'), or original on failure.
     */
    public static function convert_utc_to_wptz( $input ): string {
        try {
            $date       = new \DateTimeImmutable( $input, new \DateTimeZone( 'UTC' ) );
            $local_date = $date->setTimezone( wp_timezone() );
            return $local_date->format( 'Y-m-d H:i:s' );
        } catch ( \Exception $e ) {
            return $input;
        }
    }
}
<?php
namespace Wagy\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;

/**
 * Registers and renders the "Broadcast" admin sub-page for Wagy.
 *
 * Allows administrators to send a single WhatsApp message to multiple
 * recipients at once. Recipients can be entered manually (one per line)
 * or imported from WordPress users filtered by role.
 *
 * Messages are sent in AJAX batches to avoid PHP timeout issues.
 *
 * @package Wagy\Admin
 */
final class BroadcastPage {

    /**
     * Hooks into WordPress to register menu and AJAX handlers.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'wp_ajax_wagy_broadcast_send_batch', [ $this, 'ajax_send_batch' ] );
        add_action( 'wp_ajax_wagy_broadcast_get_users', [ $this, 'ajax_get_users' ] );
    }

    /**
     * Registers the "Broadcast" sub-menu page under the Wagy menu.
     *
     * @return void
     */
    public function add_admin_menu(): void {
        add_submenu_page(
            'wagy-status',
            __( 'Wagy Broadcast', 'wagy-connect' ),
            __( 'Broadcast', 'wagy-connect' ),
            'wagy_access_broadcast',
            'wagy-broadcast',
            [ $this, 'broadcast_page_html' ]
        );
    }

    /**
     * Handles the AJAX batch send request.
     *
     * Expects a JSON array of phone numbers and a message payload.
     * Sends each number individually and returns a summary JSON.
     *
     * @return void
     */
    public function ajax_send_batch(): void {
        check_ajax_referer( 'wagy_broadcast_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wagy-connect' ) ] );
        }

        // Each entry may be 'phone;field1;field2;...' for dynamic text substitution.
        $raw_lines      = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['phones'] ?? [] ) );
        $message        = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $media_url      = esc_url_raw( wp_unslash( $_POST['media_url'] ?? '' ) );
        $expires_in     = absint( $_POST['expires_in'] ?? 86400 );
        $retry_interval = absint( $_POST['retry_interval'] ?? 60 );

        if ( empty( $raw_lines ) || empty( $message ) ) {
            wp_send_json_error( [ 'message' => __( 'Phone list and message are required.', 'wagy-connect' ) ] );
        }

        $results = [];
        foreach ( $raw_lines as $raw_line ) {
            // Split by semicolon: first part = phone, rest = dynamic fields [1],[2],...
            $parts = array_map( 'trim', explode( ';', $raw_line ) );
            $phone = preg_replace( '/[^0-9]/', '', array_shift( $parts ) );

            if ( empty( $phone ) ) {
                continue;
            }

            // Substitute [1],[2],... with dynamic data fields.
            $personalized = $message;
            foreach ( $parts as $idx => $field ) {
                $personalized = str_replace( '[' . ( $idx + 1 ) . ']', $field, $personalized );
            }

            $args = [
                'phone'          => $phone,
                'message'        => $personalized,
                'expires_in'     => $expires_in,
                'retry_interval' => $retry_interval,
            ];
            if ( ! empty( $media_url ) ) {
                $args['media_url'] = $media_url;
            }

            $response  = Wagy::send_message( $args );
            $results[] = [
                'phone'   => $phone,
                'label'   => $phone . ( ! empty( $parts[0] ) ? ' (' . $parts[0] . ')' : '' ),
                'success' => isset( $response['status'] ) && ( $response['status'] === 'queued' || $response['status'] === 'success' ),
                'message' => $response['message'] ?? ( $response['status'] ?? __( 'Unknown error', 'wagy-connect' ) ),
            ];
        }

        $sent   = count( array_filter( $results, fn( $r ) => $r['success'] ) );
        $failed = count( $results ) - $sent;

        wp_send_json_success( [
            'results' => $results,
            'sent'    => $sent,
            'failed'  => $failed,
        ] );
    }

    /**
     * AJAX handler to fetch phone numbers from a registered source.
     *
     * @return void
     */
    public function ajax_get_users(): void {
        check_ajax_referer( 'wagy_broadcast_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'wagy-connect' ) ] );
        }

        $source_value = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
        if ( empty( $source_value ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid source.', 'wagy-connect' ) ] );
        }

        $sources         = $this->get_import_sources();
        $selected_source = null;

        foreach ( $sources as $source ) {
            if ( $source['value'] === $source_value ) {
                $selected_source = $source;
                break;
            }
        }

        if ( ! $selected_source || ! is_callable( $selected_source['callback'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Source handler not found or invalid.', 'wagy-connect' ) ] );
        }

        // Execute the callback. It should return an array of formatted strings.
        $phones = call_user_func( $selected_source['callback'], $source_value );

        if ( ! is_array( $phones ) ) {
            $phones = [];
        }

        wp_send_json_success( [
            'phones' => array_values( $phones ),
            'count'  => count( $phones ),
            'source' => $selected_source['label'],
        ] );
    }

    /**
     * Retrieves the registered import sources.
     *
     * @return array
     */
    private function get_import_sources(): array {
        $default_sources = [];
        $roles           = wp_roles()->get_names();

        foreach ( $roles as $role_key => $role_name ) {
            $default_sources[] = [
                'label'    => sprintf( __( 'WordPress Users: %s', 'wagy-connect' ), $role_name ),
                'value'    => 'wp_role_' . $role_key,
                'callback' => [ $this, 'fetch_wp_role_users' ],
            ];
        }

        if ( class_exists( 'WooCommerce' ) ) {
            $default_sources[] = [
                'label'    => __( 'WooCommerce Customers (Billing Phone)', 'wagy-connect' ),
                'value'    => 'woo_customers',
                'callback' => [ $this, 'fetch_woo_customers' ],
            ];
        }

        /**
         * Filters the available sources for broadcast import.
         *
         * @param array $sources Array of sources, each containing 'label', 'value', and 'callback'.
         */
        return apply_filters( 'wagy_broadcast_import_sources', $default_sources );
    }

    /**
     * Callback to fetch users by WP role.
     *
     * @param string $source_value The selected value.
     * @return array Formatted strings like 'Phone;Name'
     */
    public function fetch_wp_role_users( string $source_value ): array {
        $role     = str_replace( 'wp_role_', '', $source_value );
        $meta_key = 'wagy_2fa_whatsapp';

        $query_args = [
            'role'       => $role,
            'fields'     => 'all',
            'number'     => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_query' => [
                [
                    'key'     => $meta_key,
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
        ];

        $users     = get_users( $query_args );
        $formatted = [];

        foreach ( $users as $user ) {
            $phone = get_user_meta( $user->ID, $meta_key, true );
            $phone = preg_replace( '/[^0-9]/', '', (string) $phone );
            if ( ! empty( $phone ) ) {
                $name = trim( $user->first_name . ' ' . $user->last_name );
                if ( empty( $name ) ) {
                    $name = $user->display_name;
                }
                $formatted[] = $phone . ';' . $name;
            }
        }

        return $formatted;
    }

    /**
     * Callback to fetch WooCommerce customers.
     *
     * @param string $source_value The selected value.
     * @return array Formatted strings like 'Phone;Name'
     */
    public function fetch_woo_customers( string $source_value ): array {
        $meta_key = 'billing_phone';

        $query_args = [
            'fields'     => 'all',
            'number'     => -1,
            // phpcs:ignore WordPress.DB.SlowDBQuery
            'meta_query' => [
                [
                    'key'     => $meta_key,
                    'value'   => '',
                    'compare' => '!=',
                ],
            ],
        ];

        $users     = get_users( $query_args );
        $formatted = [];

        foreach ( $users as $user ) {
            $phone = get_user_meta( $user->ID, $meta_key, true );
            $phone = preg_replace( '/[^0-9]/', '', (string) $phone );
            if ( ! empty( $phone ) ) {
                $name = trim( $user->first_name . ' ' . $user->last_name );
                if ( empty( $name ) ) {
                    $name = $user->display_name;
                }
                $formatted[] = $phone . ';' . $name;
            }
        }

        return $formatted;
    }

    /**
     * Renders the full HTML for the Broadcast page.
     *
     * @return void
     */
    public function broadcast_page_html(): void {     
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Wagy Broadcast', 'wagy-connect' ); ?></h1>            
            <?php
            // close wrap if device is not connected
            $device_status = Wagy::get_device_status();
            if ( $device_status['status'] !== 'success' ) {
                echo '</div>';
                return;
            }
            ?>
            <p class="description"><?php esc_html_e( 'Send a WhatsApp message to multiple recipients. Each number is queued individually on the Wagy server.', 'wagy-connect' ); ?></p>
            <hr>

            <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start;">

                <!-- ====== LEFT: Compose Form ====== -->
                <div class="card" style="flex:1;min-width:320px;max-width:560px;padding:20px;">
                    <h3 style="margin-top:0;"><?php esc_html_e( 'Compose Message', 'wagy-connect' ); ?></h3>

                    <table class="form-table" style="margin:0;">
                        <tr>
                        <th scope="row"><?php esc_html_e( 'Message', 'wagy-connect' ); ?></th>
                            <td>
                                <textarea id="wagy-bc-message" rows="5" class="large-text"
                                    placeholder="<?php esc_attr_e( 'Type your message here...', 'wagy-connect' ); ?>"></textarea>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: 1: [1] placeholder, 2: [2] placeholder */
                                        esc_html__( 'Use %1$s, %2$s, etc. to insert dynamic data from the recipient list (field 1, field 2, ...).', 'wagy-connect' ),
                                        '<code>[1]</code>',
                                        '<code>[2]</code>'
                                    );
                                    ?>
                                    <br><em><?php esc_html_e( 'Example: "Hi [1], your order on [2] is ready."', 'wagy-connect' ); ?></em>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Media URL', 'wagy-connect' ); ?></th>
                            <td>
                                <input type="url" id="wagy-bc-media-url" class="large-text"
                                    placeholder="<?php esc_attr_e( 'https://example.com/image.jpg (optional)', 'wagy-connect' ); ?>" />
                                <p class="description"><?php esc_html_e( 'Optional. Publicly accessible image, video, or document URL.', 'wagy-connect' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Expires In', 'wagy-connect' ); ?></th>
                            <td>
                                <select id="wagy-bc-expires-in">
                                    <option value="3600"><?php esc_html_e( '1 Hour', 'wagy-connect' ); ?></option>
                                    <option value="21600"><?php esc_html_e( '6 Hours', 'wagy-connect' ); ?></option>
                                    <option value="86400" selected><?php esc_html_e( '24 Hours', 'wagy-connect' ); ?></option>
                                    <option value="259200"><?php esc_html_e( '3 Days', 'wagy-connect' ); ?></option>
                                    <option value="604800"><?php esc_html_e( '7 Days', 'wagy-connect' ); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e( 'Messages not delivered within this time will expire.', 'wagy-connect' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Retry Interval', 'wagy-connect' ); ?></th>
                            <td>
                                <input type="number" id="wagy-bc-retry" class="small-text" value="60" min="5" />
                                <span><?php esc_html_e( 'seconds', 'wagy-connect' ); ?></span>
                                <p class="description"><?php esc_html_e( 'How long to wait before retrying a failed message.', 'wagy-connect' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ====== RIGHT: Recipients ====== -->
                <div class="card" style="flex:1;min-width:320px;max-width:480px;padding:20px;">
                    <h3 style="margin-top:0;"><?php esc_html_e( 'Recipients', 'wagy-connect' ); ?></h3>

                    <!-- Import from Data Source -->
                    <div style="margin-bottom:12px;padding:12px;background:#f6f7f7;border-radius:4px;">
                        <label style="font-weight:600;display:block;margin-bottom:8px;">
                            <?php esc_html_e( 'Import Recipients', 'wagy-connect' ); ?>
                        </label>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <select id="wagy-bc-source" style="flex:1;">
                                <?php
                                $sources = $this->get_import_sources();
                                foreach ( $sources as $source ) :
                                    ?>
                                    <option value="<?php echo esc_attr( $source['value'] ); ?>">
                                        <?php echo esc_html( $source['label'] ); ?>
                                    </option>
                                    <?php
                                endforeach;
                                ?>
                            </select>
                            <button type="button" id="wagy-bc-import-source" class="button">
                                <?php esc_html_e( 'Import Numbers', 'wagy-connect' ); ?>
                            </button>
                            <span id="wagy-bc-import-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
                        </div>
                    </div>

                    <!-- Manual number entry -->
                    <label style="font-weight:600;display:block;margin-bottom:6px;">
                        <?php esc_html_e( 'Recipients (one per line)', 'wagy-connect' ); ?>
                    </label>
                    <textarea id="wagy-bc-phones" rows="10" class="large-text"
                        placeholder="<?php esc_attr_e( "628123456789; Name; Extra Data\n628987654321; Another Name", 'wagy-connect' ); ?>"></textarea>
                    <p class="description">
                        <?php esc_html_e( 'One entry per line. Format: phone; [1] field; [2] field; ...', 'wagy-connect' ); ?><br>
                        <em><?php esc_html_e( 'Example: 628123456789; Budi Santoso; 25 April 2026', 'wagy-connect' ); ?></em>
                    </p>
                    <p id="wagy-bc-count" style="color:#646970;font-size:12px;"></p>
                </div>
            </div>

            <!-- ====== Send Button & Progress ====== -->
            <div style="margin-top:20px;">
                <button type="button" id="wagy-bc-send" class="button button-primary button-large">
                    <?php esc_html_e( 'Send Broadcast', 'wagy-connect' ); ?>
                </button>
                <span id="wagy-bc-send-spinner" class="spinner" style="float:none;vertical-align:middle;margin-left:8px;"></span>
            </div>

            <div id="wagy-bc-notice" style="margin-top:16px;display:none;"></div>

            <!-- Results log -->
            <div id="wagy-bc-results" style="margin-top:20px;display:none;">
                <h3><?php esc_html_e( 'Send Results', 'wagy-connect' ); ?></h3>
                <table class="widefat striped" style="max-width:720px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Phone', 'wagy-connect' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'wagy-connect' ); ?></th>
                            <th><?php esc_html_e( 'Info', 'wagy-connect' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="wagy-bc-results-body"></tbody>
                </table>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var nonce       = '<?php echo esc_js( wp_create_nonce( 'wagy_broadcast_nonce' ) ); ?>';
            var phonesEl    = document.getElementById('wagy-bc-phones');
            var countEl     = document.getElementById('wagy-bc-count');
            var noticeEl    = document.getElementById('wagy-bc-notice');
            var resultsEl   = document.getElementById('wagy-bc-results');
            var resultsBody = document.getElementById('wagy-bc-results-body');

            /** Count phones on textarea change. */
            phonesEl.addEventListener('input', updateCount);
            function updateCount() {
                var lines = phonesEl.value.split('\n').filter(function(l) {
                    // Count lines where the phone part (before first semicolon) has digits.
                    return l.split(';')[0].replace(/[^0-9]/g, '').length > 0;
                });
                countEl.textContent = lines.length + ' <?php echo esc_js( __( 'recipient(s)', 'wagy-connect' ) ); ?>';
            }

            /** Import users by selected source. */
            var importBtn     = document.getElementById('wagy-bc-import-source');
            var importSpinner = document.getElementById('wagy-bc-import-spinner');
            importBtn.addEventListener('click', function () {
                var sourceVal = document.getElementById('wagy-bc-source').value;

                if (!sourceVal) return;

                importSpinner.classList.add('is-active');
                importBtn.disabled = true;

                var data = new FormData();
                data.append('action', 'wagy_broadcast_get_users');
                data.append('nonce', nonce);
                data.append('source', sourceVal);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(res) {
                        importSpinner.classList.remove('is-active');
                        importBtn.disabled = false;
                        if (res.success && res.data.phones && res.data.phones.length > 0) {
                            var existing = phonesEl.value.trim();
                            // Prevent duplicate lines
                            var currentLines = existing ? existing.split('\n') : [];
                            var newLines = res.data.phones.filter(function(p) {
                                return currentLines.indexOf(p) === -1;
                            });

                            if (newLines.length > 0) {
                                phonesEl.value = existing ? existing + '\n' + newLines.join('\n') : newLines.join('\n');
                                updateCount();
                                showNotice(
                                    res.data.count + ' <?php echo esc_js( __( 'number(s) imported from:', 'wagy-connect' ) ); ?> ' + res.data.source,
                                    'success'
                                );
                            } else {
                                showNotice('<?php echo esc_js( __( 'All numbers from this source are already in the list.', 'wagy-connect' ) ); ?>', 'warning');
                            }
                        } else {
                            showNotice(
                                '<?php echo esc_js( __( 'No valid numbers found in selected source.', 'wagy-connect' ) ); ?>',
                                'warning'
                            );
                        }
                    })
                    .catch(function() {
                        importSpinner.classList.remove('is-active');
                        importBtn.disabled = false;
                        showNotice('<?php echo esc_js( __( 'Import failed. Please try again.', 'wagy-connect' ) ); ?>', 'error');
                    });
            });

            /** Send broadcast in batches of 25. */
            var sendBtn     = document.getElementById('wagy-bc-send');
            var sendSpinner = document.getElementById('wagy-bc-send-spinner');
            var BATCH_SIZE  = 25;

            sendBtn.addEventListener('click', function () {
                // Send raw lines (may contain 'phone;field1;field2') — backend handles parsing.
                var phones = phonesEl.value.split('\n')
                    .map(function(p) { return p.trim(); })
                    .filter(function(p) {
                        // Keep lines where the phone part (before first ;) has digits.
                        return p.split(';')[0].replace(/[^0-9]/g, '').length > 0;
                    });

                if (phones.length === 0) {
                    showNotice('<?php echo esc_js( __( 'Please enter at least one phone number.', 'wagy-connect' ) ); ?>', 'error');
                    return;
                }

                var message  = document.getElementById('wagy-bc-message').value.trim();
                if (!message) {
                    showNotice('<?php echo esc_js( __( 'Message cannot be empty.', 'wagy-connect' ) ); ?>', 'error');
                    return;
                }

                sendBtn.disabled = true;
                sendSpinner.classList.add('is-active');
                noticeEl.style.display = 'none';
                resultsEl.style.display = 'none';
                resultsBody.innerHTML = '';

                var mediaUrl      = document.getElementById('wagy-bc-media-url').value.trim();
                var expiresIn     = document.getElementById('wagy-bc-expires-in').value;
                var retryInterval = document.getElementById('wagy-bc-retry').value;

                var allResults   = [];
                var batches      = [];
                for (var i = 0; i < phones.length; i += BATCH_SIZE) {
                    batches.push(phones.slice(i, i + BATCH_SIZE));
                }

                /**
                 * Sends batches sequentially to avoid overloading the server.
                 *
                 * @param {number} idx - Current batch index.
                 */
                function sendBatch(idx) {
                    if (idx >= batches.length) {
                        // All done.
                        sendBtn.disabled = false;
                        sendSpinner.classList.remove('is-active');
                        var sent   = allResults.filter(function(r) { return r.success; }).length;
                        var failed = allResults.length - sent;
                        showNotice(
                            '<?php echo esc_js( __( 'Broadcast complete.', 'wagy-connect' ) ); ?> ' +
                            sent + ' <?php echo esc_js( __( 'queued', 'wagy-connect' ) ); ?>, ' +
                            failed + ' <?php echo esc_js( __( 'failed', 'wagy-connect' ) ); ?>.',
                            failed > 0 ? 'warning' : 'success'
                        );
                        resultsEl.style.display = '';
                        return;
                    }

                    var data = new FormData();
                    data.append('action', 'wagy_broadcast_send_batch');
                    data.append('nonce', nonce);
                    data.append('message', message);
                    data.append('media_url', mediaUrl);
                    data.append('expires_in', expiresIn);
                    data.append('retry_interval', retryInterval);
                    batches[idx].forEach(function(p) { data.append('phones[]', p); });

                    fetch(ajaxurl, { method: 'POST', body: data })
                        .then(function(r) { return r.json(); })
                        .then(function(res) {
                            if (res.success && res.data.results) {
                                res.data.results.forEach(function(r) {
                                    allResults.push(r);
                                    var row = '<tr>' +
                                        '<td>' + (r.label || r.phone) + '</td>' +
                                        '<td>' + (r.success
                                            ? '<span style="color:#00a32a;font-weight:600;">&#10003; <?php echo esc_js( __( 'Queued', 'wagy-connect' ) ); ?></span>'
                                            : '<span style="color:#d63638;font-weight:600;">&#10007; <?php echo esc_js( __( 'Failed', 'wagy-connect' ) ); ?></span>') +
                                        '</td>' +
                                        '<td>' + (r.message || '') + '</td>' +
                                        '</tr>';
                                    resultsBody.innerHTML += row;
                                });
                                resultsEl.style.display = '';
                            }
                            sendBatch(idx + 1);
                        })
                        .catch(function() {
                            sendBtn.disabled = false;
                            sendSpinner.classList.remove('is-active');
                            showNotice('<?php echo esc_js( __( 'Network error during batch send. Some messages may not have been queued.', 'wagy-connect' ) ); ?>', 'error');
                        });
                }

                sendBatch(0);
            });

            /**
             * Shows a notice block above the results table.
             *
             * @param {string} msg   - The message to display.
             * @param {string} type  - 'success', 'warning', or 'error'.
             */
            function showNotice(msg, type) {
                noticeEl.className = 'notice notice-' + type;
                noticeEl.innerHTML = '<p>' + msg + '</p>';
                noticeEl.style.display = '';
            }

            updateCount();
        });
        </script>
        <?php
    }
}

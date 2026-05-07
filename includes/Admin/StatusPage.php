<?php
namespace Wagy\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;

/**
 * Registers and renders the main "Wagy Status & Quota" admin page.
 *
 * Displays the current WhatsApp device connection status, an inline QR code
 * for pairing, and a visual quota dashboard.
 *
 * @package Wagy\Admin
 */
final class StatusPage {

    /**
     * Hooks into WordPress to register the admin menu entry.
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'wp_ajax_wagy_refresh_qr', [ $this, 'ajax_refresh_qr' ] );
        add_action( 'wp_ajax_wagy_redeem_voucher', [ $this, 'ajax_redeem_voucher' ] );
    }

    /**
     * Registers the top-level "Wagy" menu page in the WordPress admin sidebar.
     *
     * @return void
     */
    public function add_admin_menu() {
        // Access capability is dynamically handled by AccessControl.
        $capability = 'wagy_access_status';
        add_menu_page(
            __( 'Wagy Status', 'wagy-connect' ),
            __( 'Wagy', 'wagy-connect' ),
            $capability,
            'wagy-status',
            [ $this, 'status_page_html' ],
            'dashicons-whatsapp',
            80
        );
    }

    /**
     * Renders the full HTML for the Status page.
     *
     * On first load of this page, the status cache is invalidated so the
     * admin always sees fresh data.
     *
     * @return void
     */
    public function status_page_html() {
        if ( ! Wagy::is_configured() ) {
            wp_safe_redirect( admin_url( 'admin.php?page=wagy-settings' ) );
            exit;
        }

        // Always show fresh data on the Status page itself.
        Wagy::invalidate_status_cache();

        ?>
        <style>
        .wagy-quota-bar-wrap { background: #e0e0e0; border-radius: 6px; height: 16px; overflow: hidden; margin: 6px 0 4px; }
        .wagy-quota-bar      { height: 100%; border-radius: 6px; transition: width .4s ease; }
        .wagy-quota-bar.ok   { background: #00a32a; }
        .wagy-quota-bar.warn { background: #dba617; }
        .wagy-quota-bar.crit { background: #d63638; }
        .wagy-badge          { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 10px; text-transform: uppercase; vertical-align: middle; }
        .wagy-badge.active   { background: #00a32a; color: #fff; }
        .wagy-badge.inactive { background: #8c8f94; color: #fff; }
        .wagy-pro-voucher    { border: 1px solid #e0e0e0; border-radius: 4px; padding: 10px 14px; margin-bottom: 10px; }
        .wagy-pro-voucher:last-child { margin-bottom: 0; }
        </style>
        <div class="wrap">
            <h1><?php esc_html_e( 'Wagy Status & Quota', 'wagy-connect' ); ?></h1>
            <?php $this->render_status(); ?>
        </div>
        <?php
    }

    /**
     * Fetches and renders the device connection status and quota dashboard.
     *
     * @return void
     */
    private function render_status() {
        $status = Wagy::get_device_status();
        if ( $status['status'] !== 'success' ) {
            return;
        }

        $data      = $status['data'];
        $logged_in = ! empty( $data['logged_in'] );

        if ( $logged_in ) {
            echo '<div class="notice notice-success inline"><p>'
                . sprintf( esc_html__( 'WhatsApp is connected (%s).', 'wagy-connect' ), esc_html( $data['wa_user'] ?? $data['user'] ?? '' ) )
                . '</p></div>';
            $this->render_quota();
            return;
        }

        echo '<div class="notice notice-warning inline"><p>'
            . esc_html__( 'WhatsApp is disconnected. Please scan the QR code below to connect.', 'wagy-connect' )
            . '</p></div>';

        $this->render_qr_card();
    }

    /**
     * Fetches and renders the QR code for pairing/reconnection.
     *
     * @return void
     */
    private function render_qr_card() {
        echo '<div style="margin-top:20px; max-width:300px; text-align:center;">';
        echo '<div id="wagy-qr-image-wrap" style="min-height: 250px; display: flex; align-items: center; justify-content: center; position: relative; border: 1px solid #ddd; background: #fff; border-radius: 4px;">';
        echo '<span class="spinner" style="position:absolute;"></span>';
        echo '<div id="wagy-qr-placeholder"></div>';
        echo '</div>';
        echo '<button type="button" id="wagy-refresh-qr-btn" class="button" style="margin-top:10px; width:100%;">'
            . esc_html__( 'Refresh QR Code', 'wagy-connect' )
            . '</button>';
        echo '</div>';

        $this->render_qr_script();
    }

    /**
     * AJAX handler to refresh QR code.
     */
    public function ajax_refresh_qr() {
        check_ajax_referer( 'wagy_status_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $qr = Wagy::get_qr_code();
        if ( $qr['status'] === 'success' && !empty($qr['data']['qr_code']) ) {
            wp_send_json_success( [ 'qr_code' => $qr['data']['qr_code'] ] );
        } else {
            wp_send_json_error( [ 'message' => $qr['message'] ?? 'Failed to load QR' ] );
        }
    }

    /**
     * Renders the JS for AJAX QR refresh.
     */
    private function render_qr_script() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var btn = document.getElementById('wagy-refresh-qr-btn');
            var wrap = document.getElementById('wagy-qr-image-wrap');
            var placeholder = document.getElementById('wagy-qr-placeholder');
            var spinner = wrap.querySelector('.spinner');
            var nonce = '<?php echo esc_js( wp_create_nonce( "wagy_status_nonce" ) ); ?>';

            function fetchQR() {
                if (!btn) return;
                btn.disabled = true;
                spinner.classList.add('is-active');
                
                var data = new FormData();
                data.append('action', 'wagy_refresh_qr');
                data.append('nonce', nonce);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(r => r.json())
                    .then(res => {
                        spinner.classList.remove('is-active');
                        btn.disabled = false;
                        if (res.success) {
                            placeholder.innerHTML = '<img src="' + res.data.qr_code + '" style="width:100%; height:auto; display:block;">';
                        } else {
                            placeholder.innerHTML = '<p style="color:#d63638; padding:20px;">' + res.data.message + '</p>';
                        }
                    })
                    .catch(() => {
                        spinner.classList.remove('is-active');
                        btn.disabled = false;
                        placeholder.innerHTML = '<p style="color:#d63638; padding:20px;">Network Error</p>';
                    });
            }

            if (btn) {
                btn.addEventListener('click', fetchQR);
                fetchQR(); // Initial load
            }
        });
        </script>
        <?php
    }

    /**
     * Fetches and renders the visual quota dashboard.
     *
     * @return void
     */
    private function render_quota() {
        $quota = Wagy::get_device_quota();

        if ( ! isset( $quota['status'] ) || $quota['status'] !== 'success' ) {
            echo '<div class="notice notice-error inline"><p>'
                . esc_html(
                    sprintf(
                        /* translators: %s: error message */
                        __( 'Failed to fetch quota data: %s', 'wagy-connect' ),
                        $quota['message'] ?? __( 'Unknown error', 'wagy-connect' )
                    )
                )
                . '</p></div>';
            return;
        }

        $data    = $quota['data'] ?? [];
        $summary = $data['summary'] ?? [];
        
        $free_sum = $summary['free_quota'] ?? [];
        $pro_sum  = $summary['active_pro_quota'] ?? [];
        
        $free_rem = (int)( $free_sum['remaining'] ?? 0 );
        $pro_rem  = (int)( $pro_sum['remaining'] ?? 0 );

        echo '<div class="card" style="max-width:620px;padding:20px;margin-top:20px;">';
        echo '<h3>' . esc_html__( 'Quota Dashboard', 'wagy-connect' ) . '</h3>';

        if ( $free_rem <= 0 && $pro_rem <= 0 ) {
            echo '<div class="notice notice-error inline" style="margin:0;"><p>'
                . esc_html__( 'No active quota. Messages will stay PENDING until quota is available.', 'wagy-connect' )
                . '</p></div>';
        } else {
            echo '<div class="notice notice-info inline" style="margin:0;"><p>'
                . esc_html__( 'Quota is available and ready for use.', 'wagy-connect' )
                . '</p></div>';
        }

        // --- Summary Stats ---
        echo '<div style="display:flex; gap:20px; margin: 20px 0; padding: 15px; background: #f6f7f7; border-radius: 4px;">';
        echo '<div style="flex:1;"><strong>' . esc_html__( 'Free Balance:', 'wagy-connect' ) . '</strong><br><span style="font-size:18px;">' . number_format_i18n( $free_rem ) . '</span></div>';
        echo '<div style="flex:1;"><strong>' . esc_html__( 'PRO Balance:', 'wagy-connect' ) . '</strong><br><span style="font-size:18px;">' . number_format_i18n( $pro_rem ) . '</span></div>';
        echo '</div>';

        // --- All Vouchers List ---
        $vouchers = $data['all_vouchers'] ?? [];
        if ( ! empty( $vouchers ) ) {
            echo '<h4 style="margin-bottom:12px;">' . esc_html__( 'Active Plans & Vouchers', 'wagy-connect' ) . '</h4>';
            
            foreach ( $vouchers as $v ) {
                $type      = $v['type'] ?? 'pro';
                $total     = max( 1, (int) ( $v['total_quota'] ?? 0 ) );
                $used      = (int) ( $v['used_quota'] ?? 0 );
                $remaining = max( 0, $total - $used );
                $pct       = (int) round( ( $remaining / $total ) * 100 );
                $bar_class = $pct >= 50 ? 'ok' : ( $pct >= 20 ? 'warn' : 'crit' );
                
                $expires_at = $v['expires_at'] ?? $v['reset_at'] ?? '';
                $expires_local = $expires_at ? Wagy::convert_utc_to_wptz( $expires_at ) : '--';

                echo '<div class="wagy-pro-voucher">';
                echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:5px;">';
                echo '<div>';
                echo '<strong style="text-transform:uppercase;">' . esc_html( $v['code'] ?? 'PLAN' ) . '</strong> ';
                echo '<span class="wagy-badge ' . ( $type === 'free' ? 'inactive' : 'active' ) . '">' . esc_html( $type ) . '</span>';
                echo '</div>';
                echo '<span style="font-size:12px;color:#646970;">' . esc_html( $expires_local ) . '</span>';
                echo '</div>';
                
                echo '<div class="wagy-quota-bar-wrap"><div class="wagy-quota-bar ' . esc_attr( $bar_class ) . '" style="width:' . esc_attr( $pct ) . '%"></div></div>';
                echo '<small>' . esc_html(
                    sprintf(
                        /* translators: 1: remaining, 2: total, 3: percentage */
                        __( '%1$s / %2$s messages remaining (%3$s%%)', 'wagy-connect' ),
                        number_format_i18n( $remaining ),
                        number_format_i18n( $total ),
                        $pct
                    )
                ) . '</small>';
                echo '</div>';
            }
        }

        echo '</div>'; // .card

        // --- Voucher Redemption ---
        echo '<div class="card" style="max-width:620px;padding:20px;margin-top:20px;">';
        echo '<h3>' . esc_html__( 'Redeem PRO Voucher', 'wagy-connect' ) . '</h3>';
        echo '<p class="description">' . esc_html__( 'Enter your Wagy PRO voucher code to add quota to this device.', 'wagy-connect' ) . '</p>';
        echo '<div id="wagy-redeem-notice" style="display:none; margin: 10px 0;"></div>';
        echo '<div style="display:flex; gap:10px; margin-top:15px;">';
        echo '<input type="text" id="wagy-voucher-code" class="regular-text" style="flex-grow:1;" placeholder="WAGY-PRO-XXXX-XXXX" />';
        echo '<button type="button" id="wagy-redeem-btn" class="button button-primary">' . esc_html__( 'Redeem', 'wagy-connect' ) . '</button>';
        echo '<span class="spinner" id="wagy-redeem-spinner" style="float:none; vertical-align:middle;"></span>';
        echo '</div>';
        echo '</div>';

        $this->render_redeem_script();
    }

    /**
     * AJAX handler to redeem voucher.
     */
    public function ajax_redeem_voucher() {
        check_ajax_referer( 'wagy_status_nonce', 'nonce' );
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Permission denied' ] );
        }

        $code = strtoupper( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
        if ( empty( $code ) ) {
            wp_send_json_error( [ 'message' => 'Voucher code is required' ] );
        }

        $res = Wagy::redeem_voucher( $code );
        if ( $res['status'] === 'success' ) {
            Wagy::invalidate_status_cache();
            wp_send_json_success( [ 'message' => $res['message'] ?? 'Voucher redeemed successfully!' ] );
        } else {
            wp_send_json_error( [ 'message' => $res['message'] ?? 'Failed to redeem voucher' ] );
        }
    }

    /**
     * Renders JS for voucher redemption.
     */
    private function render_redeem_script() {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var btn = document.getElementById('wagy-redeem-btn');
            var input = document.getElementById('wagy-voucher-code');
            var spinner = document.getElementById('wagy-redeem-spinner');
            var notice = document.getElementById('wagy-redeem-notice');
            var nonce = '<?php echo esc_js( wp_create_nonce( "wagy_status_nonce" ) ); ?>';

            if (!btn) return;

            btn.addEventListener('click', function() {
                var code = input.value.trim();
                if (!code) return;

                btn.disabled = true;
                spinner.classList.add('is-active');
                notice.style.display = 'none';

                var data = new FormData();
                data.append('action', 'wagy_redeem_voucher');
                data.append('nonce', nonce);
                data.append('code', code);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(r => r.json())
                    .then(res => {
                        spinner.classList.remove('is-active');
                        btn.disabled = false;
                        
                        notice.className = 'notice ' + (res.success ? 'notice-success' : 'notice-error') + ' inline';
                        notice.innerHTML = '<p>' + (res.success ? res.data.message : res.data.message) + '</p>';
                        notice.style.display = 'block';

                        if (res.success) {
                            input.value = '';
                            setTimeout(() => window.location.reload(), 2000);
                        }
                    })
                    .catch(() => {
                        spinner.classList.remove('is-active');
                        btn.disabled = false;
                        notice.className = 'notice notice-error inline';
                        notice.innerHTML = '<p>Network Error</p>';
                        notice.style.display = 'block';
                    });
            });
        });
        </script>
        <?php
    }
}

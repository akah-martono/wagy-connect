<?php
namespace Wagy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;

/**
 * Handles WooCommerce integration for Wagy Connect.
 * 
 * Listens to WooCommerce order status changes and sends automated
 * WhatsApp notifications based on user-defined templates.
 * 
 * @package Wagy\Integrations
 */
class WooCommerce {

    /**
     * Option key for WooCommerce templates.
     */
    const TEMPLATES_OPTION = 'wagy_woo_templates';

    /**
     * Option key for enabled integrations.
     */
    const ENABLED_INTEGRATIONS = 'wagy_enabled_integrations';

    public function __construct() {
        // Always register the menu so it's available to admins.
        add_action( 'admin_menu', [ $this, 'add_settings_menu' ] );

        if ( ! $this->is_enabled() ) {
            return;
        }

        // Order Status Hooks
        add_action( 'woocommerce_order_status_pending', [ $this, 'handle_order_status_change' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'handle_order_status_change' ], 10, 1 );
        add_action( 'woocommerce_order_status_on-hold', [ $this, 'handle_order_status_change' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'handle_order_status_change' ], 10, 1 );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'handle_order_status_change' ], 10, 1 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'handle_order_status_change' ], 10, 1 );
        add_action( 'woocommerce_order_status_failed', [ $this, 'handle_order_status_change' ], 10, 1 );
    }

    /**
     * Checks if the WooCommerce integration is enabled in Wagy and if WooCommerce is active.
     */
    public function is_enabled() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return false;
        }

        $enabled_integrations = get_option( self::ENABLED_INTEGRATIONS, [] );
        return is_array( $enabled_integrations ) && in_array( 'woocommerce', $enabled_integrations );
    }

    /**
     * Registers a sub-menu page for WooCommerce settings.
     */
    public function add_settings_menu() {
        add_submenu_page(
            'wagy-status',
            __( 'Wagy WooCommerce Settings', 'wagy-connect' ),
            __( 'WooCommerce', 'wagy-connect' ),
            'wagy_access_status',
            'wagy-settings-woocommerce',
            [ $this, 'render_settings_page' ]
        );

        // Hide the menu item from sidebar via CSS to keep the UI clean.
        add_action( 'admin_head', function() {
            echo '<style>
                #adminmenu .wp-has-submenu.toplevel_page_wagy-status ul li a[href*="wagy-settings-woocommerce"],
                #adminmenu .wp-has-submenu.toplevel_page_wagy-status ul li a[href*="wagy-settings-woocommerce"] + li {
                    display: none !important;
                }
            </style>';
        } );
    }

    /**
     * Handles order status changes and sends notifications.
     */
    public function handle_order_status_change( $order_id ) {
        $order     = wc_get_order( $order_id );
        $status    = $order->get_status();
        $templates = get_option( self::TEMPLATES_OPTION, [] );

        if ( empty( $templates[ $status ]['enabled'] ) || empty( $templates[ $status ]['message'] ) ) {
            return;
        }

        $phone   = $order->get_billing_phone();
        $message = $this->parse_template( $templates[ $status ]['message'], $order );
        if ( ! empty( $phone ) && ! empty( $message ) ) {
            Wagy::send_message( [
                'phone'   => $phone,
                'message' => $message,
            ] );
        }
    }

    /**
     * Parses a template by replacing placeholders with order data.
     */
    protected function parse_template( $template, $order ) {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            $items[] = $item->get_name() . ' (x' . $item->get_quantity() . ')';
        }

        $placeholders = [
            '{first_name}'     => $order->get_billing_first_name(),
            '{last_name}'      => $order->get_billing_last_name(),
            '{full_name}'      => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '{order_id}'       => $order->get_id(),
            '{order_number}'   => $order->get_order_number(),
            '{order_total}'    => wc_price( $order->get_total(), [ 'currency' => $order->get_currency() ] ),
            '{order_status}'   => wc_get_order_status_name( $order->get_status() ),
            '{payment_method}' => $order->get_payment_method_title(),
            '{items_list}'     => implode( ", ", $items ),
            '{site_title}'     => get_bloginfo( 'name' ),
            '{site_url}'       => site_url(),
        ];

        // Strip HTML from price if needed (WhatsApp is text only)
        $placeholders['{order_total}'] = html_entity_decode( strip_tags( $placeholders['{order_total}'] ) );

        return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $template );
    }

    /**
     * Renders the WooCommerce Integration settings page.
     */
    public function render_settings_page() {
        $templates = get_option( self::TEMPLATES_OPTION, $this->get_default_templates() );
        $statuses  = wc_get_order_statuses();
        
        // Handle Save
        if ( isset( $_POST['wagy_save_woo_templates'] ) && check_admin_referer( 'wagy_woo_templates_nonce' ) ) {
            $new_templates = [];
            foreach ( $statuses as $slug => $label ) {
                $status_key = str_replace( 'wc-', '', $slug );
                $new_templates[ $status_key ] = [
                    'enabled' => isset( $_POST['templates'][ $status_key ]['enabled'] ),
                    'message' => sanitize_textarea_field( wp_unslash( $_POST['templates'][ $status_key ]['message'] ?? '' ) ),
                ];
            }
            update_option( self::TEMPLATES_OPTION, $new_templates );
            $templates = $new_templates;
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wagy-connect' ) . '</p></div>';
        }

        ?>
        <div class="wrap wagy-woo-settings">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'WooCommerce WhatsApp Notifications', 'wagy-connect' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wagy-integrations' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Integrations', 'wagy-connect' ); ?></a>
            <hr class="wp-header-end">

            <div class="wagy-settings-container" style="display: flex; margin-top: 20px; background: #fff; border: 1px solid #ccd0d4; min-height: 500px;">
                <!-- Vertical Sidebar -->
                <div class="wagy-settings-sidebar" style="width: 250px; border-right: 1px solid #ccd0d4; background: #f6f7f7;">
                    <ul class="wagy-nav-list" style="margin: 0; padding: 0; list-style: none;">
                        <?php foreach ( $statuses as $slug => $label ) : 
                            $status_key = str_replace( 'wc-', '', $slug );
                        ?>
                            <li style="margin: 0; border-bottom: 1px solid #ccd0d4;">
                                <a href="#status-<?php echo esc_attr( $status_key ); ?>" class="wagy-nav-item" style="display: block; padding: 15px 20px; text-decoration: none; color: #1d2327; font-weight: 500; border-left: 4px solid transparent;" data-target="status-<?php echo esc_attr( $status_key ); ?>">
                                    <?php echo esc_html( $label ); ?>
                                    <?php if ( ! empty( $templates[ $status_key ]['enabled'] ) ) : ?>
                                        <span class="dashicons dashicons-yes" style="float: right; color: #46b450; font-size: 18px; margin-top: 2px;"></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Settings Content -->
                <div class="wagy-settings-content" style="flex: 1; padding: 30px;">
                    <form method="post">
                        <?php wp_nonce_field( 'wagy_woo_templates_nonce' ); ?>
                        
                        <?php foreach ( $statuses as $slug => $label ) : 
                            $status_key = str_replace( 'wc-', '', $slug );
                        ?>
                            <div id="status-<?php echo esc_attr( $status_key ); ?>" class="wagy-status-panel" style="display: none;">
                                <h2 style="margin-top: 0; display: flex; align-items: center; justify-content: space-between;">
                                    <?php echo esc_html( $label ); ?> <?php esc_html_e( 'Template', 'wagy-connect' ); ?>
                                    <label class="wagy-switch" style="font-size: 14px; font-weight: normal; display: flex; align-items: center;">
                                        <input type="checkbox" name="templates[<?php echo esc_attr( $status_key ); ?>][enabled]" value="1" <?php checked( ! empty( $templates[ $status_key ]['enabled'] ) ); ?>>
                                        <span style="margin-left: 10px;"><?php esc_html_e( 'Enable for this status', 'wagy-connect' ); ?></span>
                                    </label>
                                </h2>
                                <hr>
                                
                                <div style="margin-top: 20px;">
                                    <textarea name="templates[<?php echo esc_attr( $status_key ); ?>][message]" rows="10" style="width: 100%; font-family: monospace; padding: 15px; font-size: 14px; border-radius: 4px;" placeholder="<?php esc_attr_e( 'Enter WhatsApp message template...', 'wagy-connect' ); ?>"><?php echo esc_textarea( $templates[ $status_key ]['message'] ?? '' ); ?></textarea>
                                </div>

                                <div class="wagy-placeholders" style="margin-top: 20px; background: #f0f0f1; padding: 15px; border-radius: 4px;">
                                    <p style="margin-top: 0; font-weight: 600;"><?php esc_html_e( 'Available Placeholders (Click to insert):', 'wagy-connect' ); ?></p>
                                    <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                        <?php 
                                        $p_list = [ '{first_name}', '{last_name}', '{full_name}', '{order_id}', '{order_number}', '{order_total}', '{order_status}', '{payment_method}', '{items_list}', '{site_title}' ];
                                        foreach ( $p_list as $p ) : ?>
                                            <code class="wagy-p-chip" style="cursor: pointer; padding: 4px 8px; background: #fff; border: 1px solid #ccd0d4; border-radius: 3px;" data-p="<?php echo esc_attr( $p ); ?>"><?php echo esc_html( $p ); ?></code>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div style="margin-top: 30px; border-top: 1px solid #ccd0d4; padding-top: 20px;">
                            <input type="submit" name="wagy_save_woo_templates" class="button button-primary button-large" value="<?php esc_attr_e( 'Save All Templates', 'wagy-connect' ); ?>">
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.wagy-nav-item').on('click', function(e) {
                e.preventDefault();
                var target = $(this).data('target');
                
                $('.wagy-nav-item').css({
                    'background': 'transparent',
                    'border-left-color': 'transparent',
                    'color': '#1d2327'
                });
                
                $(this).css({
                    'background': '#fff',
                    'border-left-color': '#2271b1',
                    'color': '#2271b1'
                });

                $('.wagy-status-panel').hide();
                $('#' + target).show();
                
                // Store active status in session storage
                sessionStorage.setItem('wagy_woo_active_status', target);
            });

            // Placeholder insertion
            $('.wagy-p-chip').on('click', function() {
                var p = $(this).data('p');
                var $textarea = $(this).closest('.wagy-status-panel').find('textarea');
                var cursorPos = $textarea.prop('selectionStart');
                var v = $textarea.val();
                var textBefore = v.substring(0, cursorPos);
                var textAfter = v.substring(cursorPos, v.length);

                $textarea.val(textBefore + p + textAfter);
                $textarea.focus();
                $textarea[0].setSelectionRange(cursorPos + p.length, cursorPos + p.length);
            });

            // Initialize
            var activeStatus = sessionStorage.getItem('wagy_woo_active_status') || 'status-pending';
            $('.wagy-nav-item[data-target="' + activeStatus + '"]').trigger('click');
        });
        </script>
        <?php
    }

    /**
     * Provides default templates for WooCommerce statuses.
     */
    protected function get_default_templates() {
        return [
            'pending'    => [ 'enabled' => false, 'message' => "Halo {first_name},\n\nPesanan #{order_id} kamu sudah kami terima. Segera lakukan pembayaran sebesar {order_total} via {payment_method} agar pesanan bisa kami proses.\n\nTerima kasih!" ],
            'processing' => [ 'enabled' => false, 'message' => "Halo {first_name},\n\nPembayaran untuk pesanan #{order_id} sudah kami terima. Pesananmu sedang kami siapkan.\n\nDetail: {items_list}" ],
            'on-hold'    => [ 'enabled' => false, 'message' => "Halo {first_name},\n\nPesanan #{order_id} kamu sedang kami tahan (On Hold). Kami akan segera menghubungimu kembali untuk informasi lebih lanjut." ],
            'completed'  => [ 'enabled' => false, 'message' => "Halo {first_name},\n\nPesanan #{order_id} kamu sudah selesai dikirim. Semoga suka dengan produknya ya!\n\nJangan lupa berikan ulasan di {site_url}." ],
        ];
    }
}

<?php
namespace Wagy\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the "Integrations Hub" page for Wagy Connect.
 * 
 * This page serves as a gallery of available integrations (e.g. WooCommerce, Fluent Forms).
 * Users can enable/disable integrations and access their specific settings from here.
 * 
 * @package Wagy\Admin
 */
final class IntegrationsPage {

    /**
     * Option name for storing enabled integrations.
     */
    const OPTION_NAME = 'wagy_enabled_integrations';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_wagy_toggle_integration', [ $this, 'ajax_toggle_integration' ] );
    }

    /**
     * Handles AJAX toggle for integrations.
     */
    public function ajax_toggle_integration() {
        check_ajax_referer( 'wagy_integration_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $id      = sanitize_text_field( $_POST['integration_id'] ?? '' );
        $enabled = ! empty( $_POST['enabled'] );
        
        $current = get_option( self::OPTION_NAME, [] );
        if ( ! is_array( $current ) ) {
            $current = [];
        }

        if ( $enabled ) {
            if ( ! in_array( $id, $current ) ) {
                $current[] = $id;
            }
        } else {
            $current = array_diff( $current, [ $id ] );
        }

        update_option( self::OPTION_NAME, array_values( $current ) );
        wp_send_json_success();
    }

    /**
     * Registers the "Integrations" sub-menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'wagy-status',
            __( 'Integrations Hub', 'wagy-connect' ),
            __( 'Integrations', 'wagy-connect' ),
            'manage_options',
            'wagy-integrations',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Registers settings for integrations.
     */
    public function register_settings() {
        register_setting( 'wagy_integrations_group', self::OPTION_NAME );
    }

    /**
     * Returns an array of available integrations.
     */
    public static function get_available_integrations() {
        return [
            'woocommerce' => [
                'name'        => 'WooCommerce',
                'description' => __( 'Send automated WhatsApp notifications for order status, payment reminders, and stock alerts.', 'wagy-connect' ),
                'icon'        => 'dashicons-cart',
                'is_active'   => class_exists( 'WooCommerce' ),
                'setup_url'   => admin_url( 'admin.php?page=wagy-settings-woocommerce' ),
            ],
            'fluentforms' => [
                'name'        => 'Fluent Forms',
                'description' => __( 'Connect your forms to WhatsApp. Send submission alerts to admins or confirmation to users.', 'wagy-connect' ),
                'icon'        => 'dashicons-feedback',
                'is_active'   => defined( 'FLUENTFORM' ),
                'setup_url'   => admin_url( 'admin.php?page=fluent_forms_settings#wagy_whatsapp' ),
            ],
        ];
    }

    /**
     * Renders the Integrations Hub HTML.
     */
    public function render_page() {
        $integrations = self::get_available_integrations();
        $enabled      = get_option( self::OPTION_NAME, [] );
        if ( ! is_array( $enabled ) ) {
            $enabled = [];
        }
        ?>
        <div class="wrap wagy-integrations-hub">
            <h1><?php esc_html_e( 'Integrations Hub', 'wagy-connect' ); ?></h1>
            <p class="description">
                <?php esc_html_e( 'Extend Wagy Connect functionality by connecting with your favorite WordPress plugins.', 'wagy-connect' ); ?>
            </p>

            <div class="wagy-integrations-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                <?php foreach ( $integrations as $id => $data ) : ?>
                    <div class="card wagy-integration-card" style="margin: 0; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; border-radius: 8px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <div>
                            <div style="display: flex; align-items: center; margin-bottom: 15px;">
                                <span class="dashicons <?php echo esc_attr( $data['icon'] ); ?>" style="font-size: 32px; width: 32px; height: 32px; margin-right: 12px; color: #2271b1;"></span>
                                <h2 style="margin: 0; font-size: 1.3em;"><?php echo esc_html( $data['name'] ); ?></h2>
                            </div>
                            <p style="min-height: 3em; margin-bottom: 20px; color: #646970;">
                                <?php echo esc_html( $data['description'] ); ?>
                            </p>
                        </div>
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; border-top: 1px solid #f0f0f1; padding-top: 15px;">
                            <?php if ( $data['is_active'] ) : ?>
                                <?php 
                                $is_enabled = in_array( $id, $enabled );
                                ?>
                                <div class="wagy-toggle-wrapper">
                                    <label class="wagy-switch" style="position: relative; display: inline-block; width: 40px; height: 20px;">
                                        <input type="checkbox" class="wagy-integration-toggle" data-id="<?php echo esc_attr( $id ); ?>" <?php checked( $is_enabled ); ?> style="opacity: 0; width: 0; height: 0;">
                                        <span class="wagy-slider" style="position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: <?php echo $is_enabled ? '#46b450' : '#ccc'; ?>; transition: .4s; border-radius: 20px;">
                                            <span style="position: absolute; content: ''; height: 16px; width: 16px; left: 2px; bottom: 2px; background-color: white; transition: .4s; border-radius: 50%; transform: <?php echo $is_enabled ? 'translateX(20px)' : 'translateX(0)'; ?>;"></span>
                                        </span>
                                    </label>
                                    <span style="margin-left: 8px; font-weight: 600; font-size: 12px; vertical-align: middle; color: <?php echo $is_enabled ? '#46b450' : '#646970'; ?>;">
                                        <?php echo $is_enabled ? esc_html__( 'Enabled', 'wagy-connect' ) : esc_html__( 'Disabled', 'wagy-connect' ); ?>
                                    </span>
                                </div>
                                <a href="<?php echo esc_url( $data['setup_url'] ); ?>" class="button <?php echo $is_enabled ? 'button-primary' : 'disabled'; ?>" <?php echo $is_enabled ? '' : 'onclick="return false;"'; ?>>
                                    <?php esc_html_e( 'Configure', 'wagy-connect' ); ?>
                                </a>
                            <?php else : ?>
                                <span style="color: #d63638; font-style: italic; font-size: 12px;">
                                    <?php 
                                    /* translators: %s: Plugin Name */
                                    printf( esc_html__( '%s is not active', 'wagy-connect' ), esc_html( $data['name'] ) ); 
                                    ?>
                                </span>
                                <button class="button disabled" disabled><?php esc_html_e( 'Install Plugin', 'wagy-connect' ); ?></button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.wagy-integration-toggle').on('change', function() {
                var $checkbox = $(this);
                var id = $checkbox.data('id');
                var isEnabled = $checkbox.is(':checked');
                var $slider = $checkbox.siblings('.wagy-slider');
                var $knob = $slider.find('span');
                var $label = $checkbox.parent().siblings('span');
                var $configBtn = $checkbox.closest('.wagy-integration-card').find('.button-primary, .button.disabled');

                // Visual feedback immediately
                if (isEnabled) {
                    $slider.css('background-color', '#46b450');
                    $knob.css('transform', 'translateX(20px)');
                    $label.text('<?php echo esc_js( __( 'Enabled', 'wagy-connect' ) ); ?>').css('color', '#46b450');
                    $configBtn.removeClass('disabled').addClass('button-primary').attr('onclick', '');
                } else {
                    $slider.css('background-color', '#ccc');
                    $knob.css('transform', 'translateX(0)');
                    $label.text('<?php echo esc_js( __( 'Disabled', 'wagy-connect' ) ); ?>').css('color', '#646970');
                    $configBtn.addClass('disabled').removeClass('button-primary').attr('onclick', 'return false;');
                }

                // Save via AJAX
                $.post(ajaxurl, {
                    action: 'wagy_toggle_integration',
                    nonce: '<?php echo esc_js( wp_create_nonce( 'wagy_integration_nonce' ) ); ?>',
                    integration_id: id,
                    enabled: isEnabled ? 1 : 0
                });
            });
        });
        </script>
        <?php
    }
}

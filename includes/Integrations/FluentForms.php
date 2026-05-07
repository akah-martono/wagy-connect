<?php
namespace Wagy\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Wagy\Wagy;
use FluentForm\App\Http\Controllers\IntegrationManagerController;
use FluentForm\Framework\Foundation\Application;

class FluentForms extends IntegrationManagerController
{
    public function __construct(Application $app)
    {
        parent::__construct(
            $app,
            'Wagy WhatsApp',
            'wagy_whatsapp',
            'wagy_whatsapp_settings',
            'wagy_whatsapp_feed',
            10
        );

        $this->description = 'Send WhatsApp message via Wagy';
        $this->logo = '';

        $this->registerAdminHooks();
    }

    /**
     * WAJIB: Merge Fields handler
     */
    public function getMergeFields($list, $listId, $formId) {
        return $list;
    }

    /**
     * Global Settings
     */
    public function getGlobalFields($settings = []) {
        return [
            'menu_title'       => 'Wagy Settings',
            'menu_description' => 'WhatsApp Integration via Wagy',
            'valid_message'    => 'Wagy settings is valid',    
            'invalid_message'  => 'Wagy settings is invalid',                
            'fields'           => []
        ];
    }

    public function getGlobalSettings($settings) {
        return [
            'status' => Wagy::is_token_valid()
        ];
    }

    public function isConfigured() {
        return Wagy::is_token_valid();
    }    

    public function saveGlobalSettings($settings) {
        wp_send_json_success([
            'message' => 'No global settings required',
            'status'  => true
        ]);
    }

    /**
     * Register Integration ke Fluent Forms
     */
    public function pushIntegration($integrations, $formId) {
        $integrations[$this->integrationKey] = [
            'title'                 => 'Wagy WhatsApp',
            'logo'                  => $this->logo,
            'is_active'             => $this->isConfigured(),
            'configure_title'       => 'Configuration required!',
            'global_configure_url'  => admin_url('admin.php?page=fluent_forms_settings#wagy_whatsapp'),
            'configure_message'     => 'Please configure Wagy first',
            'configure_button_text' => 'Configure Wagy'
        ];

        return $integrations;
    }

    /**
     * Default Feed
     */
    public function getIntegrationDefaults($settings, $formId) {
        return [
            'name'       => '',
            'recipient'  => '',
            'message'    => '',
            'media_url'  => '',
            'expired_in' => '',
            'enabled'    => true
        ];
    }

    /**
     * Settings UI
     */
    public function getSettingsFields($settings, $formId) {
        return [
            'fields' => [
                [
                    'key'       => 'name',
                    'label'     => 'Feed Name',
                    'component' => 'text',
                    'required'  => true
                ],
                [
                    'key'         => 'recipient',
                    'label'       => 'WhatsApp Number',
                    'component'   => 'value_text',
                    'required'    => true,
                    'placeholder' => '628xxxx atau {inputs.phone}'
                ],
                [
                    'key'         => 'message',
                    'label'       => 'Message',
                    'component'   => 'value_textarea',
                    'required'    => true,
                    'placeholder' => 'Halo {inputs.name}'
                ],
                [
                    'key'       => 'media_url',
                    'label'     => 'Media URL (optional)',
                    'component' => 'value_text'
                ],
                [
                    'key'       => 'expired_in',
                    'label'     => 'Expires In (hours, optional)',
                    'component' => 'number'
                ],
                [
                    'key'            => 'enabled',
                    'label'          => 'Status',
                    'component'      => 'checkbox-single',
                    'checkbox_label' => 'Enable'
                ]
            ],
            'integration_title' => 'Wagy WhatsApp'
        ];
    }

    /**
     * Trigger setelah submit
     */
    public function notify($feed, $formData, $entry, $form) {               
        $value = $feed['processedValues'];

        if (empty($value['enabled'])) {
            return;
        }

        $recipient = preg_replace('/[^0-9]/', '', $value['recipient']);
        $message   = $value['message'];
        $media     = $value['media_url'] ?? '';
        $expired_in   = $value['expired_in'] ?? '';

        if (!$recipient) {
            return;
        }

        try {
            // support multiple nomor (pisah koma)
            $numbers = array_map('trim', explode(',', $recipient));

            foreach ($numbers as $number) {
                if (!$number) continue;
                Wagy::send_message([
                    'phone'      => $number,
                    'message'    => $message,
                    'media_url'  => $media,
                    'expires_in' => $expired_in * 3600, // convert hours to seconds
                ]);
            }

            do_action( 'ff_log_data', [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentForms core hook.
                'title'  => 'Wagy Success',
                'status' => 'success',
                'message'=> $recipient
            ] );

        } catch (\Exception $e) {
            do_action( 'ff_log_data', [ // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- FluentForms core hook.
                'title'   => 'Wagy Error',
                'status'  => 'failed',
                'message' => $e->getMessage()
            ] );
        }
    }
}
<?php

namespace VivaWalletPaymentForPaymattic\Settings;

use \WPPayForm\Framework\Support\Arr;
use \WPPayForm\App\Services\AccessControl;
use \WPPayFormPro\GateWays\BasePaymentMethod;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class VivaWalletSettings extends BasePaymentMethod
{
   /**
     * Automatically create global payment settings page
     * @param  String: key, title, routes_query, 'logo')
     */
    public function __construct()
    {
        parent::__construct(
            'vivawallet',
            'VivaWallet',
            [],
            VIVAWALLET_PAYMENT_FOR_PAYMATTIC_URL . 'assets/vivawallet.svg' // follow naming convention of logo with lowercase exactly as payment key to avoid logo rendering hassle
        );
    }

     /**
     * @function mapperSettings, To map key => value before store
     * @function validateSettings, To validate before save settings
     */

    public function init()
    {
        add_filter('wppayform_payment_method_settings_mapper_'.$this->key, array($this, 'mapperSettings'));
        add_filter('wppayform_payment_method_settings_validation_'.$this->key, array($this, 'validateSettings'), 10, 2);
    }

    public function mapperSettings ($settings)
    { 
        return $this->mapper(
            static::settingsKeys(), 
            $settings, 
            false
        );
    }

    /**
     * @return Array of default fields
     */
    public static function settingsKeys()
    {
        $slug = 'vivawallet-payment-for-paymattic';
        return array(
            'payment_mode' => 'test',
            'live_client_id' => '',
            'live_client_secret' => '',
            'test_client_id' => '',
            'test_client_secret' => '',
            'live_source_code' => '',
            'test_source_code' => '',
            'test_api_key' => '',
            'live_api_key' => '',
            'test_merchant_id' => '',
            'live_merchant_id' => '',
            'update_available' => static::checkForUpdate($slug),
        );
    }

    // public static function settingsKeys() : array
    // {
    //     $slug = 'moneris-payment-for-paymattic';

    //     return array(
    //         'is_active' => 'no',
    //         'payment_mode' => 'test',
    //         'checkout_type' => 'modal',
    //         'test_store_id' => '',
    //         'test_api_token' => '',
    //         'test_checkout_id' => '',
    //         'live_store_id' => '',
    //         'live_api_token' => '',
    //         'live_checkout_id' => '',
    //         'payment_channels' => [],
    //         'update_available' => array(
    //             'available' => 'no',
    //             'url' => '',
    //             'slug' => $slug
            
    //         ),
    //     );
    // }

    public static function checkForUpdate($slug) : array
    {
        $githubApi = "https://api.github.com/repos/WPManageNinja/{$slug}/releases";

        // will be handled properly in future
        return  array(
            'available' => 'no',
            'url' => '',
            'slug' => 'vivawallet-payment-for-paymattic'
        );

        $response = wp_remote_get($githubApi);

        $response = wp_remote_get($githubApi);
        $releases = json_decode($response['body']);
        if (isset($releases->documentation_url)) {
            return $result;
        }

        $latestRelease = $releases[0];
        $latestVersion = $latestRelease->tag_name;
        $zipUrl = $latestRelease->zipball_url;

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    
        $plugins = get_plugins();
        $currentVersion = '';

        // Check if the plugin is present
        foreach ($plugins as $plugin_file => $plugin_data) {
            // Check if the plugin slug or name matches
            if ($slug === $plugin_data['TextDomain'] || $slug === $plugin_data['Name']) {
                $currentVersion = $plugin_data['Version'];
            }
        }

        if (version_compare( $latestVersion, $currentVersion, '>')) {
            $result['available'] = 'yes';
            $result['url'] = $zipUrl;
        }

        return $result;
    }

    public static function getSettings () : array
    {
        $setting = get_option('wppayform_payment_settings_vivawallet', []);

        // Check especially only for addons
        $tempSettings = static::settingsKeys();
        if (isset($tempSettings['update_available'])) {
            $setting['update_available'] = $tempSettings['update_available'];
        }

        return wp_parse_args($setting, static::settingsKeys());
    }

    public function getPaymentSettings()
    {
        $settings = $this->mapper(
            $this->globalFields(), 
            static::getSettings()
        );

        return array(
            'settings' => $settings
        ); 
    }

    /**
     * @return Array of global fields
     */
    public function globalFields() : array
    {
        $successURL = add_query_arg(array(
            'wppayform_payment' => 'wpf_success',
            'payment_method' => 'vivawallet',
        ), home_url());

        $failureURL = add_query_arg(array(
            'wppayform_payment' => 'wpf_failed',
            'payment_method' => 'vivawallet',
        ), home_url());



        return array(
            'payment_mode' => array(
                'value' => 'test',
                'label' => __('Payment Mode', 'vivawallet-payment-for-paymattic'),
                'options' => array(
                    'test' => __('Test Mode', 'vivawallet-payment-for-paymattic'),
                    'live' => __('Live Mode', 'vivawallet-payment-for-paymattic')
                ),
                'type' => 'payment_mode'
            ),
            'test_client_id' => array(
                'value' => '',
                'label' => __('Test Client ID', 'vivawallet-payment-for-paymattic'),
                'type' => 'test_pub_key',
                'placeholder' => __('Test Client ID', 'vivawallet-payment-for-paymattic')
            ),
            'test_client_secret' => array(
                'value' => '',
                'label' => __('Test Client Secret', 'vivawallet-payment-for-paymattic'),
                'type' => 'test_secret_key',
                'placeholder' => __('Test Client Secret', 'vivawallet-payment-for-paymattic')
            ),
            'live_client_id' => array(
                'value' => '',
                'label' => __('Live Client ID', 'vivawallet-payment-for-paymattic'),
                'type' => 'live_pub_key',
                'placeholder' => __('Live Client ID', 'vivawallet-payment-for-paymattic')
            ),
            'live_client_secret' => array(
                'value' => '',
                'label' => __('Live Client Secret', 'vivawallet-payment-for-paymattic'),
                'type' => 'live_secret_key',
                'placeholder' => __('Live Client Secret', 'vivawallet-payment-for-paymattic')
            ),
            'test_source_code' => array(
                'value' => '',
                'label' => __('Test Source Code', 'vivawallet-payment-for-paymattic'),
                'type' => 'test_pub_key',
                'placeholder' => __('Test Source Code', 'vivawallet-payment-for-paymattic')
            ),
            'live_source_code' => array(
                'value' => '',
                'label' => __('Live Source Code', 'vivawallet-payment-for-paymattic'),
                'type' => 'live_pub_key',
                'placeholder' => __('Live Source Code', 'vivawallet-payment-for-paymattic')
            ),
            'test_api_key' => array(
                'value' => '',
                'label' => __('Test API Key', 'vivawallet-payment-for-paymattic'),
                'type' => 'test_pub_key',
                'placeholder' => __('Test API Key', 'vivawallet-payment-for-paymattic')
            ),
            'live_api_key' => array(
                'value' => '',
                'label' => __('Live API Key', 'vivawallet-payment-for-paymattic'),
                'type' => 'live_pub_key',
                'placeholder' => __('Live API Key', 'vivawallet-payment-for-paymattic')
            ),
            'test_merchant_id' => array(
                'value' => '',
                'label' => __('Test Merchant ID', 'vivawallet-payment-for-paymattic'),
                'type' => 'test_pub_key',
                'placeholder' => __('Test Merchant ID', 'vivawallet-payment-for-paymattic')
            ),
            'live_merchant_id' => array(
                'value' => '',
                'label' => __('Live Merchant ID', 'vivawallet-payment-for-paymattic'),
                'type' => 'live_pub_key',
                'placeholder' => __('Live Merchant ID', 'vivawallet-payment-for-paymattic')
            ),
            'desc' => array(
                'value' => '<p>See our <a href="https://paymattic.com/docs/add-vivawallet-payment-gateway-in-paymattic" target="_blank" rel="noopener">documentation</a> to get more information about vivawallet setup.</p>',
                'type' => 'html_attr',
                'placeholder' => __('Description', 'vivawallet-payment-for-paymattic')
            ),
            'webhook_desc' => array(
                'value' => "<h3><span style='color: #ef680e; margin-right: 2px'>*</span>Required VivaWallet Webhook Setup</h3> <p>In order for Vaivawallet to function completely for payments, you must configure your vivawallet webhooks. Visit your <a href='https://dashboard.vivawallet.co/settings/developers#callbacks' target='_blank' rel='noopener'>account dashboard</a> to configure them. Please add a webhook endpoint for the URL below. </p> <p><b>Webhook URL: </b><code> ". site_url('?wpf_vivawallet_listener=1') . "</code></p> <p>See <a href='https://paymattic.com/docs/add-vivawallet-payment-gateway-in-paymattic#webhook' target='_blank' rel='noopener'>our documentation</a> for more information. <p><b>Please enable the following Webhook events for this URL:</b></p> <ul> <li><code>Transaction Payment Created</code></li></ul></div>",
                'label' => __('Webhook URL', 'vivawallet-payment-for-paymattic'),
                'type' => 'html_attr',
            ),
            'success_url' => array(
                'value' => "<h3><span style='color: #ef680e; margin-right: 2px'>*</span>Set Your Success URL:" . "</h3>".  "<p> Ex: " . htmlspecialchars($successURL, ENT_QUOTES, 'UTF-8') . "</p> <span style='font-weight: bold'> This part is required - '?wppayform_payment=wpf_success&payment_method=vivawallet'</span>",
                'label' => __('Success URL', 'vivawallet-payment-for-paymattic'),
                'type' => 'html_attr',
                'placeholder' => __('Success URL', 'vivawallet-payment-for-paymattic')
            ),
            'failure_url' => array(
                'value' => "<h3><span style='color: #ef680e; margin-right: 2px'>*</span>Set Your Failure URL:" . "</h3>".  "<p> Ex: " . htmlspecialchars($failureURL, ENT_QUOTES, 'UTF-8') . "</p> <span style='font-weight: bold'> This part is required - '?wppayform_payment=wpf_failed&payment_method=vivawallet'</span>",
                'label' => __('Failure URL', 'vivawallet-payment-for-paymattic'),
                'type' => 'html_attr',
                'placeholder' => __('Failure URL', 'vivawallet-payment-for-paymattic')
            ),
            'is_pro_item' => array(
                'value' => 'yes',
                'label' => __('PayPal', 'vivawallet-payment-for-paymattic'),
            ),
            'update_available' => array(
                'value' => array(
                    'available' => 'no',
                    'url' => '',
                    'slug' => 'vivawallet-payment-for-paymattic'
                ),
                'type' => 'update_check',
                'label' => __('Update to new version avaiable', 'vivawallet-payment-for-paymattic'),
            )
        );
    }

    public function validateSettings($errors, $settings)
    {
        AccessControl::checkAndPresponseError('set_payment_settings', 'global');
        $mode = Arr::get($settings, 'payment_mode');

        if ($mode == 'test') {
            if (empty(Arr::get($settings, 'test_client_secret')) || empty(Arr::get($settings, 'test_client_id')) || empty(Arr::get($settings, 'test_source_code'))) {
                $errors['test_api_key'] = __('Please provide Test credentials', 'vivawallet-payment-for-paymattic');
            }
        }

        if ($mode == 'live') {
            if (empty(Arr::get($settings, 'live_client_secret')) || empty(Arr::get($settings, 'live_client_id')) || empty(Arr::get($settings, 'live_source_code'))) {
                $errors['live_api_key'] = __('Please provide Live credentials', 'vivawallet-payment-for-paymattic');
            }
        }
        return $errors;
    }

    public function isLive($formId = false)
    {
        $settings = $this->getSettings();
        return $settings['payment_mode'] == 'live';
    }

    public function getApiKeys($formId = false)
    {
        $isLive = static::isLive($formId);
        $settings = static::getSettings();

        if ($isLive) {
            return array(
                'client_id' => Arr::get($settings, 'live_client_id'),
                'client_secret' => Arr::get($settings, 'live_client_secret'),
                'source_code' => Arr::get($settings, 'live_source_code'),
                'api_key' => Arr::get($settings, 'live_api_key'),
                'merchant_id' => Arr::get($settings, 'live_merchant_id'),
                'payment_mode' => 'live'
            );
        }
        return array(
            'client_id' => Arr::get($settings, 'test_client_id'),
            'client_secret' => Arr::get($settings, 'test_client_secret'),
            'source_code' => Arr::get($settings, 'test_source_code'),
            'api_key' => Arr::get($settings, 'test_api_key'),
            'merchant_id' => Arr::get($settings, 'test_merchant_id'),
            'payment_mode' => 'test'
        );
    }
}

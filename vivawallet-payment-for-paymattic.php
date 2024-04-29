<?php

/**
 * @package vivawallet-payment-for-paymattic
 *
 *
 */

/**
 * Plugin Name: Vivawallet Payment for paymattic
 * Plugin URI: https://paymattic.com/
 * Description: Vivawallet payment gateway for paymattic. Vivawallet is the leading payment gateway in Europe.
 * Version: 1.0.0
 * Author: WPManageNinja LLC
 * Author URI: https://paymattic.com/
 * License: GPLv2 or later
 * Text Domain: vivawallet-payment-for-paymattic
 * Domain Path: /language
 */

if (!defined('ABSPATH')) {
    exit;
}

defined('ABSPATH') or die;

define('VIVAWALLET_PAYMENT_FOR_PAYMATTIC', true);
define('VIVAWALLET_PAYMENT_FOR_PAYMATTIC_DIR', __DIR__);
define('VIVAWALLET_PAYMENT_FOR_PAYMATTIC_URL', plugin_dir_url(__FILE__));
define('VIVAWALLET_PAYMENT_FOR_PAYMATTIC_VERSION', '1.0.0');


if (!class_exists('VivaWalletPaymentForPaymattic')) {
    class VivaWalletPaymentForPaymattic
    {
        public function boot()
        {
            if (!class_exists('VivaWalletPaymentForPaymattic\API\VivaWalletProcessor')) {
                $this->init();
            };
        }

        public function init()
        {
            require_once VIVAWALLET_PAYMENT_FOR_PAYMATTIC_DIR . '/API/VivaWalletProcessor.php';
            (new VivaWalletPaymentForPaymattic\API\VivaWalletProcessor())->init();

            $this->loadTextDomain();
        }

        public function loadTextDomain()
        {
            load_plugin_textdomain('vivawallet-payment-for-paymattic', false, dirname(plugin_basename(__FILE__)) . '/language');
        }

        public function hasPro()
        {
            return defined('WPPAYFORMPRO_DIR_PATH') || defined('WPPAYFORMPRO_VERSION');
        }

        public function hasFree()
        {

            return defined('WPPAYFORM_VERSION');
        }

        public function versionCheck()
        {
            $currentFreeVersion = WPPAYFORM_VERSION;
            $currentProVersion = WPPAYFORMPRO_VERSION;

            return version_compare($currentFreeVersion, '4.5.2', '>=') && version_compare($currentProVersion, '4.5.2', '>=');
        }

        public function renderNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please install & Activate Paymattic and Paymattic Pro to use vivawallet-payment-for-paymattic plugin.', 'vivawallet-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }

        public function updateVersionNotice()
        {
            add_action('admin_notices', function () {
                if (current_user_can('activate_plugins')) {
                    echo '<div class="notice notice-error"><p>';
                    echo __('Please update Paymattic and Paymattic Pro to use vivawallet-payment-for-paymattic plugin!', 'vivawallet-payment-for-paymattic');
                    echo '</p></div>';
                }
            });
        }
    }


    add_action('init', function () {

        $vivawallet = (new VivaWalletPaymentForPaymattic);

        if (!$vivawallet->hasFree() || !$vivawallet->hasPro()) {
            $vivawallet->renderNotice();
        } else if (!$vivawallet->versionCheck()) {
            $vivawallet->updateVersionNotice();
        } else {
            $vivawallet->boot();
        }
    });
}

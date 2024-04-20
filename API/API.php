<?php

namespace VivaWalletPaymentForPaymattic\API;

use VivaWalletPaymentForPaymattic\Settings\VivaWalletSettings;
use WPPayForm\App\Models\Submission;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;

class API
{
    public function init()
    {
        $this->verifyIPN();
    }
    public function verifyIPN()
    {
        if (!isset($_REQUEST['wpf_vivawallet_listener'])) {
            return;
        }
        // Check the request method is POST
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        // Set initial post data to empty string
        $post_data = '';

        // Fallback just in case post_max_size is lower than needed
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
            ini_set('post_max_size', '12M');
        }
        // Start the encoded data collection with notification command
        $encoded_data = '';

        // Get current arg separator
        $arg_separator = ini_get('arg_separator.output');

        // Verify there is a post_data
        if ($post_data || strlen($post_data) > 0) {
            // Append the data
            $encoded_data .= $arg_separator . $post_data;
        } else {
            // Check if POST is empty
            if (empty($_POST)) {
                // Nothing to do
                return;
            } else {
                // Loop through each POST
                foreach ($_POST as $key => $value) {
                    // Encode the value and append the data
                    $encoded_data .= $arg_separator . "$key=" . urlencode($value);
                }
            }
        }

        // Convert collected post data to an array
        parse_str($encoded_data, $encoded_data_array);

        foreach ($encoded_data_array as $key => $value) {
            if (false !== strpos($key, 'amp;')) {
                $new_key = str_replace('&amp;', '&', $key);
                $new_key = str_replace('amp;', '&', $new_key);
                unset($encoded_data_array[$key]);
                $encoded_data_array[$new_key] = $value;
            }
        }

        $defaults = $_REQUEST;
        $encoded_data_array = wp_parse_args($encoded_data_array, $defaults);
        $this->handleIpn($encoded_data_array);
        exit(200);
    }

    protected function handleIpn($data)
    {
        $submissionId = intval(Arr::get($data, 'submission_id'));
        if (!$submissionId || empty($data['id'])) {
            return;
        }
        $submission = Submission::where('id', $submissionId)->first();
        if (!$submission) {
            return;
        }
        // implements the logic to handle the IPN if needed, now its not implemented
    }

    public function makeApiCall($path, $args, $formId, $method = 'GET', $accessTokenReq = false)
    {
        if ($accessTokenReq) {
            return $this->getAccessToken($path, $formId, $args);
        }

        $keys = (new VivaWalletSettings())->getApiKeys($formId);


        $headers = [
            'Content-type'  => 'application/json'
        ];

        $endPoint = 'https://demo-accounts.vivapayments.com/';

        if ($keys['payment_mode'] == 'live') {
            $endPoint = 'https://api.vivapayments.com/';
        }

        if ($method == 'POST') {
            $response = wp_remote_post(`$endPoint . $path`, [
                'headers' => $headers,
                'body'    => json_encode($args)
            
            ]);
        } else {
            $response = wp_remote_request(`$endPoint . $path`, [
                'headers' => $headers,
                'body'    => $args
            ]);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        if (!$responseData) {
            return [
                'response' => array(
                    'success' => 'false',
                    'error' => __('Unknown Moneris API request error', 'wp-payment-form-pro')
                )
            ];
        }
        return $responseData;
    }

    public function getAccessToken($path, $args, $formId, $method = 'POST')
    {
        $keys = (new VivaWalletSettings())->getApiKeys($formId);

        $headers = [
            'Content-type'  => 'application/json',
            'Authorization' => [
                'username' => $keys['test_client_id'],
                'password' => $keys['test_client_secret']
            ]
        ];

        $endPoint = 'https://demo-accounts.vivapayments.com/';

        if ($keys['payment_mode'] == 'live') {
            $endPoint = 'https://accounts.vivapayments.com/';
            $headers['Authorization']['username'] = $keys['live_client_id'];
            $headers['Authorization']['password'] = $keys['live_client_secret'];
        }

        if ($method == 'POST') {
            $response = wp_remote_post($endPoint, [
                'headers' => $headers,
                'body'    => json_encode($args)
            
            ]);
        } else {
            $response = wp_remote_request(`$endPoint . $path`, [
                'headers' => $headers,
                'body'    => $args
            ]);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $responseData = json_decode($body, true);

        if (!$responseData) {
            return [
                'response' => array(
                    'success' => 'false',
                    'error' => __('Unknown Vivalwallet API request error', 'wp-payment-form-pro')
                )
            ];
        }
        return $responseData;
    }
    
}

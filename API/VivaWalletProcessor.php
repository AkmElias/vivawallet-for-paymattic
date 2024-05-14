<?php

namespace VivaWalletPaymentForPaymattic\API;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

use WPPayForm\Framework\Support\Arr;
use WPPayForm\App\Models\Transaction;
use WPPayForm\App\Models\Form;
use WPPayForm\App\Models\Submission;
use WPPayForm\App\Models\OrderItem;
use WPPayForm\App\Services\CountryNames;
use WPPayForm\App\Services\PlaceholderParser;
use WPPayForm\App\Services\ConfirmationHelper;
use WPPayForm\App\Models\SubmissionActivity;

// can't use namespace as these files are not accessible yet
require_once VIVAWALLET_PAYMENT_FOR_PAYMATTIC_DIR . '/Settings/VivaWalletElement.php';
require_once VIVAWALLET_PAYMENT_FOR_PAYMATTIC_DIR . '/Settings/VivaWalletSettings.php';
require_once VIVAWALLET_PAYMENT_FOR_PAYMATTIC_DIR . '/API/API.php';


class VivaWalletProcessor
{
    public $method = 'vivawallet';

    protected $form;

    public function init()
    {
        new  \VivaWalletPaymentForPaymattic\Settings\VivaWalletElement();
        (new  \VivaWalletPaymentForPaymattic\Settings\VivaWalletSettings())->init();

        add_action('wppayform/payment_success_' . $this->method, array($this, 'handlePaid'), 10, 1);
        add_filter('wppayform/choose_payment_method_for_submission', array($this, 'choosePaymentMethod'), 10, 4);
        add_action('wppayform/form_submission_make_payment_' . $this->method, array($this, 'makeFormPayment'), 10, 6);
        add_action('wppayform_payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));
        add_filter('wppayform/entry_transactions_' . $this->method, array($this, 'addTransactionUrl'), 10, 2);
        // add_action('wppayform_ipn_vivawallet_action_refunded', array($this, 'handleRefund'), 10, 3);
        add_filter('wppayform/submitted_payment_items_' . $this->method, array($this, 'validateSubscription'), 10, 4);

        // do_actions triggered in API class won't be able to catch if the class is instantiated before above add_actions defined
        (new \VivaWalletPaymentForPaymattic\API\API())->init();
    }

    protected function getPaymentMode($formId = false)
    {
        $isLive = (new \VivaWalletPaymentForPaymattic\Settings\VivaWalletSettings())->isLive($formId);

        if ($isLive) {
            return 'live';
        }
        return 'test';
    }

    public function addTransactionUrl($transactions, $submissionId)
    {
        foreach ($transactions as $transaction) {
            if ($transaction->payment_method == 'vivawallet' && $transaction->charge_id) {
                $transactionUrl = Arr::get(unserialize($transaction->payment_note), '_links.dashboard.href');
                $transaction->transaction_url =  $transactionUrl;
            }
        }
        return $transactions;
    }

    public function choosePaymentMethod($paymentMethod, $elements, $formId, $form_data)
    {
        if ($paymentMethod) {
            // Already someone choose that it's their payment method
            return $paymentMethod;
        }
        // Now We have to analyze the elements and return our payment method
        foreach ($elements as $element) {
            if ((isset($element['type']) && $element['type'] == 'vivawallet_gateway_element')) {
                return 'vivawallet';
            }
        }
        return $paymentMethod;
    }

    public function makeFormPayment($transactionId, $submissionId, $form_data, $form, $hasSubscriptions, $totalPayable = 0)
    {      
        $paymentMode = $this->getPaymentMode();
       
        $transactionModel = new Transaction();
        if ($transactionId) {
            $transactionModel->updateTransaction($transactionId, array(
                'payment_mode' => $paymentMode
            ));
        }
        $transaction = $transactionModel->getTransaction($transactionId);

        $submission = (new Submission())->getSubmission($submissionId);
        $formDataFormatted = maybe_unserialize($submission->form_data_formatted);
        $this->handleRedirect($transaction, $submission, $form, $form_data, $formDataFormatted, $paymentMode, $hasSubscriptions);
    }

    public function handleRedirect($transaction, $submission, $form, $form_data, $formDataFormatted, $paymentMode, $hasSubscriptions)
    {
        // Get accessToken
        $response = (new API())->makeApiCall('connect/token', [], $form->ID, 'POST', true, '');

        if (!isset($response['access_token'])) {
            wp_send_json_error([
                'message' => __('Unable to get access token from vivawallet', 'vivawallet-payment-for-paymattic'),
                'payment_error' => true
            ], 423);
        } 

        $accessToken = $response['access_token'];
        $sourceCode = (new \VivaWalletPaymentForPaymattic\Settings\VivaWalletSettings())->getApiKeys($submission->form_id)['source_code'];

        if (!$sourceCode) {
            wp_send_json_error([
                'message' => __('Source code is not set for this form', 'vivawallet-payment-for-paymattic'),
                'payment_error' => true
            ], 423);
        }

        $requireBillingAddress = Arr::get($form_data, '__payment_require_billing_address') == 'yes';
        $paymentMode = $this->getPaymentMode($submission->form_id);
        $address = '';
        $language = 'en-GB';

        if ($requireBillingAddress) {
            if (empty($formDataFormatted['address_input'])) {
                return [
                    'response' => array(
                        'success' => 'false',
                        'error' => __('Billing Address is required.', 'wp-payment-form-pro')
                    )
                ];
            }
            $address = explode(',', $formDataFormatted['address_input']);
        }

        $hasAddress = isset($formDataFormatted['address_input']);

        if ($hasAddress && !$address) {
            $address = explode(',', $formDataFormatted['address_input']);
        }

        $country = 'GB';
        if (isset($address[5]) && !empty($address[5])) {
            $country = CountryNames::getCountryCode(trim($address[5]));
        }
      
        $orderItemsModel = new OrderItem();
        $lineItems = $orderItemsModel->getOrderItems($submission->id)->toArray();
        $hasLineItems = count($lineItems) ? true : false;
        $langCode = CountryNames::getLanguageCodeByCountryCode($country);
        $language = $langCode? $langCode : $language;

        if (!$hasLineItems && !$hasSubscriptions) {
           wp_send_json_error(array(
                'message' => 'Vivawallet payment requires at least one line item or subscription',
                'payment_error' => true,
                'type' => 'error',
                'form_events' => [
                    'payment_failed'
                ]
            ), 423);
        }

        $currencyIsSupported = $this->checkForSupportedCurrency($submission);
        
        if (!$currencyIsSupported) {
            wp_send_json_error([
                'message' => sprintf(__('%s is not supported by vivawallet', 'vivawallet-payment-for-paymattic'), $submission->currency),
                'payment_error' => true
            ], 423);
        }


        $currencyCode = $this->getCurrencyCode($submission->currency);

        $orderItemModel = new OrderItem();
        $discountItems = $orderItemModel->getDiscountItems($submission->id)->toArray();

        // now construct payment
        $paymentArgs = [];
        $paymentArgs['amount'] = $transaction->payment_total;
        $paymentArgs['customerTrns'] = $hasLineItems ? $this->getCustomerTrns($submission, $lineItems, $discountItems): 'Payment for ' . get_bloginfo('name');
        $paymentArgs['customer'] = $this->getCustomer($submission, $country, $language);
        $paymentArgs['sourceCode'] = $sourceCode;
        $paymentArgs['currencyCode'] = $currencyCode ? $currencyCode : 978;
        $paymentArgs['paymentTimeout'] = 300;
        $paymentArgs['allowRecurring'] = false;

        // make create payment order api call
        $response = (new API())->makeApiCall('checkout/v2/orders', $paymentArgs, $form->ID, 'POST', false, $accessToken);

        if (!isset($response['orderCode'])) {
            wp_send_json_error([
                'message' => $response['message'],
                'payment_error' => true
            ], 423);
        }

        // https://paymattic.com/pricing/?t=fa9c0eb7-d8d6-4a96-9fe7-e87ff32c9037&s=8463337861197764&lang=en-GB&eventId=0&eci=1
      
        $orderCode = Arr::get($response, 'orderCode');

        $updateData = [
            'charge_id'        => $orderCode,
        ];

        $transactionModel = new Transaction();
        $transactionModel->updateTransaction($transaction->id, $updateData);

        // construct payment redirect link
        if ($paymentMode == 'live') {
            $paymentLink = 'https://www.vivapayments.com/web/checkout?ref='.$orderCode;
        } else {
            $paymentLink = 'https://demo.vivapayments.com/web/checkout?ref='. $orderCode;
        }

        do_action('wppayform_log_data', [
            'form_id' => $form->ID,
            'submission_id' => $submission->id,
            'type' => 'activity',
            'created_by' => 'Paymattic BOT',
            'title' => 'vivawallet Payment Redirect',
            'content' => 'User redirect to vivawallet for completing the payment'
        ]);

        wp_send_json_success([
            // 'nextAction' => 'payment',
            'call_next_method' => 'normalRedirect',
            'redirect_url' => $paymentLink,
            'message'      => __('You are redirecting to vivawallet.com to complete the purchase. Please wait while you are redirecting....', 'vivawallet-payment-for-paymattic'),
        ], 200);
    }

    public function getCustomerTrns($submission, $lineItems, $discountItems = [])
    {
        // in lineItems array every item has name and price and quantity
        $customerTrns = '';
        $lineItems = array_map(function ($item) {
            if ($item['type'] == 'tax_line') {
                return $item['item_name'];
            }
            return $item['item_name'] . ' x ' . $item['quantity'] . ' = ' . number_format($item['item_price'] / 100, 2, '.', '');
        }, $lineItems);

        $discountItems = array_map(function ($item) {
            return $item['item_name'];
        }, $discountItems);

        $customerTrns = implode(', ', $lineItems);

        if (count($discountItems)) {
            $customerTrns .= ', Applied Coupons: (' . implode(', ', $discountItems) . ')';
        }
        return $customerTrns;
    }
    public function getCustomer($submission, $country = 'GB', $language = 'en-GB')
    {
        $customer = array(
            'email' => $submission->customer_email,
            'phone' => $submission->customer_phone ? $submission->customer_phone : '0000000000',
            'fullName' => $submission->customer_name ? $submission->customer_name : '',
            'requestLang' => $language,
            'countryCode' => $country,
        );

        return $customer;
    }


    public function checkForSupportedCurrency($submission)
    {
        $currency = $submission->currency;
        $supportedCurrencies = array(
            'EUR',
            'GBP',
            'RON',
            'PLN',
            'CZK',
            'HUF',
            'SEK',
            'DKK',
            'BGN'
        );

        // check currencyis in supported currencies
        if (!in_array($currency, $supportedCurrencies)) {
            return false;
        }

        return true;

    }

    public function getCurrencyCode($currency){
        $currencyCode = array(
            'EUR' => 978,
            'GBP' => 826,
            'RON' => 946,
            'PLN' => 985,
            'CZK' => 203,
            'HUF' => 348,
            'SEK' => 752,
            'DKK' => 208,
            'BGN' => 975
        );

        return $currencyCode[$currency];
    }

    public function handlePaid($data)
    {
        if(!$data){
            return;
        }

        $orderCode = $data->OrderCode;
        $transactionId = $data->TransactionId;
        $cardNumber = (string) $data->CardNumber;
        $lastFourDigits = substr($cardNumber, -4);
        $status = $data->StatusId;

        $status = $status == 'F' ? 'paid' : 'failed';

        $transaction = new Transaction();
        // get the transaction by charge id which is the order code
        $transaction = $transaction->getTransactionByChargeId($orderCode);

        if (!$transaction || $transaction->payment_method != $this->method || $transaction->status === 'paid') {
            return;
        }

        if ($status == $transaction->status) {
            return;
        }

        $submission = (new Submission())->getSubmission($transaction->submission_id);

        if ($status == 'failed') {
            $updateData = [
                'charge_id' => $orderCode,
                'status' => 'failed',
            ];
            $this->markAsFailed($status, $updateData, $transaction);
        }

        // Get accessToken to verify the transaction
        $response = (new API())->makeApiCall('connect/token', [], $submission->form_id, 'POST', true, '');
      
        if (isset($response['access_token'])) {
            $payment = (new API())->makeApiCall('/checkout/v2/transactions/' . $transactionId, [], $submission->form_id, 'GET', false, $response['access_token']);
            if (isset($payment['error'])) {
                do_action('wppayform_log_data', [
                    'form_id' => $submission->form_id,
                    'submission_id' => $submission->id,
                    'type' => 'info',
                    'created_by' => 'PayForm Bot',
                    'content' => $payment['error']
                ]);
                return;
            } else {
                // payment varified. make payment paid
                $updateData = [
                    'charge_id' => $transactionId,
                    'payment_note' => maybe_serialize($data),
                    'card_last_4' => $lastFourDigits,
                    'status' => $status,
                    'updated_at' => current_time('mysql')
                ];
            
                do_action('wppayform/form_submission_activity_start', $transaction->form_id);
                $this->markAsPaid($status, $updateData, $transaction);
            }
        } else {
            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'info',
                'created_by' => 'PayForm Bot',
                'content' => $response['error']
            ]);
            return;
        } 


    }
    public function handleSessionRedirectBack($data)
    {
        $orderCode = sanitize_text_field(Arr::get($data, 'ocd'));
        $status = sanitize_text_field(Arr::get($data, 'wppayform_payment'));

        if ($status == 'wpf_success') {
            $status = 'paid';
        } else if ($status == 'wpf_failed') {
            $status = 'failed';
        } else {
            $status = 'pending';
        }

        $transaction = new Transaction();
        // get the transaction by charge id which is the order code in this case
        $transaction = $transaction->getTransactionByChargeId($orderCode);

        if (!$transaction || $transaction->payment_method != $this->method || $transaction->status === 'paid') {
            return;
        }

        if ($status == $transaction->status) {
            return;
        }

        $submission = (new Submission())->getSubmission($transaction->submission_id);

        // This hook will be useful for the developers to do something after the payment is processed
        do_action('wppayform/form_payment_processed', $submission->form_id, $submission, $data, $status);

        if ($status == 'failed') {
            $updateData = [
                'charge_id' => $orderCode,
                'status' => 'failed',
            ];

            $submissionData = array(
                'payment_status' => $status,
                'updated_at' => current_time('Y-m-d H:i:s')
            );
            $submissionModel = new Submission();
            $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

            $transactionModel = new Transaction();
            $data = array(
                'status' => $status,
                'updated_at' => current_time('Y-m-d H:i:s')
            );
            $transactionModel->where('id', $transaction->id)->update($data);

            $transaction = $transactionModel->getTransaction($transaction->id);
            SubmissionActivity::createActivity(array(
                'form_id' => $transaction->form_id,
                'submission_id' => $transaction->submission_id,
                'type' => 'info',
                'created_by' => 'PayForm Bot',
                'content' => sprintf(__('Transaction Marked as failed', 'vivawallet-payment-for-paymattic'))
            ));
            return;
        }

        // get the real transaction id from the $data received from vivawallet
        $transactionId = Arr::get($data, 't');

        // Get accessToken to verify the transaction
        $response = (new API())->makeApiCall('connect/token', [], $submission->form_id, 'POST', true, '');
      
        if (isset($response['access_token'])) {
            $payment = (new API())->makeApiCall('/checkout/v2/transactions/' . $transactionId, [], $submission->form_id, 'GET', false, $response['access_token']);
            if (isset($payment['error'])) {
                do_action('wppayform_log_data', [
                    'form_id' => $submission->form_id,
                    'submission_id' => $submission->id,
                    'type' => 'info',
                    'created_by' => 'PayForm Bot',
                    'content' => $payment['error']
                ]);
                return;
            } else {
                // payment varified. make payment paid
                $updateData = [
                    'charge_id' => $transactionId,
                    'payment_note' => maybe_serialize($data),
                    'status' => $status,
                    'updated_at' => current_time('mysql')
                ];
            
                do_action('wppayform/form_submission_activity_start', $transaction->form_id);
                $this->markAsPaid($status, $updateData, $transaction);
            }
        } else {
            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'info',
                'created_by' => 'PayForm Bot',
                'content' => $response['error']
            ]);
            return;
        } 
    }

    public function handleRefund($refundAmount, $submission, $vendorTransaction)
    {
        $transaction = $this->getLastTransaction($submission->id);
        $this->updateRefund($vendorTransaction['status'], $refundAmount, $transaction, $submission);
    }

    public function updateRefund($newStatus, $refundAmount, $transaction, $submission)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($submission->id);
        if ($submission->payment_status == $newStatus) {
            return;
        }

        $submissionModel->updateSubmission($submission->id, array(
            'payment_status' => $newStatus
        ));

        Transaction::where('submission_id', $submission->id)->update(array(
            'status' => $newStatus,
            'updated_at' => current_time('mysql')
        ));

        do_action('wppayform/after_payment_status_change', $submission->id, $newStatus);

        $activityContent = 'Payment status changed from <b>' . $submission->payment_status . '</b> to <b>' . $newStatus . '</b>';
        $note = wp_kses_post('Status updated by vivawallet.');
        $activityContent .= '<br />Note: ' . $note;
        SubmissionActivity::createActivity(array(
            'form_id' => $submission->form_id,
            'submission_id' => $submission->id,
            'type' => 'info',
            'created_by' => 'vivawallet',
            'content' => $activityContent
        ));
    }

    public function getLastTransaction($submissionId)
    {
        $transactionModel = new Transaction();
        $transaction = $transactionModel->where('submission_id', $submissionId)
            ->first();
        return $transaction;
    }

    public function markAsPaid($status, $updateData, $transaction)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);

        $formDataRaw = $submission->form_data_raw;
        $formDataRaw['vivawallet_ipn_data'] = $updateData;
        $submissionData = array(
            'payment_status' => $status,
            'form_data_raw' => maybe_serialize($formDataRaw),
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        $transactionModel = new Transaction();
        $data = array(
            'charge_id' => $updateData['charge_id'],
            'payment_note' => $updateData['payment_note'],
            'status' => $status,
            'updated_at' => current_time('Y-m-d H:i:s')
        );
        $transactionModel->where('id', $transaction->id)->update($data);

        $transaction = $transactionModel->getTransaction($transaction->id);
        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as paid and vivawallet Transaction ID: %s', 'vivawallet-payment-for-paymattic'), $data['charge_id'])
        ));

        do_action('wppayform/form_payment_success_vivawallet', $submission, $transaction, $transaction->form_id, $updateData);
        do_action('wppayform/form_payment_success', $submission, $transaction, $transaction->form_id, $updateData);
    }

    public function markAsFailed($status, $updateData, $transaction)
    {
        $submissionModel = new Submission();
        $submission = $submissionModel->getSubmission($transaction->submission_id);

        $formDataRaw = $submission->form_data_raw;
        $formDataRaw['vivawallet_ipn_data'] = $updateData;
        $submissionData = array(
            'payment_status' => $status,
            'form_data_raw' => maybe_serialize($formDataRaw),
            'updated_at' => current_time('Y-m-d H:i:s')
        );

        $submissionModel->where('id', $transaction->submission_id)->update($submissionData);

        $transactionModel = new Transaction();
        $data = array(
            'charge_id' => $updateData['charge_id'],
            'payment_note' => $updateData['payment_note'],
            'status' => $status,
            'updated_at' => current_time('Y-m-d H:i:s')
        );
        $transactionModel->where('id', $transaction->id)->update($data);

        $transaction = $transactionModel->getTransaction($transaction->id);
        SubmissionActivity::createActivity(array(
            'form_id' => $transaction->form_id,
            'submission_id' => $transaction->submission_id,
            'type' => 'info',
            'created_by' => 'PayForm Bot',
            'content' => sprintf(__('Transaction Marked as failed', 'vivawallet-payment-for-paymattic'))
        ));

        do_action('wppayform/form_payment_failed_vivawallet', $submission, $transaction, $transaction->form_id, $updateData);
        do_action('wppayform/form_payment_failed', $submission, $transaction, $transaction->form_id, $updateData);
    }

    public function validateSubscription($paymentItems)
    {
        wp_send_json_error(array(
            'message' => __('Subscription with vivawallet is not supported yet!', 'vivawallet-payment-for-paymattic'),
            'payment_error' => true
        ), 423);
    }
}

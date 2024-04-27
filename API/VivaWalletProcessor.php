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
        (new \VivaWalletPaymentForPaymattic\API\API())->init();

        add_filter('wppayform/choose_payment_method_for_submission', array($this, 'choosePaymentMethod'), 10, 4);
        add_action('wppayform/form_submission_make_payment_vivawallet', array($this, 'makeFormPayment'), 10, 6);
        add_action('wppayform_payment_frameless_' . $this->method, array($this, 'handleSessionRedirectBack'));
        add_filter('wppayform/entry_transactions_' . $this->method, array($this, 'addTransactionUrl'), 10, 2);
        // add_action('wppayform_ipn_vivawallet_action_refunded', array($this, 'handleRefund'), 10, 3);
        add_filter('wppayform/submitted_payment_items_' . $this->method, array($this, 'validateSubscription'), 10, 4);
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

    private function getSuccessURL($form, $submission)
    {
        // Check If the form settings have success URL
        $confirmation = Form::getConfirmationSettings($form->ID);
        $confirmation = ConfirmationHelper::parseConfirmation($confirmation, $submission);
        if (
            ($confirmation['redirectTo'] == 'customUrl' && $confirmation['customUrl']) ||
            ($confirmation['redirectTo'] == 'customPage' && $confirmation['customPage'])
        ) {
            if ($confirmation['redirectTo'] == 'customUrl') {
                $url = $confirmation['customUrl'];
            } else {
                $url = get_permalink(intval($confirmation['customPage']));
            }
            $url = add_query_arg(array(
                'payment_method' => 'vivawallet'
            ), $url);
            return PlaceholderParser::parse($url, $submission);
        }
        // now we have to check for global Success Page
        $globalSettings = get_option('wppayform_confirmation_pages');
        if (isset($globalSettings['confirmation']) && $globalSettings['confirmation']) {
            return add_query_arg(array(
                'wpf_submission' => $submission->submission_hash,
                'payment_method' => 'vivawallet'
            ), get_permalink(intval($globalSettings['confirmation'])));
        }
        // In case we don't have global settings
        return add_query_arg(array(
            'wpf_submission' => $submission->submission_hash,
            'payment_method' => 'vivawallet'
        ), home_url());
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

        $country = CountryNames::getCountryCode(trim($address[5])) ? CountryNames::getCountryCode(trim($address[5])) : 'GB';

        $orderItemsModel = new OrderItem();
        $lineItems = $orderItemsModel->getOrderItems($submission->id)->toArray();
        $hasLineItems = count($lineItems) ? true : false;

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

        $orderItemModel = new OrderItem();
        $discountItems = $orderItemModel->getDiscountItems($submission->id)->toArray();

        // dd($lineItems);
        // die();

        // now construct payment
        $paymentArgs = [];
        $paymentArgs['amount'] = $transaction->payment_total;
        $paymentArgs['customerTrns'] = $hasLineItems ? $this->getCustomerTrns($submission, $lineItems, $discountItems): 'Payment for ' . get_bloginfo('name');
        $paymentArgs['customer'] = $this->getCustomer($submission, $country, $language);
        $paymentArgs['sourceCode'] = $sourceCode;
        $paymentArgs['paymentTimeout'] = 300;
        $paymentArgs['allowRecurring'] = false;

        // $paymentArgs = [
        //     'amount' => $transaction->payment_total,
        //     'customerTrns' => 'Payment for ' . get_bloginfo('name'),
        //     'customer' => array(
        //         'email' => $submission->customer_email,
        //         'phone' => $submission->customer_phone ? $submission->customer_phone : '0000000000',
        //         'fullName' => $submission->customer_name,
        //         'requestLang' => 'en-GB',
        //         'countryCode' => 'GB',
        //     ),
        //     'sourceCode' => (new \VivaWalletPaymentForPaymattic\Settings\VivaWalletSettings())->getApiKeys($submission->form_id)['source_code'],
        //     'paymentTimeout' => 300,
        //     'allowRecurring' => false,
        //     'requestLang' => 'en-US',
        // ];

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

        $currencyIsSupported = $this->checkForSupportedCurrency($submission);
        
        if (!$currencyIsSupported) {
            wp_send_json_error([
                'message' => sprintf(__('%s is not supported by vivawallet', 'vivawallet-payment-for-paymattic'), $submission->currency),
                'payment_error' => true
            ], 423);
        }

        // $successUrl = $this->getSuccessURL($form, $submission);
        // $listener_url = add_query_arg(array(
        //     'wppayform_payment' => $submission->id,
        //     'payment_method' => $this->method,
        //     'submission_hash' => $submission->submission_hash,
        // ), $successUrl);

        // $customer = array(
        //     'email' => $submission->customer_email,
        //     'name' => $submission->customer_name,
        // );

        // // we need to change according to the payment gateway documentation
        // $paymentArgs = array(
        //     'tx_ref' => $submission->submission_hash,
        //     'amount' => number_format((float) $transaction->payment_total / 100, 2, '.', ''),
        //     'currency' => $submission->currency,
        //     'redirect_url' => $listener_url,
        //     'customer' => $customer,
        // );

        // $paymentArgs = apply_filters('wppayform_vivawallet_payment_args', $paymentArgs, $submission, $transaction, $form);
        // $payment = (new API())->makeApiCall('payments', $paymentArgs, $form->ID, 'POST');

        // if (is_wp_error($payment)) {
        //     do_action('wppayform_log_data', [
        //         'form_id' => $submission->form_id,
        //         'submission_id'        => $submission->id,
        //         'type' => 'activity',
        //         'created_by' => 'Paymattic BOT',
        //         'title' => 'vivawallet Payment Redirect Error',
        //         'content' => $payment->get_error_message()
        //     ]);

        //     wp_send_json_error([
        //         'message'      => $payment->get_error_message()
        //     ], 423);
        // }

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
            return $item['item_name'] . ' x ' . $item['quantity'];
        }, $lineItems);

        // $discountItems = array_map(function ($item) {
        //     return $item[''] . ' x ' . $item->quantity;
        // }, $discountItems);

        $customerTrns = implode(', ', $lineItems);

        // if (count($discountItems)) {
        //     $customerTrns .= ', ' . implode(', ', $discountItems);
        // }
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
            'GBP', 
            'CAD',
            'XAF', 
            'CLP', 
            'COP', 
            'EGP', 
            'EUR',
            'GHS', 
            'GNF', 
            'KES', 
            'MWK',
            'MAD', 
            'NGN', 
            'RWF', 
            'ZAR', 
            'TZS', 
            'UGX', 
            'USD', 
            'XOF', 
            'ZMW', 
            'SLL', 
            'STD'
            );

        // check currencyis in supported currencies
        if (!in_array($currency, $supportedCurrencies)) {
            return false;
        }

        return true;

    }
    public function handleSessionRedirectBack($data)
    {

        $submissionId = intval($data['wppayform_payment']);
        $submission = (new Submission())->getSubmission($submissionId);
        $transaction = $this->getLastTransaction($submissionId);

        $transactionId = Arr::get($data, 'transaction_id');
        $paymentStatus = Arr::get($data, 'status');
        // This hook will be usefull for the developers to do something after the payment is processed
        do_action('wppayform/form_payment_processed', $submission->form_id, $submission, $data, $paymentStatus);

        $payment = (new API())->makeApiCall('transactions/' . $transactionId . '/verify', [], $submission->form_id);

        if (!$payment || is_wp_error($payment)) {
            do_action('wppayform/form_payment_failed',$submission, $submission->form_id, $data, 'razorpay');
            return;
        }

        if (is_wp_error($payment)) {
            do_action('wppayform_log_data', [
                'form_id' => $submission->form_id,
                'submission_id' => $submission->id,
                'type' => 'info',
                'created_by' => 'PayForm Bot',
                'content' => $payment->get_error_message()
            ]);
        }

        $transaction = $this->getLastTransaction($submissionId);

        if (!$transaction || $transaction->payment_method != $this->method || $transaction->status === 'paid') {
            return;
        }

        do_action('wppayform/form_submission_activity_start', $transaction->form_id);

        if ($paymentStatus === 'successful') {
            $status = 'paid';
        } else if ($paymentStatus === 'failed') {
            $status = 'failed';
        } else {
            $status = 'pending';
        }

        $updateData = [
            'payment_note'     => maybe_serialize($payment),
            'charge_id'        => $transactionId,
        ];

        $this->markAsPaid($status, $updateData, $transaction);
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

    public function validateSubscription($paymentItems)
    {
        wp_send_json_error(array(
            'message' => __('Subscription with vivawallet is not supported yet!', 'vivawallet-payment-for-paymattic'),
            'payment_error' => true
        ), 423);
    }
}

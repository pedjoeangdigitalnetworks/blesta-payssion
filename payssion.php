<?php
/**
 * Payssion Payment Gateway
 *
 * Payssion API reference: https://www.payssion.com/en/docs
 *
 * @package blesta
 * @subpackage blesta.components.gateways.payssion
 * @copyright Copyright (c) 2023, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.pedjoeangdigital.net/ Pedjoeang Digital Network
 */
require_once dirname(__FILE__) . DS . 'libs' . DS . 'payssion.php';

use Payssion\PayssionClient;

class Payssion extends NonmerchantGateway
{
    /**
     * @var array An array of meta data for this gateway
     */
    private $meta;

    private $payssionUrl;

    /**
     * Construct a new merchant gateway
     */
    public function __construct()
    {
        // Load configuration required by this gateway
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        // Load components required by this gateway
        Loader::loadComponents($this, ['Input']);

        // Load the helpers required for this gateway
        Loader::loadHelpers($this, ['Html']);

        // Load the language required by this gateway
        Language::loadLang('payssion', null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /**
     * Sets the meta data for this particular gateway
     *
     * @param array $meta An array of meta data to set for this gateway
     */
    public function setMeta(array $meta = null)
    {
        $this->meta = $meta;
    }

    /**
     * Create and return the view content required to modify the settings of this gateway
     *
     * @param array $meta An array of meta (settings) data belonging to this gateway
     * @return string HTML content containing the fields to update the meta data for this gateway
     */
    public function getSettings(array $meta = null)
    {
        // Load the view into this object, so helpers can be automatically add to the view
        $this->view = new View('settings', 'default');
        $this->view->setDefaultView('components' . DS . 'gateways' . DS . 'nonmerchant' . DS . 'payssion' . DS);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('meta', $meta);
        $select_options = [
            'live' => Language::_('Payssion.mode.live', true),
            'sandbox' => Language::_('Payssion.mode.sandbox', true)
        ];
        $this->view->set('select_options', $select_options);

        return $this->view->fetch();
    }

    /**
     * Validates the given meta (settings) data to be updated for this gateway
     *
     * @param array $meta An array of meta (settings) data to be updated for this gateway
     * @return array The meta data to be updated in the database for this gateway, or reset into the form on failure
     */
    public function editSettings(array $meta)
    {
        $rules = [
            'api_key' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Payssion.!error.api_key.empty', true)
                ]
            ],
            'api_secret' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Payssion.!error.api_secret.empty', true)
                ]
            ],
            'mode' => [
                'valid' => [
                    'if_set' => true,
                    'rule' => ['in_array', ['live', 'sandbox']],
                    'message' => Language::_('Payssion.!error.mode.invalid', true)
                ]
            ],
            'payment_method' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Payssion.!error.payment_method.empty', true)
                ]
            ]
        ];
        $this->Input->setRules($rules);

        // Validate the given meta data to ensure it meets the requirements
        $this->Input->validates($meta);

        // Return the meta data, no changes required regardless of success or failure for this gateway
        return $meta;
    }

    /**
     * Returns an array of all fields to encrypt when storing in the database
     *
     * @return array An array of the field names to encrypt when storing in the database
     */
    public function encryptableFields()
    {
        return ['api_key', 'api_secret'];
    }

    /**
     * Sets the currency code to be used for all subsequent payments
     *
     * @param string $currency The ISO 4217 currency code to be used for subsequent payments
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
    }

    private function getSig($params, $secret_key)
    {
        $msg_array = array();
    	foreach ($params as $key => $value) {
    		$msg_array[$key] = $value;
    	}
    	$msg_array['secret_key'] = $secret_key;
    	$msg = implode('|', $msg_array);
    	$sig = md5($msg);
        return $sig;
    }

    /**
     * Returns all HTML markup required to render an authorization and capture payment form
     *
     * @param array $contact_info An array of contact info including:
     * @return string HTML markup required to render an authorization and capture payment form
     */
    public function buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)
    {
        if (isset($_POST['pm_id'])) {
            file_put_contents('/var/log/Payssion_blesta_create.log', 'Payment Method ID : ' . $_POST['pm_id'] . PHP_EOL, FILE_APPEND);
        }
        // Force 2-decimal places only
        $amount = round($amount, 2);
        if (isset($options['recur']['amount'])) {
            $options['recur']['amount'] = round($options['recur']['amount'], 2);
        }

        // Initialize API
        if ($this->meta['mode'] == 'sandbox') {
            $client = new PayssionClient($this->meta['api_key'], $this->meta['api_secret'], false);
            $this->payssionUrl = 'https://sandbox.payssion.com/payment/create.html';
        } else {
            $client = new PayssionClient($this->meta['api_key'], $this->meta['api_secret']);
            $this->payssionUrl = 'https://www.payssion.com/payment/create.html';
        }
        Loader::loadModels($this, ['Contacts']);
        $contact_numbers = $this->Contacts->getNumbers($contact_info['id'], 'phone');
        $customer_info = $this->Contacts->get($contact_info['id']);
        // Set invoice parameters
        $fees = (3/100) * $amount;
        $orderId = base64_encode($this->serializeInvoices($invoice_amounts));
        $params = [
            'order_id' => $orderId,
            'amount' => $amount,
            'payer_name' => ($contact_info['first_name'] ?? null) . ' ' . ($contact_info['last_name'] ?? null),
            'payer_email' => ($customer_info->email ?? null),
            'description' => $invoice_amounts[0]->id != null ? 'Payment for invoice #' . ($invoice_amounts[0]->id) : 'Payment for invoice',
            'return_url' => ($options['return_url']  . "&invoice_id=" . ($invoice_amounts[0]->id ?? null) ?? null),
            'currency' => 'USD',
            'api_key' => $this->meta['api_key'],
            'pm_id' => isset($_POST['payment_method']) ? $_POST['payment_method'] : null,
            'api_sig' => isset($_POST['payment_method']) ? $this->getSig(['api_key' => $this->meta['api_key'], 'pm_id' => $_POST['payment_method'], 'amount' => $amount, 'currency' => 'USD', 'order_id' => $orderId], $this->meta['api_secret']) : null,
            // 3% from total amount
        ];

        $this->log('buildProcess', json_encode($params), 'input', true);
    

        $callbackUrl = Configure::get('Blesta.gw_callback_url')
        . Configure::get('Blesta.company_id') . '/payssion/?client_id='
        . ($contact_info['client_id'] ?? null);

        $this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS));
        $this->view->set('post_to', isset($_POST['payment_method']) && $params['api_sig'] != null ? $this->payssionUrl : null);

        $payment_options = [
            'qris_id' => Language::_('Payssion.payment_method.qris_id', true),
            'atm_id' => Language::_('Payssion.payment_method.atm_id', true),
            'dana_id' => Language::_('Payssion.payment_method.dana_id', true),
            'ovo_id' => Language::_('Payssion.payment_method.ovo_id', true),
            'enets_sg' => Language::_('Payssion.payment_method.enets_sg', true),
            'paynow_sg' => Language::_('Payssion.payment_method.paynow_sg', true),
            'alipay_cn' => Language::_('Payssion.payment_method.alipay_cn', true),
            'upi_in' => Language::_('Payssion.payment_method.upi_in', true),
            'paytm_in' => Language::_('Payssion.payment_method.paytm_in', true),
            'bankcard_tr' => Language::_('Payssion.payment_method.bankcard_tr', true),
            'gcash_ph' => Language::_('Payssion.payment_method.gcash_ph', true),
            'grabpay_ph' => Language::_('Payssion.payment_method.grabpay_ph', true),
            'kakaopay_kr' => Language::_('Payssion.payment_method.kakaopay_kr', true),
            'creditcard_kr' => Language::_('Payssion.payment_method.creditcard_kr', true),
            'grabpay_my' => Language::_('Payssion.payment_method.grabpay_my', true),
        ];

        $this->view->set('payment_options', $payment_options);
        $this->view->set('fields', $params);

        // Load the helpers required for this view
        Loader::loadHelpers($this, ['Form', 'Html']);

        return $this->view->fetch();
    }

    /**
     * Validates the incoming POST/GET response from the gateway to ensure it is
     * legitimate and can be trusted.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, sets any errors using Input if the data fails to validate
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this
     *      transaction's original transaction (in the case of refunds)
     */
    public function validate(array $get, array $post)
    {
        // Initialize API
        $php_input = file_get_contents('php://input');
        $json = json_decode($php_input, true);
        
        $pm_id = $json['pm_id'];
        $amount = $json['amount'];
        $currency = $json['currency'];
        $order_id = $json['order_id'];
        $state = $json['state'];

        $check_array = array(
                $api_key,
                $pm_id,
                $amount,
                $currency,
                $order_id,
                $state,
                $secret_key
        );

        $check_msg = implode('|', $check_array);
        $check_sig = md5($check_msg);
        $notify_sig = $json['notify_sig'];

        if ($notify_sig != $check_sig) {
            $this->Input->setErrors(['invalid', ['response' => 'Invalid signature']]);
        }

        $invoice_details = $this->unserializeInvoices(base64_decode($order_id));
        $invoice_id = count($invoice_details) > 0 ? (int)$invoice_details[0]['id'] : null;
        $current_invoice_status = 'UNPAID';
        $client_id = null;
        if ($invoice_id) {
            Loader::loadModels($this, ['Invoices', 'Contacts']);
            $data = $this->Invoices->get($invoice_id);
            $current_invoice_status = $data->status == 'active' || $data->status == 'paid' || $data->status == 'approved' ? 'PAID' : 'UNPAID';
            $client_id = $data != null ? $data->client_id : null;
        }
        if ($current_invoice_status == 'PAID') {
            $this->Input->setErrors(['invalid', ['response' => 'Invoice already paid']]);
        }

        $paymentStatus = isset($state) ? $state : null;
        $invoice_id = isset($order_id) ? $order_id : null;
        if (!$invoice_id) {
            $this->Input->setErrors(['invalid', ['response' => 'Invalid invoice id']]);
        }

        $status = 'error';
        $success = false;
        if (isset($paymentStatus)) {
            $success = true;
            switch ($paymentStatus) {
                case 'completed':
                    $status = 'approved';
                    break;
                case 'paid_partial':
                    $status = 'approved';
                    break;
                case 'failed':
                    $status = 'declined';
                    break;
                case 'expired':
                    $status = 'declined';
                    break;
                default:
                    $status = 'pending';
                    break;
            }
        }

        if (!$success) {
            return;
        }

        $total_amount = 0;
        $invoices = $this->unserializeInvoices(base64_decode($order_id));
        foreach ($invoices as $invoice) {
            $total_amount += (int)$invoice['amount'];
        }
        $paid_amount = $paymentStatus == 'completed' ? $total_amount : 0;
        $transaction_id = $this->unserializeInvoices(base64_decode($order_id));
        $transaction_id = count($transaction_id) > 0 ? $transaction_id[0]['id'] : null;
        $params = [
            'client_id' => ($client_id ?? null),
            'amount' => ($paid_amount ?? null),
            'currency' => 'USD',
            'invoices' => $this->unserializeInvoices(base64_decode($order_id)),
            'status' => $status,
            'reference_id' => ($transaction_id?? null),
            'transaction_id' => ($json['transaction_id']  ?? null),
            'parent_transaction_id' => null
        ];
        file_put_contents('/var/log/Payssion_blesta.log', json_encode($params) . PHP_EOL, FILE_APPEND);
        return $params;
    }

    /**
     * Returns data regarding a success transaction. This method is invoked when
     * a client returns from the non-merchant gateway's web site back to Blesta.
     *
     * @param array $get The GET data for this request
     * @param array $post The POST data for this request
     * @return array An array of transaction data, may set errors using Input if the data appears invalid
     *  - client_id The ID of the client that attempted the payment
     *  - amount The amount of the payment
     *  - currency The currency of the payment
     *  - invoices An array of invoices and the amount the payment should be applied to (if any) including:
     *      - id The ID of the invoice to apply to
     *      - amount The amount to apply to the invoice
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - transaction_id The ID returned by the gateway to identify this transaction
     *  - parent_transaction_id The ID returned by the gateway to identify this transaction's original transaction
     */
    public function success(array $get, array $post)
    {
        // Initialize API
        if ($this->meta['mode'] == 'sandbox') {
            $client = new PayssionClient($this->meta['api_key'], $this->meta['api_secret'], false);
        } else {
            $client = new PayssionClient($this->meta['api_key'], $this->meta['api_secret']);
        }
        // Get transaction
        try {
            $order_id = $get['order_id'];
            $transaction = $client->getDetails(['order_id' => $order_id]);
            file_put_contents('/var/log/Payssion_blesta_return.log', json_encode($transaction) . PHP_EOL, FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents('/var/log/Payssion_blesta_error.log', $e->getMessage() . PHP_EOL, FILE_APPEND);
            $this->Input->setErrors(['invalid' => ['response' => $e->getMessage()]]);
            return;
        }

        // Set status
        $status = 'error';
        $paymentStatus = isset($transaction['state']) ? $transaction['state'] : null;
        if (isset($paymentStatus)) {
            switch ($paymentStatus) {
                case 'completed':
                    $status = 'approved';
                    break;
                case 'paid_partial':
                    $status = 'approved';
                    break;
                case 'failed':
                    $status = 'declined';
                    break;
                case 'expired':
                    $status = 'pending';
                    break;
                case 'error':
                    $status = 'declined';
                    break;
                default:
                    $status = 'pending';
                    break;
            }
        }

        $total_amount = $transaction['amount'] - ((3/100) * $transaction['amount']);

        $received_amount = $transaction['state'] == 'completed' ? $total_amount  : 0;

        $params = [
            'client_id' => ($get['client_id'] ?? null),
            'amount' => ($received_amount ?? null),
            'currency' => 'USD',
            'invoices' => $this->unserializeInvoices(base64_decode($get['order_id'])),
            'status' => $status,
            'transaction_id' => ($get['transaction_id'] ?? null),
            'parent_transaction_id' => null
        ];

        file_put_contents('/var/log/Payssion_blesta.log', "RETURN <br/>" . json_encode($params) . PHP_EOL, FILE_APPEND);

        return $params;
    }

    /**
     * Refund a payment
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param float $amount The amount to refund this transaction
     * @param string $notes Notes about the refund that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function refund($reference_id, $transaction_id, $amount, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Void a payment or authorization.
     *
     * @param string $reference_id The reference ID for the previously submitted transaction
     * @param string $transaction_id The transaction ID for the previously submitted transaction
     * @param string $notes Notes about the void that may be sent to the client by the gateway
     * @return array An array of transaction data including:
     *  - status The status of the transaction (approved, declined, void, pending, reconciled, refunded, returned)
     *  - reference_id The reference ID for gateway-only use with this transaction (optional)
     *  - transaction_id The ID returned by the remote gateway to identify this transaction
     *  - message The message to be displayed in the interface in addition to the standard
     *      message for this transaction status (optional)
     */
    public function void($reference_id, $transaction_id, $notes = null)
    {
        $this->Input->setErrors($this->getCommonError('unsupported'));
    }

    /**
     * Serializes an array of invoice info into a string
     *
     * @param array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     * @return string A serialized string of invoice info in the format of key1=value1|key2=value2
     */
    private function serializeInvoices(array $invoices)
    {
        $str = '';
        foreach ($invoices as $i => $invoice) {
            $str .= ($i > 0 ? '|' : '') . $invoice['id'] . '=' . $invoice['amount'];
        }
        return $str;
    }

    /**
     * Unserializes a string of invoice info into an array
     *
     * @param string A serialized string of invoice info in the format of key1=value1|key2=value2
     * @return array A numerically indexed array invoices info including:
     *  - id The ID of the invoice
     *  - amount The amount relating to the invoice
     */
    private function unserializeInvoices($str)
    {
        $invoices = [];
        $temp = explode('|', $str);
        foreach ($temp as $pair) {
            $pairs = explode('=', $pair, 2);
            if (count($pairs) != 2) {
                continue;
            }
            $invoices[] = ['id' => $pairs[0], 'amount' => $pairs[1]];
        }
        return $invoices;
    }
}
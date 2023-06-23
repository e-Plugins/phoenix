<?php
/**
 * Digiwallet Payment Module for osCommerce
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license   see LICENSE.TXT
 */
$ywincludefile = realpath(dirname(__FILE__) . '/../../../extra_datafiles/digiwallet.php');
require_once $ywincludefile;

$ywincludefile = realpath(dirname(__FILE__) . '/../../../languages/dutch/modules/payment/digiwallet.php');
require_once $ywincludefile;

if(!defined('TABLE_ORDERS')) {
    require_once realpath(dirname(__FILE__) . '/database_tables.php');
}

require_once realpath(dirname(__FILE__) . '/compatibility.php');

$ywincludefile = realpath(dirname(__FILE__) . '/digiwallet.class.php');
require_once $ywincludefile;

class digiwalletpayment extends abstract_payment_module
{

    const DEFAULT_RTLO = 156187;

    const DEFAULT_DIGIWALLET_TOKEN = 'bf72755a648832f48f0995454';

    public $code;

    public $title;

    public $public_title;

    public $payment_icon;

    public $description;

    public $enabled;

    public $sort_order = 0;

    public $rtlo;

    public $passwordKey;

    public $merchantReturnURL;

    public $expirationPeriod;

    public $transactionDescription;

    public $transactionDescriptionText;

    public $returnURL;

    public $reportURL;

    public $transactionID;

    public $purchaseID;

    public $directoryUpdateFrequency;

    public $error;

    public $bankUrl;

    public $config_code = "IDE";

    const CONFIG_KEY_BASE = 'MODULE_PAYMENT_DIGIWALLET';
    /**
     *
     * @method digiwallet inits the module
     */
    public function __construct()
    {
        parent::__construct();

        global $order;

        $this->code = 'digiwallet_' . strtolower($this->config_code);
        $this->title = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TEXT_TITLE");
        $this->public_title = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TEXT_PUBLIC_TITLE");
        $this->payment_icon = $this->tep_image('images/icons/' . $this->config_code . '_50.png', '', '', '', 'align=absmiddle');
        $this->description = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TEXT_DESCRIPTION") . $this->getConstant("MODULE_PAYMENT_DIGIWALLET_TESTMODE_WARNING_MESSAGE");
        $sort_order = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_SORT_ORDER");
        if(!empty($sort_order)) {
            $this->sort_order = $sort_order;
        }
        $this->enabled = (($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_STATUS") == 'True') ? true : false);

        $this->rtlo = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_DIGIWALLET_RTLO");

        $this->transactionDescription = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TRANSACTION_DESCRIPTION");
        $this->transactionDescriptionText = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_MERCHANT_TRANSACTION_DESCRIPTION_TEXT");

        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_REPAIR_ORDER") === true) {
            if ($_GET['digiwallet_transaction_id']) {
                $_SESSION['digiwallet_repair_transaction_id'] = $this->tep_site_db_input($_GET['digiwallet_transaction_id']);
            }
            $this->transactionID = $_SESSION['digiwallet_repair_transaction_id'];
        }
    }

    protected function get_parameters()
    {
        return [];
    }

    /**
     * Get the defined constant values
     *
     * @param unknown $key
     * @return mixed|boolean
     */
    public function getConstant($key)
    {
        if (defined($key)) {
            return constant($key);
        }
        return false;
    }

    /**
     * update module status
     */
    public function update_status()
    {
        global $order, $db;

        if (($this->enabled == true) && ((int) $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ZONE") > 0)) {
            $check_flag = false;
            $check = $GLOBALS['db']->query("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ZONE") . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");

            while (! $check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }
            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    /**
     * get bank directory
     */
    public function getDirectory()
    {
        $issuerList = array();

        $objDigiCore = new DigiWalletCore($this->config_code, $this->rtlo);

        $bankList = $objDigiCore->getBankList();
        foreach ($bankList as $issuerID => $issuerName) {
            $i = new stdClass();
            $i->issuerID = $issuerID;
            $i->issuerName = $issuerName;
            $i->issuerList = 'short';
            array_push($issuerList, $i);
        }
        return $issuerList;
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see digiwallet::selection()
     */
    public function selection()
    {
        return array(
            'id' => $this->code,
            'module' => $this->payment_icon
        );
    }

    /**
     * Don't check javascript validation
     *
     * @return boolean
     */
    public function javascript_validation()
    {
        return false;
    }

    /**
     */
    public function pre_confirmation_check()
    {
        global $cartID, $cart;

        if (empty($cart->cartID)) {
            $cartID = $cart->cartID = $cart->generate_cart_id();
        }

        if (!isset($_SESSION['cartID'])) {
            $_SESSION['cartID'] = $cartID;
        }
    }

    /**
     * prepare the transaction and send user back on error or forward to bank
     */
    public function prepareTransaction()
    {
        global $order, $currencies, $customer_id, $db, $messageStack, $order_totals, $cart_digiwallet_id;

        list ($void, $customOrderId) = explode("-", $cart_digiwallet_id);

        $customer = new customer($customer_id);
        $payment_purchaseID = time();
        $payment_issuer = $this->config_code;
        $payment_currency = "EUR"; // future use
        $payment_language = "nl"; // future use
        $payment_amount = round($order->info['total'] * 100, 0);
        $payment_entranceCode = session_id();
        if ((strtolower($this->transactionDescription) == 'automatic') && (count($order->products) == 1)) {
            $product = $order->products[0];
            $payment_description = $product['name'];
        } else {
            $payment_description = 'Order:' . $customOrderId . ' ' . $this->transactionDescriptionText;
        }
        $payment_description = trim(strip_tags($payment_description));
        // This function has been DEPRECATED as of PHP 5.3.0. Relying on this feature is highly discouraged.
        // $payment_description = ereg_replace("[^( ,[:alnum:])]", '*', $payment_description);
        $payment_description = preg_replace("/[^a-zA-Z0-9\s]/", '', $payment_description);
        $payment_description = substr($payment_description, 0, 31); /* Max. 32 characters */
        if (empty($payment_description)) {
            $payment_description = 'nvt';
        }

        $iTest = false;//($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TESTACCOUNT") == "True") ? 1 : 0;
        $objDigiCore = new DigiWalletCore($payment_issuer, $this->rtlo, 'nl', $iTest);
        $objDigiCore->setAmount($payment_amount);
        $objDigiCore->setDescription($payment_description);

        if(!empty($_POST['bankID'])) {  //for ideal
            $objDigiCore->setBankId($_POST['bankID']);
        }
        if(!empty($_POST['countryID'])) {   //for sorfor
            $objDigiCore->setBankId($_POST['countryID']);
        }

        $objDigiCore->setReturnUrl(DigiWalletCore::formatOscommerceUrl($this->tep_site_href_link('ext/modules/payment/digiwallet/callback.php?type=' . $this->config_code, '', 'SSL') . "&finished=1"));
        $objDigiCore->setReportUrl(DigiWalletCore::formatOscommerceUrl($this->tep_site_href_link('ext/modules/payment/digiwallet/callback.php?type=' . $this->config_code, '', 'SSL')));
        $objDigiCore->setCancelUrl(DigiWalletCore::formatOscommerceUrl($this->tep_site_href_link('ext/modules/payment/digiwallet/callback.php?type=' . $this->config_code, '', 'SSL') . "&cancel=1"));

        // Consumer's email address
        if(isset($order->customer['email_address']) && !empty($order->customer['email_address'])) {
            $objDigiCore->bindParam("email", $order->customer['email_address']);
        }

        $result = @$objDigiCore->startPayment();

        if ($result === false) {
            $messageStack->add_session('checkout_payment', $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . "<br/>" . $objDigiCore->getErrorMessage());
            Href::redirect($this->tep_site_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . " " . $objDigiCore->getErrorMessage()), 'SSL', true, false));
        }

        $this->transactionID = $objDigiCore->getTransactionId();

        $this->bankUrl = $objDigiCore->getBankUrl();

        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_EMAIL_ORDER_INIT") == 'True') {
            $email_text = 'Er is zojuist een Digiwallet iDeal bestelling opgestart' . "\n\n";
            $email_text .= 'Details:' . "\n";
            $email_text .= 'customer_id: ' . $_SESSION['customer_id'] . "\n";
            $email_text .= 'customer_first_name: ' . $_SESSION['customer_first_name'] . "\n";
            $email_text .= 'Digiwallet transaction_id: ' . $this->transactionID . "\n";
            $email_text .= 'bedrag: ' . $payment_amount . ' (' . $payment_currency . 'x100)' . "\n";
            $max_orders_id = $GLOBALS['db']->query("select max(orders_id) orders_id from " . TABLE_ORDERS);
            $new_order_id = $max_orders_id->fields['orders_id'] + 1;
            $email_text .= 'order_id: ' . $new_order_id . ' (verwacht indien de bestelling wordt voltooid, kan ook hoger zijn)' . "\n";
            $email_text .= "\n\n";
            $email_text .= 'Digiwallet transactions id: ' . $this->transactionID . "\n";

            Notifications::mail(STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS, '[iDeal bestelling opgestart] #' . $new_order_id . ' (?)', $email_text, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
        }

        $GLOBALS['db']->query("INSERT INTO " . TABLE_DIGIWALLET_TRANSACTIONS . "
		    		(
		    		`transaction_id`,
		    		`rtlo`,
		    		`purchase_id`,
		    		`issuer_id`,
		    		`transaction_status`,
		    		`datetimestamp`,
		    		`customer_id`,
		    		`amount`,
		    		`currency`,
		    		`session_id`,
		    		`ideal_session_data`,
		    		`order_id`
		    		) VALUES (
		    		'" . $this->transactionID . "',
		    		'" . $this->rtlo . "',
		    		'" . $payment_purchaseID . "',
		    		'" . $payment_issuer . "',
		    		'open',
		    		NOW( ),
		    		'" . $_SESSION['customer_id'] . "',
		    		'" . $payment_amount . "',
		    		'" . $payment_currency . "',
		    		'" . $this->tep_site_db_input(session_id()) . "',
		    		'" . base64_encode(serialize($_SESSION)) . "',
		    		'" . $customOrderId . "'
		    		);");
        Href::redirect(html_entity_decode($this->bankUrl));
    }

    /**
     *
     * @return false
     */
    public function confirmation()
    {
        global $cartID, $cart_digiwallet_id, $customer_id, $languages_id, $order, $order_total_modules;
        if (isset($cartID)) {
            $insert_order = false;

            if (isset($_SESSION['cart_digiwallet_id'])) {
                $order_id = substr($cart_digiwallet_id, strpos($cart_digiwallet_id, '-') + 1);

                $curr_check = $GLOBALS['db']->query("select currency, payment_method from " . TABLE_ORDERS . " where orders_id = '" . (int) $order_id . "'");
                $curr = $this->tep_db_fetch_array($curr_check);

                if (($curr['currency'] != $order->info['currency']) || ($curr['payment_method'] != $order->info['payment_method']) || ($cartID != substr($cart_digiwallet_id, 0, strlen($cartID)))) {
                    $check_query = $GLOBALS['db']->query('select orders_id from ' . TABLE_ORDERS_STATUS_HISTORY . ' where orders_id = "' . (int) $order_id . '" limit 1');

                    if (mysqli_num_rows($check_query) < 1) {
                        $GLOBALS['db']->query('delete from ' . TABLE_ORDERS . ' where orders_id = "' . (int) $order_id . '"');
                        $GLOBALS['db']->query('delete from ' . TABLE_ORDERS_TOTAL . ' where orders_id = "' . (int) $order_id . '"');
                        $GLOBALS['db']->query('delete from ' . TABLE_ORDERS_STATUS_HISTORY . ' where orders_id = "' . (int) $order_id . '"');
                        $GLOBALS['db']->query('delete from ' . TABLE_ORDERS_PRODUCTS . ' where orders_id = "' . (int) $order_id . '"');
                        $GLOBALS['db']->query('delete from ' . TABLE_ORDERS_PRODUCTS_ATTRIBUTES . ' where orders_id = "' . (int) $order_id . '"');
                        $GLOBALS['db']->query('delete from ' . TABLE_ORDERS_PRODUCTS_DOWNLOAD . ' where orders_id = "' . (int) $order_id . '"');
                    }
                    $insert_order = true;
                }
            } else {
                $insert_order = true;
            }

            if ($insert_order == true) {
                $order_totals = $order->totals;

                $sql_data_array = array(
                    'customers_id' => $customer_id,
                    'customers_name' => $order->customer['firstname'] . ' ' . $order->customer['lastname'],
                    'customers_company' => isset($order->customer['company']) ? $order->customer['company'] : '',
                    'customers_street_address' => isset($order->customer['street_address']) ? $order->customer['street_address'] : '',
                    'customers_suburb' => isset($order->customer['suburb']) ? $order->customer['suburb'] : '',
                    'customers_city' => isset($order->customer['city']) ? $order->customer['city'] : '',
                    'customers_postcode' => isset($order->customer['postcode']) ? $order->customer['postcode'] : '',
                    'customers_state' => isset($order->customer['state']) ? $order->customer['state'] : '',
                    'customers_country' => isset($order->customer['country']['title']) ? $order->customer['country']['title'] : '',
                    'customers_telephone' => isset($order->customer['telephone']) ? $order->customer['telephone'] : '',
                    'customers_email_address' => isset($order->customer['email_address']) ? $order->customer['email_address'] : '',
                    'customers_address_format_id' => isset($order->customer['format_id']) ? $order->customer['format_id'] : '',
                    'delivery_name' => (isset($order->delivery['firstname']) ? $order->delivery['firstname'] : '') . ' ' . (isset($order->delivery['lastname']) ? $order->delivery['lastname'] : ''),
                    'delivery_company' => isset($order->delivery['company']) ? $order->delivery['company'] : '',
                    'delivery_street_address' => isset($order->delivery['street_address']) ? $order->delivery['street_address'] : '',
                    'delivery_suburb' => isset($order->delivery['suburb']) ? $order->delivery['suburb'] : '',
                    'delivery_city' => isset($order->delivery['city']) ? $order->delivery['city'] : '',
                    'delivery_postcode' => isset($order->delivery['postcode']) ? $order->delivery['postcode'] : '',
                    'delivery_state' => isset($order->delivery['state']) ? $order->delivery['state'] : '',
                    'delivery_country' => isset($order->delivery['country']['title']) ? $order->delivery['country']['title'] : '',
                    'delivery_address_format_id' => isset($order->delivery['format_id']) ? $order->delivery['format_id'] : '',
                    'billing_name' => (isset($order->billing['firstname']) ? $order->billing['firstname'] : '') . ' ' . (isset($order->billing['lastname']) ? $order->billing['lastname'] : ''),
                    'billing_company' => isset($order->billing['company']) ? $order->billing['company'] : '',
                    'billing_street_address' => isset($order->billing['street_address']) ? $order->billing['street_address'] : '',
                    'billing_suburb' => isset($order->billing['suburb']) ? $order->billing['suburb'] : '',
                    'billing_city' => isset($order->billing['city']) ? $order->billing['city'] : '',
                    'billing_postcode' => isset($order->billing['postcode']) ? $order->billing['postcode'] : '',
                    'billing_state' => isset($order->billing['state']) ? $order->billing['state'] : '',
                    'billing_country' => isset($order->billing['country']['title']) ? $order->billing['country']['title'] : '',
                    'billing_address_format_id' => isset($order->billing['format_id']) ? $order->billing['format_id'] : '',
                    'payment_method' => isset($order->info['payment_method']) ? $order->info['payment_method'] : '',
                    'cc_type' => isset($order->info['cc_type']) ? $order->info['cc_type'] : '',
                    'cc_owner' => isset($order->info['cc_owner']) ? $order->info['cc_owner'] : '',
                    'cc_number' => isset($order->info['cc_number']) ? $order->info['cc_number'] : '',
                    'cc_expires' => isset($order->info['cc_expires']) ? $order->info['cc_expires'] : '',
                    'date_purchased' => 'now()',
                    'orders_status' => isset($order->info['order_status']) ? $order->info['order_status'] : '',
                    'currency' => isset($order->info['currency']) ? $order->info['currency'] : '',
                    'currency_value' => isset($order->info['currency_value']) ? $order->info['currency_value'] : ''
                );

                $GLOBALS['db']->perform(TABLE_ORDERS, $sql_data_array);

                $insert_id = $GLOBALS['db']->insert_id;

                for ($i = 0, $n = sizeof($order_totals); $i < $n; $i ++) {
                    $sql_data_array = array(
                        'orders_id' => $insert_id,
                        'title' => $order_totals[$i]['title'],
                        'text' => $order_totals[$i]['text'],
                        'value' => $order_totals[$i]['value'],
                        'class' => $order_totals[$i]['code'],
                        'sort_order' => $order_totals[$i]['sort_order']
                    );
                    $GLOBALS['db']->perform(TABLE_ORDERS_TOTAL, $sql_data_array);
                }

                for ($i = 0, $n = sizeof($order->products); $i < $n; $i ++) {
                    $sql_data_array = array(
                        'orders_id' => $insert_id,
                        'products_id' => Product::build_prid($order->products[$i]['id']),
                        'products_model' => $order->products[$i]['model'],
                        'products_name' => $order->products[$i]['name'],
                        'products_price' => $order->products[$i]['price'],
                        'final_price' => $order->products[$i]['final_price'],
                        'products_tax' => $order->products[$i]['tax'],
                        'products_quantity' => $order->products[$i]['qty']
                    );

                    $GLOBALS['db']->perform(TABLE_ORDERS_PRODUCTS, $sql_data_array);

                    $order_products_id = $GLOBALS['db']->insert_id;
                    $attributes_exist = '0';
                    if (isset($order->products[$i]['attributes'])) {
                        $attributes_exist = '1';
                        for ($j = 0, $n2 = sizeof($order->products[$i]['attributes']); $j < $n2; $j ++) {
                            if (DOWNLOAD_ENABLED == 'true') {
                                $attributes_query = "select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix, pad.products_attributes_maxdays, pad.products_attributes_maxcount , pad.products_attributes_filename
														from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa
														left join " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
														on pa.products_attributes_id=pad.products_attributes_id
														where pa.products_id = '" . $order->products[$i]['id'] . "'
														and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "'
														and pa.options_id = popt.products_options_id
														and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "'
														and pa.options_values_id = poval.products_options_values_id
														and popt.language_id = '" . $languages_id . "'
														and poval.language_id = '" . $languages_id . "'";
                                $attributes = $GLOBALS['db']->query($attributes_query);
                            } else {
                                $attributes = $GLOBALS['db']->query("select popt.products_options_name, poval.products_options_values_name, pa.options_values_price, pa.price_prefix from " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_OPTIONS_VALUES . " poval, " . TABLE_PRODUCTS_ATTRIBUTES . " pa where pa.products_id = '" . $order->products[$i]['id'] . "' and pa.options_id = '" . $order->products[$i]['attributes'][$j]['option_id'] . "' and pa.options_id = popt.products_options_id and pa.options_values_id = '" . $order->products[$i]['attributes'][$j]['value_id'] . "' and pa.options_values_id = poval.products_options_values_id and popt.language_id = '" . $languages_id . "' and poval.language_id = '" . $languages_id . "'");
                            }
                            $attributes_values = $this->tep_db_fetch_array($attributes);

                            $sql_data_array = array(
                                'orders_id' => $insert_id,
                                'orders_products_id' => $order_products_id,
                                'products_options' => $attributes_values['products_options_name'],
                                'products_options_values' => $attributes_values['products_options_values_name'],
                                'options_values_price' => $attributes_values['options_values_price'],
                                'price_prefix' => $attributes_values['price_prefix']
                            );

                            $GLOBALS['db']->perform(TABLE_ORDERS_PRODUCTS_ATTRIBUTES, $sql_data_array);

                            if ((DOWNLOAD_ENABLED == 'true') && isset($attributes_values['products_attributes_filename']) && !empty($attributes_values['products_attributes_filename'])) {
                                $sql_data_array = array(
                                    'orders_id' => $insert_id,
                                    'orders_products_id' => $order_products_id,
                                    'orders_products_filename' => $attributes_values['products_attributes_filename'],
                                    'download_maxdays' => $attributes_values['products_attributes_maxdays'],
                                    'download_count' => $attributes_values['products_attributes_maxcount']
                                );

                                $GLOBALS['db']->perform(TABLE_ORDERS_PRODUCTS_DOWNLOAD, $sql_data_array);
                            }
                        }
                    }
                }

                $cart_digiwallet_id = $cartID . '-' . $insert_id;
                $_SESSION['cart_digiwallet_id'] = $cart_digiwallet_id;
            }
        }
        return false;
    }

    /**
     * make hidden value for payment system
     */
    public function process_button()
    {
        $process_button = $this->tep_draw_hidden_field('payment_code', $this->config_code) . $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_EXPRESS_TEXT");

        return $process_button;
    }

    /**
     * before process check status or prepare transaction
     */
    public function before_process()
    {
        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_REPAIR_ORDER") === true) {
            global $order;
            // when repairing iDeal the transaction status is succes, set order status accordingly
            $order->info['order_status'] = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ORDER_STATUS_ID");
            return false;
        }
        if (isset($_GET['action']) && $_GET['action'] == "process") {
            $this->checkStatus();
        } else {
            $this->prepareTransaction();
        }
    }

    /**
     * check payment status
     */
    public function checkStatus()
    {
        global $order, $db, $messageStack;

        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_REPAIR_ORDER") === true) {
            return false;
        }
        $this->transactionID = $this->tep_site_db_input($_GET['trxid']);
        $method = $this->tep_site_db_input($_GET['method']);

        if ($this->transactionID == "") {
            $messageStack->add_session('checkout_payment', $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING"));
            Href::redirect($this->tep_site_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING")), 'SSL', true, false));
        }

        $iTest = false;//($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TESTACCOUNT") == "True") ? 1 : 0;

        $objDigiCore = new DigiWalletCore($method, $this->rtlo, 'nl', $iTest);
        $status = @$objDigiCore->checkPayment($this->transactionID);

        if ($objDigiCore->getPaidStatus()) {
            $realstatus = "success";
        } else {
            $realstatus = "open";
        }

        $customerInfo = $objDigiCore->getConsumerInfo();
        $consumerAccount = (((isset($customerInfo->consumerInfo["bankaccount"]) && ! empty($customerInfo->consumerInfo["bankaccount"])) ? $customerInfo->consumerInfo["bankaccount"] : ""));
        $consumerName = (((isset($customerInfo->consumerInfo["name"]) && ! empty($customerInfo->consumerInfo["name"])) ? $customerInfo->consumerInfo["name"] : ""));
        $consumerCity = (((isset($customerInfo->consumerInfo["city"]) && ! empty($customerInfo->consumerInfo["city"])) ? $customerInfo->consumerInfo["city"] : ""));

        $GLOBALS['db']->query("UPDATE " . TABLE_DIGIWALLET_TRANSACTIONS . " SET `transaction_status` = '" . $realstatus . "',`datetimestamp` = NOW( ) ,`consumer_name` = '" . $consumerName . "',`consumer_account_number` = '" . $consumerAccount . "',`consumer_city` = '" . $consumerCity . "' WHERE `transaction_id` = '" . $this->transactionID . "' LIMIT 1");

        switch ($realstatus) {
            case "success":
                $order->info['order_status'] = $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ORDER_STATUS_ID");
                break;
            default:
                $messageStack->add_session('checkout_payment', $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_TRANSACTION_OPEN"));
                $url = Guarantor::ensure_global('Linker')->link(FILENAME_CHECKOUT_PAYMENT, phoenix_parameterize('error_message=' . urlencode($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_TRANSACTION_OPEN"))), true);
                Href::redirect($url);
                break;
        }
    }

    /**
     * after order create set value in database
     *
     * @param
     *            $zf_order_id
     */
    public function after_order_create($zf_order_id)
    {
        $GLOBALS['db']->query("UPDATE " . TABLE_DIGIWALLET_TRANSACTIONS . " SET `order_id` = '" . $zf_order_id . "', `ideal_session_data` = '' WHERE `transaction_id` = '" . $this->transactionID . "' LIMIT 1 ;");
        if (isset($_SESSION['digiwallet_repair_transaction_id'])) {
            unset($_SESSION['digiwallet_repair_transaction_id']);
        }
    }

    /**
     * after process function
     *
     * @return false
     */
    public function after_process()
    {
        echo 'after process komt hier';
        return false;
    }

    /**
     * checks installation of module
     */
    public function check()
    {
        if (! isset($this->_check)) {
            $check_query = $GLOBALS['db']->query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_STATUS") . "'");
            $this->_check = mysqli_num_rows($check_query);
        }
        return $this->_check;
    }

    /**
     * install values in database
     */
    public function install($parameter_key = null)
    {
        parent::install($parameter_key);
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Digiwallet payment module', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_STATUS") . "', 'True', 'Do you want to accept Digiwallet payments?', '6', '1', 'Config::select_one(array(\'True\', \'False\'), ', now())");
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sortorder', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_SORT_ORDER") . "', '".$this->sort_order."', 'Sort order of payment methods in list. Lowest is displayed first.', '6', '2', now())");
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment zone', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ZONE") . "', '0', 'If a zone is selected, enable this payment method for that zone only.', '6', '3', 'geo_zone::fetch_name', 'Config::select_geo_zone(', now())");

        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Transaction description', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TRANSACTION_DESCRIPTION") . "', 'Automatic', 'Select automatic for product name as description, or manual to use the text you supply below.', '6', '8', 'Config::select_one(array(\'Automatic\',\'Manual\'), ', now())");
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Transaction description text', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_MERCHANT_TRANSACTION_DESCRIPTION_TEXT") . "', '" . TITLE . "', 'Description of transactions from this webshop. <strong>Should not be empty!</strong>.', '6', '8', now())");

        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Digiwallet Outlet Identifier', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_DIGIWALLET_RTLO") . "', ".self::DEFAULT_RTLO.", 'The Digiwallet layout code', '6', '4', now())"); // Default Digiwallet
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Digiwallet API Token', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_DIGIWALLET_API_TOKEN") . "', '".self::DEFAULT_DIGIWALLET_TOKEN."', 'The Digiwallet API Token', '6', '4', now())"); // Default Digiwallet

        // Remove testmode setting
        //$GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Testaccount?', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TESTACCOUNT") . "', 'False', 'Enable testaccount (only for validation)?', '6', '1', 'Config::select_one(array(\'True\', \'False\'), ', now())");

        //$GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('IP address', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_REPAIR_IP") . "', '" . $_SERVER['REMOTE_ADDR'] . "', 'The IP address of the user (administrator) that is allowed to complete open ideal orders (if empty everyone will be allowed, which is not recommended!).', '6', '8', now())");
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable pre order emails','" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_EMAIL_ORDER_INIT") . "', 'False', 'Do you want emails to be sent to the store owner whenever an Digiwallet order is being initiated? The default is <strong>False</strong>.', '6', '17', 'Config::select_one(array(\'True\', \'False\'), ', now())");

        $GLOBALS['db']->query("CREATE TABLE IF NOT EXISTS " . TABLE_DIGIWALLET_DIRECTORY . " (`issuer_id` VARCHAR( 4 ) NOT NULL ,`issuer_name` VARCHAR( 30 ) NOT NULL ,`issuer_issuerlist` VARCHAR( 5 ) NOT NULL ,`timestamp` DATETIME NOT NULL ,PRIMARY KEY ( `issuer_id` ) );");

        $GLOBALS['db']->query("CREATE TABLE IF NOT EXISTS " . TABLE_DIGIWALLET_TRANSACTIONS . " (`transaction_id` VARCHAR( 30 ) NOT NULL ,`rtlo` VARCHAR( 7 ) NOT NULL ,`purchase_id` VARCHAR( 30 ) NOT NULL , `issuer_id` VARCHAR( 25 ) NOT NULL , `session_id` VARCHAR( 128 ) NOT NULL ,`ideal_session_data`  MEDIUMBLOB NOT NULL ,`order_id` INT( 11 ),`transaction_status` VARCHAR( 10 ) ,`datetimestamp` DATETIME, `last_modified` TIMESTAMP NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP, `consumer_name` VARCHAR( 50 ) ,`consumer_account_number` VARCHAR( 20 ) ,`consumer_city` VARCHAR( 50 ), `customer_id` INT( 11 ), `amount` DECIMAL( 15, 4 ), `currency` CHAR( 3 ), `batch_id` VARCHAR( 30 ), PRIMARY KEY ( `transaction_id` ));");

        // Add more column for Afterpay and Bankwire payment
        $result = $GLOBALS['db']->query("SHOW COLUMNS FROM " . TABLE_DIGIWALLET_TRANSACTIONS . " LIKE 'more';");
        if (mysqli_num_rows($result) != 1) {
            // Add more columns
            $GLOBALS['db']->query("ALTER TABLE " . TABLE_DIGIWALLET_TRANSACTIONS . " ADD `more` TEXT default null;");
        }

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS;
        $status_query = $GLOBALS['db']->query($sql);

        $status = $this->tep_db_fetch_array($status_query);
        $status_id = $status['status_id'] + 1;
        $cancel = $status['status_id'] + 2;
        $error = $status['status_id'] + 3;
        $review = $status['status_id'] + 4;

        $languages = array_values(language::load_all());
        $orderStatusIds = array(
            $status_id => 'Payment Paid [digiwallet]',
            $cancel => 'Payment canceled [digiwallet]',
            $error => 'Payment error [digiwallet]',
            $review => 'Payment Review [digiwallet]',
        );
        foreach ($languages as $language) {
            foreach ($orderStatusIds as $orderStatusId => $orderStatusName) {
                $query = sprintf(
                    'SELECT 1 FROM %s WHERE `language_id` = %d AND `orders_status_name` = "%s"',
                    TABLE_ORDERS_STATUS,
                    $language['id'],
                    $orderStatusName
                );
                if (mysqli_num_rows($GLOBALS['db']->query($query)) == 0) {
                    $query = sprintf(
                        'INSERT INTO %s SET `orders_status_id` = %d, `language_id` = %d, `orders_status_name` = "%s"',
                        TABLE_ORDERS_STATUS,
                        $orderStatusId,
                        $language['id'],
                        $orderStatusName
                    );
                    $GLOBALS['db']->query($query);
                }
            }
        }
        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Paid [digiwallet]'";
        $status = $this->tep_db_fetch_array($GLOBALS['db']->query($sql));
        $status_id = $status['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment canceled [digiwallet]'";
        $status = $this->tep_db_fetch_array($GLOBALS['db']->query($sql));
        $cancel = $status['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment error [digiwallet]'";
        $status = $this->tep_db_fetch_array($GLOBALS['db']->query($sql));
        $error = $status['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Review [digiwallet]'";
        $status = $this->tep_db_fetch_array($GLOBALS['db']->query($sql));
        $review = $status['status_id'];

        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		                        values ('Set Paid Order Status', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PREPARE_ORDER_STATUS_ID") . "', '" . $status_id . "', 'Set the status of prepared orders to success', '6', '5', 'Config::select_order_status(', 'Config::select_order_status', now())");
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
                                values ('Set Payment Cancelled Order Status', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_CANCELLED") . "', '" . $cancel . "', 'The payment is cancelled by the enduser', '6', '6', 'Config::select_order_status(', 'Config::select_order_status', now())");
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		                        values ('Set Payment Error Order Status', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_ERROR") . "', '" . $error . "', 'The payment is error', '6', '7', 'Config::select_order_status(', 'Config::select_order_status', now())");
        $GLOBALS['db']->query("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		                        values ('Set Payment Review Status', '" . ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_REVIEW") . "', '" . $review . "', 'The payment is being reviewed when paid by Bankwire', '6', '8', 'Config::select_order_status(', 'Config::select_order_status', now())");
    }

    /**
     * Remove the module event
     */
    public function remove()
    {
        $GLOBALS['db']->query("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    /**
     * Configuration keys
     *
     * @return string[]
     */
    public function keys()
    {
        return array(
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_DIGIWALLET_RTLO"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_DIGIWALLET_API_TOKEN"),
            // ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TESTACCOUNT"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_ERROR"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PREPARE_ORDER_STATUS_ID"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_CANCELLED"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_PAYMENT_REVIEW"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_STATUS"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_SORT_ORDER"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ZONE"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_TRANSACTION_DESCRIPTION"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_MERCHANT_TRANSACTION_DESCRIPTION_TEXT"),
            ("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_EMAIL_ORDER_INIT")
        );
    }

    function tep_image(
        $src,
        $alt = '',
        $width = '',
        $height = '',
        $parameters = '',
        $responsive = true,
        $bootstrap_css = '')
    {
        $image = new Image($src, $this->phoenix_normalize($parameters));

        if (!Text::is_empty($alt)) {
            $image->set('alt', $alt);
        }

        if (!Text::is_empty($width)) {
            $image->set('width', $width);
        }

        if (!Text::is_empty($height)) {
            $image->set('height', $height);
        }

        if ($responsive !== true) {
            $image->set_responsive(false);
        }

        if (!Text::is_empty($bootstrap_css)) {
            $image->append_css($bootstrap_css);
        }

        return "$image";
    }

    function phoenix_normalize($attributes) {
        $parameters = [];
        foreach (preg_split('{"[^"]*"(*SKIP)(*FAIL)|\s+}', $attributes) as $parameter) {
            $pair = explode('=', $parameter, 2);
            if (!empty($pair[0])) {
                $parameters[$pair[0]] = isset($pair[1]) ? trim($pair[1], '"') : null;
            }
        }

        return $parameters;
    }

    function tep_db_fetch_array($db_query) {
        return $db_query->fetch_assoc();
    }
    function tep_draw_pull_down_menu($name, $values, $default = '', $parameters = '', $required = false) {
        $select = new Select($name, $values, $this->phoenix_normalize($parameters));

        if ( !empty($default) ) {
            $select->set_selection($default);
        }

        if ($required) {
            $select->set_required($required);
        }

        return $select;
    }

    function tep_site_href_link($page = '', $parameters = '', $connection = null, $add_session_id = true, $search_engine_safe = null) {
        return Guarantor::ensure_global('Linker')->build($page, phoenix_parameterize($parameters), $add_session_id);
    }


    function tep_draw_hidden_field($name, $value = '', $parameters = '') {
        $input = new Input($name, $this->phoenix_normalize($parameters), 'hidden');
        if (Text::is_empty($value)) {
            if ( is_string($requested_value = Request::value($name)) ) {
                $input->set('value', $requested_value);
            }
        } else {
            $input->set('value', $value);
        }

        return "$input";
    }
    function tep_site_db_input($string, $link = 'db') {
        return $GLOBALS[$link]->real_escape_string($string);
    }
}

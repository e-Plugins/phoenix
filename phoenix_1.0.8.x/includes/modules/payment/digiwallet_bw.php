<?php
/**
 * Digiwallet Payment Module for osCommerce
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license   see LICENSE.TXT
 */
$ywincludefile = realpath(dirname(__FILE__) . '/digiwallet/digiwalletpayment.class.php');
require_once $ywincludefile;

class digiwallet_bw extends digiwalletpayment
{
    /**
     *
     * @method digiwallet inits the module
     */
    public function __construct()
    {
        $this->sort_order = 4;
        $this->config_code = "BW";
        parent::__construct();
    }

    /**
     * prepare the transaction and send user back on error or forward to bank
     *
     * {@inheritDoc}
     * @see digiwalletpayment::prepareTransaction()
     */
    public function prepareTransaction()
    {
        global $order, $currencies, $customer_id, $db, $messageStack, $order_totals, $cart_digiwallet_id;

        list ($void, $customOrderId) = explode("-", $cart_digiwallet_id);

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
        $objDigiCore->bindParam('userip', $_SERVER["REMOTE_ADDR"]);

        $objDigiCore->setReturnUrl(DigiWalletCore::formatOscommerceUrl($this->tep_site_href_link('ext/modules/payment/digiwallet/callback.php', '', 'SSL') . '?finished=1&type=' . $this->config_code));
        $objDigiCore->setReportUrl(DigiWalletCore::formatOscommerceUrl($this->tep_site_href_link('ext/modules/payment/digiwallet/callback.php', '', 'SSL') . '?checksum=1&type=' . $this->config_code));
        $objDigiCore->setCancelUrl(DigiWalletCore::formatOscommerceUrl($this->tep_site_href_link('ext/modules/payment/digiwallet/callback.php', '', 'SSL') . '?cancel=1&type=' . $this->config_code));

        // Consumer's email address
        if(isset($order->customer['email_address']) && !empty($order->customer['email_address'])) {
            $objDigiCore->bindParam("email", $order->customer['email_address']);
        }

        $result = @$objDigiCore->startPayment();

        if ($result === false) {
            $messageStack->add_session('checkout_payment', $this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . "<br/>" . $objDigiCore->getErrorMessage());
            Href::redirect($this->tep_site_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode($this->getConstant("MODULE_PAYMENT_DIGIWALLET_" . $this->config_code . "_ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . " " . $objDigiCore->getErrorMessage()), 'SSL', true, false));
            exit(0);
        }

        $this->transactionID = $objDigiCore->getTransactionId();


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
            $email_text .= 'Digiwallet transactions id: ' .  $this->transactionID . "\n";

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
		    		`order_id`,
                    `more`
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
		    		'" . $customOrderId . "',
		    		'" . $this->tep_site_db_input($objDigiCore->getMoreInformation()) . "'
		    		);");
        // Check the result of payment
        $result_str = $objDigiCore->getMoreInformation();
        // Process return message
        // Update order as successfulll and redirect to information page
        // Clear current cart
        global $cart;
        if (empty($cart)) {
            $cart = new shoppingCart;
        }
        $cart->reset(true);
        $cart->contents = array();
        $cart->total = 0;
        $cart->weight = 0;
        $cart->content_type = false;

        // unregister session variables used during checkout
        unset($_SESSION['sendto']);
        unset($_SESSION['billto']);
        unset($_SESSION['shipping']);
        unset($_SESSION['payment']);
        unset($_SESSION['comments']);
        $cart->reset(true);
        // Set order as success

        // Show information page
        Href::redirect(DigiWalletCore::formatOscommerceUrl($this->tep_site_href_link("bankwire_success.php?trxid=" . $this->transactionID, '', 'SSL', true, false)));
        exit(0);
    }
}

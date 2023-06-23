<?php

require('includes/application_top.php');

require 'includes/modules/payment/digiwallet/compatibility.php';

if(!defined('TABLE_ORDERS')) {
    require_once 'includes/modules/payment/digiwallet/database_tables.php';
}

if(isset($oscTemplate)) {
    require $oscTemplate->map_to_template('template_top.php', 'component');
} else {
    require(DIR_WS_INCLUDES . 'template_top.php');
}

require_once 'includes/modules/payment/digiwallet/digiwallet.class.php';
require_once 'includes/extra_datafiles/digiwallet.php';
$availableLanguages = array("dutch","english");
$langDir = (isset($_SESSION["language"]) && in_array($_SESSION["language"], $availableLanguages)) ? $_SESSION["language"] : "dutch";
$ywincludefile = realpath(DIR_WS_LANGUAGES . $langDir . '/modules/payment/digiwallet_ide.php');
require_once $ywincludefile;

$trxid = $_REQUEST["trxid"];
if(empty($trxid)){
    // For Afterpay only
    $trxid = $_REQUEST['invoiceID'];
}
if(!$trxid){
    Href::redirect(tep_site_href_link(FILENAME_DEFAULT, '', 'SSL', true, false));
    exit(0);
}

// Check transaction in digiwallet sale table
$sql = "select * from " . TABLE_DIGIWALLET_TRANSACTIONS . " where `transaction_id` = '" . $GLOBALS['db']->real_escape_string($trxid) . "'";
$sale_obj = $GLOBALS['db']->query($sql);
if (mysqli_num_rows($sale_obj) > 0){
    $sale = ($sale_obj->fetch_assoc());
} else {
    Href::redirect(tep_site_href_link(FILENAME_DEFAULT, '', 'SSL', true, false));
    exit(0);
}
// Check customer's order information
$customer_info = null;
$query = $GLOBALS['db']->query("select * from " . TABLE_ORDERS . " where `orders_id` = '" . $sale['order_id'] . "'");
if (mysqli_num_rows($query) > 0){
    $customer_info = ($query->fetch_assoc());
} else {
    Href::redirect(tep_site_href_link(FILENAME_DEFAULT, '', 'SSL', true, false));
    exit(0);
}
?>

<?php
if($sale['transaction_status'] == "success"){
    ?>
    <h1><?php echo MODULE_PAYMENT_DIGIWALLET_BANKWIRE_THANKYOU_FINISHED;?></h1>
    <?php
} else {
    list($trxid, $accountNumber, $iban, $bic, $beneficiary, $bank) = explode("|", $sale['more']);
    // Encode email address
    $emails = str_split($customer_info['customers_email_address']);
    $counter = 0;
    $cus_email = "";
    foreach ($emails as $char) {
        if($counter == 0) {
            $cus_email .= $char;
            $counter++;
        } else if($char == "@") {
            $cus_email .= $char;
            $counter++;
        } else if($char == "." && $counter > 1) {
            $cus_email .= $char;
            $counter++;
        } else if($counter > 2) {
            $cus_email .= $char;
        } else {
            $cus_email .= "*";
        }
    }
    echo sprintf(MODULE_PAYMENT_DIGIWALLET_BANKWIRE_THANKYOU_PAGE,
        $currencies->display_price(((float) $sale['amount'])/100, 0),
        $iban,
        $beneficiary,
        $trxid,
        $cus_email,
        $bic,
        $bank
    );
}

if(isset($oscTemplate)) {
    require $oscTemplate->map_to_template('template_bottom.php', 'component');
} else {
    require(DIR_WS_INCLUDES . 'template_bottom.php');
}
require(DIR_WS_INCLUDES . 'application_bottom.php');



function tep_site_href_link($page = '', $parameters = '', $connection = null, $add_session_id = true, $search_engine_safe = null) {
    return Guarantor::ensure_global('Linker')->build($page, phoenix_parameterize($parameters), $add_session_id);
}
<?php
/**
 * Stripe Link Callback Handler
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'stripe_link';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$invoiceId = (int)$_GET['invoiceid'];
$paymentIntentId = $_GET['payment_intent'];
$paymentIntentClientSecret = $_GET['payment_intent_client_secret'];

// Verify with Stripe API
$secretKey = $gatewayParams['secretKey'];
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/payment_intents/" . $paymentIntentId);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");
$result = curl_exec($ch);
$paymentIntent = json_decode($result, true);
curl_close($ch);

$success = false;
if ($paymentIntent['status'] === 'succeeded') {
    $success = true;
}

// Check if invoice is already paid
$invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
if ($invoice['status'] === 'Paid') {
    header("Location: " . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId);
    exit;
}

if ($success) {
    // Check if transaction already exists to prevent duplicates
    $checkTransaction = localAPI('GetTransactions', array('transid' => $paymentIntentId));
    if ($checkTransaction['totalresults'] == 0) {
        addInvoicePayment(
            $invoiceId,
            $paymentIntentId,
            $paymentIntent['amount'] / 100,
            0, // Fees can be calculated or retrieved from Stripe
            $gatewayModuleName
        );
        logTransaction($gatewayParams['name'], $paymentIntent, "Successful");
    }
    
    header("Location: " . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId . "&paymentsuccess=true");
} else {
    logTransaction($gatewayParams['name'], $paymentIntent, "Unsuccessful");
    header("Location: " . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId . "&paymentfailed=true");
}
exit;

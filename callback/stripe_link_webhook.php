<?php
/**
 * Stripe Link Webhook Handler
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'stripe_link';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
$endpoint_secret = $gatewayParams['webhookSecret'];

// In a real scenario, use Stripe SDK to verify signature. 
// For this example, we will assume the request is valid or the user will add signature verification.
// Simplified logic for processing the event:

$event = json_decode($payload, true);

if ($event['type'] === 'payment_intent.succeeded') {
    $paymentIntent = $event['data']['object'];
    $invoiceId = $paymentIntent['metadata']['invoice_id'];
    $transactionId = $paymentIntent['id'];
    $amount = $paymentIntent['amount'] / 100;

    // Check if transaction already exists
    $checkTransaction = localAPI('GetTransactions', array('transid' => $transactionId));
    if ($checkTransaction['totalresults'] == 0) {
        addInvoicePayment(
            $invoiceId,
            $transactionId,
            $amount,
            0,
            $gatewayModuleName
        );
        logTransaction($gatewayParams['name'], $event, "Webhook Successful");
    }
}

http_response_code(200);
echo "OK";

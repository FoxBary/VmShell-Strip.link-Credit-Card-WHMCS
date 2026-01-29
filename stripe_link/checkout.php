<?php
/**
 * Stripe Link Checkout Page
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
$ca = new WHMCS_ClientArea();

if (!$ca->isLoggedIn()) {
    die("Please login to continue");
}

// Fetch Invoice Details
$invoice = localAPI('GetInvoice', array('invoiceid' => $invoiceId));
if ($invoice['result'] !== 'success' || $invoice['userid'] != $ca->getUserID()) {
    die("Invalid Invoice");
}

if ($invoice['status'] !== 'Unpaid') {
    header("Location: " . $gatewayParams['systemurl'] . "viewinvoice.php?id=" . $invoiceId);
    exit;
}

$amount = $invoice['total'];
// WHMCS API sometimes returns currencycode, sometimes you need to get it from the user's profile or currency ID
$currency = $invoice['currencycode'];

if (empty($currency)) {
    // Fallback: Try to get currency from currency ID if available
    $currencyId = $invoice['currencyid'];
    if ($currencyId) {
        $currencyData = localAPI('GetCurrencies', array());
        if ($currencyData['result'] == 'success') {
            foreach ($currencyData['currencies']['currency'] as $c) {
                if ($c['id'] == $currencyId) {
                    $currency = $c['code'];
                    break;
                }
            }
        }
    }
}

// Final Fallback: Use system default currency if still empty
if (empty($currency)) {
    $currency = 'USD'; 
}

$secretKey = $gatewayParams['secretKey'];
$publishableKey = $gatewayParams['publishableKey'];

// Create Payment Intent via Stripe API (using cURL for maximum compatibility)
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/payment_intents");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
$postFields = http_build_query([
    'amount' => $amount * 100, // Stripe uses cents
    'currency' => strtolower($currency),
    'payment_method_types[]' => 'card',
    'payment_method_options[card][request_three_d_secure]' => 'any',
    'description' => 'Invoice #' . $invoiceId,
    'metadata[invoice_id]' => $invoiceId,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");

$result = curl_exec($ch);
$paymentIntent = json_decode($result, true);
curl_close($ch);

if (isset($paymentIntent['error'])) {
    die("Stripe Error: " . $paymentIntent['error']['message']);
}

$clientSecret = $paymentIntent['client_secret'];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Pay Invoice #<?php echo $invoiceId; ?></title>
    <script src="https://js.stripe.com/v3/"></script>
    <style>
        body { font-family: Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background-color: #f6f9fc; }
        #payment-form { width: 400px; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(50, 50, 93, 0.11); }
        #payment-element { margin-bottom: 24px; }
        button { background: #5469d4; color: #ffffff; border-radius: 4px; border: 0; padding: 12px 16px; font-size: 16px; font-weight: 600; cursor: pointer; width: 100%; }
        button:hover { filter: contrast(115%); }
        #payment-message { color: rgb(105, 115, 134); font-size: 16px; line-height: 20px; padding-top: 12px; text-align: center; }
    </style>
</head>
<body>
    <form id="payment-form">
        <h3>Invoice #<?php echo $invoiceId; ?> - <?php echo $amount . ' ' . $currency; ?></h3>
        <div id="payment-element"></div>
        <button id="submit">
            <div class="spinner hidden" id="spinner"></div>
            <span id="button-text">Pay now</span>
        </button>
        <div id="payment-message" class="hidden"></div>
    </form>

    <script>
        const stripe = Stripe("<?php echo $publishableKey; ?>");
        const options = {
            clientSecret: "<?php echo $clientSecret; ?>",
            appearance: { theme: 'stripe' },
        };
        const elements = stripe.elements(options);
        const paymentElement = elements.create("payment");
        paymentElement.mount("#payment-element");

        const form = document.getElementById('payment-form');
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const {error} = await stripe.confirmPayment({
                elements,
                confirmParams: {
                    return_url: "<?php echo $gatewayParams['systemurl']; ?>modules/gateways/callback/stripe_link.php?invoiceid=<?php echo $invoiceId; ?>",
                },
            });

            if (error) {
                const messageContainer = document.querySelector('#payment-message');
                messageContainer.textContent = error.message;
                messageContainer.classList.remove("hidden");
            }
        });
    </script>
</body>
</html>

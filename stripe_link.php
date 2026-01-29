<?php
/**
 * WHMCS Stripe Link & 3DS Gateway Module
 * Optimized for VmShell INC.
 * Plugin Name: VmShell-Credit Card
 * Payment Method: Stripe-Link
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function stripe_link_MetaData()
{
    return array(
        'DisplayName' => 'VmShell-Credit Card',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function stripe_link_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'VmShell-Credit Card',
        ),
        'publishableKey' => array(
            'FriendlyName' => 'Stripe Publishable Key',
            'Type' => 'text',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Enter your Stripe Publishable Key',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Stripe Secret Key',
            'Type' => 'password',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Enter your Stripe Secret Key',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Stripe Webhook Secret',
            'Type' => 'password',
            'Size' => '40',
            'Default' => '',
            'Description' => 'Enter your Stripe Webhook Secret',
        ),
        'settlementCurrency' => array(
            'FriendlyName' => 'Settlement Currency',
            'Type' => 'dropdown',
            'Options' => 'USD,HKD,CNY,EUR,GBP,JPY,CAD,AUD',
            'Default' => 'USD',
            'Description' => 'Select the currency to charge customers in Stripe',
        ),
    );
}

/**
 * This function is called on the view invoice page.
 * We will embed the Stripe Payment Element directly here.
 */
function stripe_link_link($params)
{
    $invoiceId = $params['invoiceid'];
    $systemUrl = $params['systemurl'];
    $publishableKey = $params['publishableKey'];
    $secretKey = $params['secretKey'];
    $settlementCurrency = $params['settlementCurrency'];
    $amount = $params['amount']; 

    // Create Payment Intent via Stripe API
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.stripe.com/v1/payment_intents");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    
    $postFields = http_build_query([
        'amount' => $amount * 100, 
        'currency' => strtolower($settlementCurrency),
        'payment_method_types[]' => 'card',
        'payment_method_options[card][request_three_d_secure]' => 'any',
        'description' => 'Invoice #' . $invoiceId . ' - VmShell INC.',
        'metadata[invoice_id]' => $invoiceId,
    ]);
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ":");
    $result = curl_exec($ch);
    $paymentIntent = json_decode($result, true);
    curl_close($ch);

    if (isset($paymentIntent['error'])) {
        return '<div class="alert alert-danger">Stripe Error: ' . $paymentIntent['error']['message'] . '</div>';
    }

    $clientSecret = $paymentIntent['client_secret'];

    // HTML/JS to inject the payment element into the invoice page
    $code = '
    <div id="stripe-link-container" style="margin-bottom: 20px; padding: 15px; border: 1px solid #e1e4e8; border-radius: 6px; background: #fdfdfd; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: none;">
        <h4 style="margin-top:0; color: #32325d; font-weight: 600;">Stripe-Link</h4>
        <p style="font-size: 12px; color: #6b7c93; margin-bottom: 15px;">Secure payment powered by VmShell-Credit Card</p>
        <form id="stripe-payment-form">
            <div id="stripe-payment-element"></div>
            <button id="stripe-submit-btn" class="btn btn-primary btn-block" style="margin-top: 15px; background-color: #5469d4; border-color: #5469d4;">
                <span id="button-text">Pay ' . $params['currency'] . ' ' . $amount . ' Now</span>
            </button>
            <div id="stripe-payment-message" class="alert alert-warning hidden" style="margin-top: 10px;"></div>
        </form>
    </div>

    <script src="https://js.stripe.com/v3/"></script>
    <script>
        (function() {
            // Auto-redirect to invoice page if we are on the checkout/payment selection page
            if (window.location.href.indexOf("cart.php?a=checkout") !== -1 || window.location.href.indexOf("viewinvoice.php") === -1) {
                window.location.href = "' . $systemUrl . 'viewinvoice.php?id=' . $invoiceId . '";
                return;
            }

            const stripe = Stripe("' . $publishableKey . '");
            const options = {
                clientSecret: "' . $clientSecret . '",
                appearance: { 
                    theme: "stripe",
                    variables: {
                        colorPrimary: "#5469d4",
                    }
                },
            };
            const elements = stripe.elements(options);
            const paymentElement = elements.create("payment");
            paymentElement.mount("#stripe-payment-element");

            const form = document.getElementById("stripe-payment-form");
            form.addEventListener("submit", async (event) => {
                event.preventDefault();
                const submitBtn = document.getElementById("stripe-submit-btn");
                submitBtn.disabled = true;
                submitBtn.innerText = "Processing...";

                const {error} = await stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: "' . $systemUrl . 'modules/gateways/callback/stripe_link.php?invoiceid=' . $invoiceId . '",
                    },
                });

                if (error) {
                    const messageContainer = document.querySelector("#stripe-payment-message");
                    messageContainer.textContent = error.message;
                    messageContainer.classList.remove("hidden");
                    submitBtn.disabled = false;
                    submitBtn.innerText = "Pay Now";
                }
            });

            // Move and show the container
            const moveContainer = function() {
                const target = document.querySelector(".payment-btn-container") || 
                               document.querySelector(".invoice-header .pull-right") || 
                               document.querySelector(".invoice-container .pull-right") ||
                               document.querySelector(".pull-right.export-options");
                
                const container = document.getElementById("stripe-link-container");
                if (target && container) {
                    target.prepend(container);
                    container.style.display = "block";
                    container.style.width = "320px";
                    container.style.float = "right";
                    container.style.clear = "both";
                    container.style.textAlign = "left";
                } else if (container) {
                    // Fallback if no target found
                    container.style.display = "block";
                    container.style.margin = "20px auto";
                    container.style.maxWidth = "400px";
                }
            };

            if (document.readyState === "complete") {
                moveContainer();
            } else {
                window.addEventListener("load", moveContainer);
            }
            // Secondary check for dynamic themes
            setTimeout(moveContainer, 500);
            setTimeout(moveContainer, 1500);
        })();
    </script>
    <style>
        .hidden { display: none; }
        #stripe-link-container { text-align: left; }
    </style>
    ';

    return $code;
}

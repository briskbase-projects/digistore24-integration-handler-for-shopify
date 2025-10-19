<?php

// Shopify API credentials
$shopifyAccessToken = "here_goes_your_shopify_access_token_keys";
$shopifyStore = "storeid.myshopify.com";

// Digistore24 secret key (found in your Digistore24 IPN settings)
$digistoreSecretKey = "your_digistore_secret_key";

// Function to verify the SHA512 signature
function verifyDigistoreSignature($postData, $secretKey)
{
    $dataString = "";
    ksort($postData);

    foreach ($postData as $key => $value) {
        if ($key !== "sha_sign") {
            $dataString .= $value . "|";
        }
    }

    $dataString .= $secretKey;
    $expectedSignature = hash("sha512", $dataString);

    return $expectedSignature === $postData["sha_sign"];
}

// Function to update order in Shopify
function updateShopifyOrder($orderId, $transactionId, $status, $receiptUrl)
{
    global $shopifyAccessToken, $shopifyStore;

    $apiUrl = "https://$shopifyStore/admin/api/2023-07/orders/$orderId/transactions.json";

    $transactionData = [
        "transaction" => [
            "kind" => "capture",
            "status" => $status,
            "amount" => $_POST["transaction_amount"],
            "currency" => $_POST["transaction_currency"],
            "gateway" => "Digistore24",
            "receipt" => [
                "url" => $receiptUrl
            ]
        ]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($transactionData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "X-Shopify-Access-Token: $shopifyAccessToken"
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 201) ? json_decode($response, true) : false;
}

// Function to log IPN data for debugging
function logIPNData($data)
{
    file_put_contents("digistore_ipn_log.txt", print_r($data, true) . "\n\n", FILE_APPEND);
}

// Handle IPN request
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    logIPNData($_POST); // Log request

    // Verify signature
    if (!verifyDigistoreSignature($_POST, $digistoreSecretKey)) {
        http_response_code(400);
        echo "Invalid signature";
        exit;
    }

    $event = $_POST["event"];
    $orderId = $_POST["custom"]; // Assuming custom field stores Shopify order ID
    $transactionId = $_POST["transaction_id"];
    $receiptUrl = $_POST["receipt_url"];

    switch ($event) {
        case "on_payment":
            $status = "success";
            updateShopifyOrder($orderId, $transactionId, $status, $receiptUrl);
            break;

        case "on_refund":
            $status = "refunded";
            updateShopifyOrder($orderId, $transactionId, $status, $receiptUrl);
            break;

        case "on_chargeback":
            $status = "chargeback";
            updateShopifyOrder($orderId, $transactionId, $status, $receiptUrl);
            break;

        case "on_payment_missed":
            $status = "failed";
            updateShopifyOrder($orderId, $transactionId, $status, $receiptUrl);
            break;

        default:
            logIPNData(["Unhandled event" => $event]);
            break;
    }

    echo "OK";
} else {
    http_response_code(405);
    echo "Method Not Allowed";
}

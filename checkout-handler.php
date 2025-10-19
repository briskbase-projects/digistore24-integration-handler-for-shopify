<?php

// Allow only requests from https://example.com/checkout-handler.php
$allowed_origin = "https://example.com";

if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $allowed_origin) {
    header("Access-Control-Allow-Origin: $allowed_origin");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
} else {
    http_response_code(403);
    echo json_encode(["error" => "Forbidden: Invalid Origin"]);
    exit;
}

// Handle preflight (OPTIONS request)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Allow only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Method Not Allowed"]);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);

// Check if required data exists
if (!isset($data['customer']) || !isset($data['cart']) || empty($data['cart']['items'])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid request data"]);
    exit;
}

define('YOUR_API_KEY', 'here_goes_your_keys'); // replace by your api key (permission: "readonly" or "writable") - see https://www.digistore24.com/vendor/settings/account_access/api

define('YOUR_PRODUCT_ID', 596770); // replace 123 by your product id

require_once 'ds24_api.php';

// Shopify credentials
$access_token = "here_goes_your_shopify_access_token_keys";
$shop_name = "myshop"; // e.g., myshop


// Get incoming POST data
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Extract customer details
$customer_name = $data['customer']['name'] ?? "Guest User";
$customer_email = $data['customer']['email'] ?? "guest@example.com";

// Extract cart items
$line_items = [];
foreach ($data['cart']['items'] as $item) {
    $line_items[] = [
        "variant_id" => $item['variant_id'], // Shopify Variant ID
        "quantity" => $item['quantity'],
        "price" => $item['unit_amount']['value'],
        "title" => $item['name'],
        "properties" => json_decode($item['description'], true) // Custom properties
    ];
}

// Total price
$total_price = $data['cart']['total'];

// Shopify API URL
$shopify_url = "https://$shop_name.myshopify.com/admin/api/2023-04/orders.json";

// Order Data for Shopify
$order_data = [
    "order" => [
        "email" => $customer_email,
        // "phone" => $customer_phone,
        "financial_status" => "pending",
        "payment_gateway_names" => ["manual"], // Unpaid order
        "tags" => "Digistore24 Payment",
        "line_items" => $line_items
    ]
];

// Create Shopify Order
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $shopify_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($order_data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json",
    "X-Shopify-Access-Token: $access_token"
]);

$response = curl_exec($ch);
curl_close($ch);
$order_response = json_decode($response, true);

if (isset($order_response['order']['id'])) {
    $order_id = $order_response['order']['id'];

    try {
        $api = DigistoreApi::connect(YOUR_API_KEY);
        $product_id = YOUR_PRODUCT_ID;
        $customer_name = explode(" ", $customer_name);
        $buyer = array(
            'email'         => $customer_email,
            'first_name'    => $customer_name[0] ?? 'Guest',
            'last_name'     => $customer_name[1] ?? 'User',
            'readonly_keys' => 'email_and_name',
        );
        // 7 days trial period for 1,- EUR, then 27 EUR monthly:
        $payment_plan = array(
            'first_amount'  => $total_price,
            // 'other_amounts' => 27.00,
            // 'first_billing_interval' => '7_day',
            // 'other_billing_intervals' => '1_month',
            'currency' => 'EUR'
        );
        $tracking = array(
            'custom'    => 'shopify_order_' . $order_id
            // 'affiliate' => 'some_digistore24_id',
        );
        $valid_until = ''; // not expired

        $urls = array(
            'thankyou_url' => 'https://example.com/pages/thank-you',
        );
        $placeholders = array();
        $settings = array();
        $data = $api->createBuyUrl($product_id, $buyer, $payment_plan, $tracking, $valid_until, $urls, $placeholders, $settings);
        $payment_link = $data->url;
        $api->disconnect();

        // Add Digistore24 Payment Link to Shopify Order Note
        $update_url = "https://$shop_name.myshopify.com/admin/api/2023-04/orders/$order_id.json";
        $update_data = [
            "order" => [
                "note" => "Payment pending. Pay here: $payment_link"
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $update_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($update_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Accept: application/json",
            "X-Shopify-Access-Token: $access_token"
        ]);

        $update_response = curl_exec($ch);
        curl_close($ch);

        // Return Payment Link
        echo json_encode([
            "success" => true,
            "order_id" => $order_id,
            "payment_link" => $payment_link
        ]);
    } catch (DigistoreApiException $e) {
        $error_message = $e->getMessage();
        echo json_encode([
            "success" => false,
            "error" => $error_message
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "error" => $order_response
    ]);
}

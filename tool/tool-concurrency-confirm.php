<?php

/**
 * Test for concurrency of same transactionId and same orderId
 */

require __DIR__ . '/_config.php';

use yidas\linePay\Client as LinePayClient;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;

// Get saved config
$config = $_SESSION['config'];
// Check is in session
if (!$config) {
    die("<script>alert('Invalid session');location.href='./index.php';</script>");
}
// Check input
if (!isset($_GET['transactionId'])) {
    die("<script>alert('No input');location.href='./index.php';</script>");
}
// Get the order from session
$order = $_SESSION['linePayOrder'];
$transactionId = $_GET['transactionId'];

// New a HTTP client
$client = new Client([
    'base_uri' => ($config['isSandbox']) ? LinePayClient::SANDBOX_API_HOST : LinePayClient::API_HOST,
    'headers' => [
        'Content-Type' => 'application/json',
        'X-LINE-ChannelId' => $config['channelId'],
    ]
]);

// Body
$bodyParams = [
    'amount' => (integer) $order['params']['amount'],
    'currency' => $order['params']['currency'],
];
$options['body'] = json_encode($bodyParams);
// Promises list
$promises = [];

// Single transaction concurrency or multi-transactions to same orderId concurrency 
if (is_array($transactionId)) {

    // All $transactionIds need same confirm API body (Currency & Price)
    foreach ($transactionId as $key => $eachId) {
        $uri = "/v3/payments/{$eachId}/confirm";
        authToOptions($options, $uri, $config['channelSecret']);
        $promises[] = $client->requestAsync('POST', $uri, $options);
    }

} else {

    for ($i=0; $i < 3; $i++) { 
        $uri = "/v3/payments/{$transactionId}/confirm";
        authToOptions($options, $uri, $config['channelSecret']);
        $promises[] = $client->requestAsync('POST', $uri, $options);
    }
}
// Wait on all of the requests to complete. Throws a ConnectException
// if any of the requests fail
$results = Promise\unwrap($promises);

// Wait for the requests to complete, even if some of them fail
$results = Promise\settle($promises)->wait();

// You can access each result using the key provided to the unwrap
// function.
foreach ($results as $key => $result) {
    saveLog('Confirm API', $bodyParams, null, json_decode($result['value']->getBody()->getContents(), true), null);
}

// Exit
die("<script>alert('Done, please check logs.');location.href='./index.php';</script>");

/**
 * API Auth
 * 
 * @param array $options
 * @param string $uri
 * @param string $channelSecret
 */
function authToOptions(& $options, $uri, $channelSecret)
{
    // V3 API Authentication
    $authNonce = date('c'); // ISO 8601 date
    $authMacText = $channelSecret . $uri . $options['body'] . $authNonce;
    $options['headers'] = [
        'X-LINE-Authorization' => base64_encode(hash_hmac('sha256', $authMacText, $channelSecret, true)),
        'X-LINE-Authorization-Nonce' => $authNonce,
    ];
}
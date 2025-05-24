<?php

// You can download this file from here https://cdn.dataforseo.com/v3/examples/php/php_RestClient.zip
require 'RestClient.php';

require_once __DIR__ . '/../examples/bootstrap.php';

// Get DataForSEO credentials from config
$login       = config('api-cache.apis.dataforseo.DATAFORSEO_LOGIN');
$password    = config('api-cache.apis.dataforseo.DATAFORSEO_PASSWORD');
$pingbackUrl = config('api-cache.apis.dataforseo.pingback_url');
$postbackUrl = config('api-cache.apis.dataforseo.postback_url');
$api_url     = 'https://api.dataforseo.com/';

function _in_logit_GET($id_message, $data)
{
    @file_put_contents(__DIR__ . '/pingback.log', PHP_EOL . date('Y-m-d H:i:s') . ': ' . $id_message . PHP_EOL . '---------' . PHP_EOL . print_r($data, true) . PHP_EOL . '---------', FILE_APPEND);
}

$id = $_GET['id'];
_in_logit_GET('GET', $_GET);
if (!empty($id)) {
    // Instead of 'login' and 'password' use your credentials from https://app.dataforseo.com/api-access
    $client = new RestClient($api_url, null, $login, $password);

    try {
        $serp_result = $client->get('/v3/serp/google/organic/task_get/advanced/' . $_GET['id']);
    } catch (RestClientException $e) {
        echo "\n";
        print "HTTP code: {$e->getHttpCode()}\n";
        print "Error code: {$e->getCode()}\n";
        print "Message: {$e->getMessage()}\n";
        print  $e->getTraceAsString();
        echo "\n";
        // Log the error
        _in_logit_GET("error - RestClientException (id: $id)", $e);
        exit();
    }
    // you can find the full list of the response codes here https://docs.dataforseo.com/v3/appendix/errors
    if (isset($serp_result['status_code']) and $serp_result['status_code'] === 20000) {
        _in_logit_GET('ready', $serp_result);
        // do something with results
        echo 'ok';
    } else {
        echo 'error';
        // Log the error
        _in_logit_GET("error - status_code (id: $id)", $serp_result);
    }
} else {
    echo 'empty GET';
    // Log the error
    $get = $_GET;
    _in_logit_GET('error - empty GET', $get);
}

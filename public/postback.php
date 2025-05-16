<?php

function _in_logit_POST($id_message, $data)
{
    @file_put_contents(__DIR__ . '/postback_url_example.log', PHP_EOL . date('Y-m-d H:i:s') . ': ' . $id_message . PHP_EOL . '---------' . PHP_EOL . print_r($data, true) . PHP_EOL . '---------', FILE_APPEND);
}

$post_data_in = file_get_contents('php://input');
if (!empty($post_data_in)) {
    $post_arr = json_decode(gzdecode($post_data_in), true);
    // you can find the full list of the response codes here https://docs.dataforseo.com/v3/appendix/errors
    if (isset($post_arr['status_code']) and $post_arr['status_code'] === 20000) {
        _in_logit_POST('result', $post_arr);
        //do something with results
        echo 'ok';
    } else {
        //_in_logit_POST('error decode', $post_data_in);
        echo 'error';
    }
} else {
    echo 'empty POST';
}

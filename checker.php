<?php

require_once __DIR__ . '/result.php';

function getRandomMessage($length = 16) {
    $str = '';
    $characters = array_merge(range('A','Z'), range('a','z'), range('0','9'));
    $max = count($characters) - 1;
    for ($i = 0; $i < $length; $i++) {
        $rand = mt_rand(0, $max);
        $str .= $characters[$rand];
    }
    return $str;
}

function push($endpoint, $capsule, $label, $metadata) {
    sleep(rand(1, 5));
    return [Result::UP, $label, getRandomMessage()];
}

function pull($endpoint, $capsule, $label, $metadata) {
    sleep(rand(1, 5));
    return [Result::UP, getRandomMessage()];
}

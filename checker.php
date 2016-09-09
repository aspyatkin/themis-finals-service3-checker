<?php

require_once __DIR__ . '/result.php';

function push($endpoint, $flag, $adjunct, $metadata) {
    sleep(rand(1, 5));
    return [Result::UP, $adjunct];
}

function pull($endpoint, $flag, $adjunct, $metadata) {
    sleep(rand(1, 5));
    return Result::UP;
}

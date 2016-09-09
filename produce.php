<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/base.php';

use Bernard\Message;
use Bernard\Middleware;
use Bernard\Producer;

function getProducerMiddleware() {
    return new Middleware\MiddlewareBuilder;
}

function getProducer() {
    return new Producer(getQueueFactory(), getProducerMiddleware());
}

function enqueueBackgroundJob($action, $params) {
    $producer = getProducer();
    $producer->produce(new Message\DefaultMessage('action', [
        'action' => $action,
        'params' => $params
    ]));
}

<?php
require_once __DIR__ . '/vendor/autoload.php';

use Predis\Client;
use Bernard\Driver\PredisDriver;
use Bernard\Serializer\SimpleSerializer;
use Bernard\QueueFactory\PersistentFactory;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

function getDriver() {
    $connection = [
        'scheme' => 'tcp',
        'host' => getenv('REDIS_HOST'),
        'port' => (int)getenv('REDIS_PORT'),
        'database' => (int)getenv('REDIS_DB')
    ];

    $options = [
        'prefix' => 'bernard:'
    ];

    return new PredisDriver(new Client($connection, $options));
}

function getSerializer() {
    return new SimpleSerializer;
}

function getQueueFactory() {
    return new PersistentFactory(getDriver(), getSerializer());
}

$logger = null;

function getLogger() {
    global $logger;
    if ($logger === null) {
        $logger = new Logger('checker');
        $logger->pushHandler(new StreamHandler('php://stdout'));
    }
    return $logger;
}

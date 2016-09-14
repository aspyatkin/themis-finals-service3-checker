<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/base.php';
require_once __DIR__ . '/result.php';

$checkerModuleName = getenv('THEMIS_FINALS_CHECKER_MODULE');

if ($checkerModuleName === false) {
    $checkerModuleName = getcwd() . '/checker.php';
}

require_once $checkerModuleName;

use Bernard\Consumer;
use Bernard\Message;
use Bernard\Middleware;
use Bernard\Router\SimpleRouter;
use Bernard\Message\DefaultMessage;
use Base64Url\Base64Url;

$ravenEnabled = getenv('SENTRY_DSN') !== false;
$ravenClient = null;

if ($ravenEnabled) {
    $ravenClient = new Raven_Client(getenv('SENTRY_DSN'), [
        'curl_method' => 'async'
    ]);
}

class Metadata
{
    public $timestamp;
    public $round;
    public $teamName;
    public $serviceName;

    function __construct($options)
    {
        $this->timestamp = $options['timestamp'];
        $this->round = $options['round'];
        $this->teamName = $options['team_name'];
        $this->serviceName = $options['service_name'];
    }
}

function getNonceSize() {
    if (getenv('THEMIS_FINALS_NONCE_SIZE') !== false) {
        return (int)getenv('THEMIS_FINALS_NONCE_SIZE');
    }
    return 16;
}

function issueToken($name) {
    $nonce = mcrypt_create_iv(getNonceSize(), MCRYPT_DEV_URANDOM);
    $secret = Base64Url::decode(getenv('THEMIS_FINALS_' . $name . '_KEY'));

    $nonceBytes = $nonce;
    $digestBytes = pack('H*', hash('sha256', $nonce . $secret));

    $tokenBytes = $nonceBytes . $digestBytes;
    return Base64Url::encode($tokenBytes);
}

function issueCheckerToken() {
    return issueToken('CHECKER');
}

function floatSecondsBetween($microtime1, $microtime2) {
    return abs($microtime1 - $microtime2);
}

class ActionService
{
    public function __construct() {
        $this->logger = getLogger();
    }

    public function action(DefaultMessage $message)
    {
        if ($message->action == 'push') {
            $this->queuePush($message->params);
        } elseif ($message->action == 'pull') {
            $this->queuePull($message->params);
        }
    }

    public function internalPush($endpoint, $flag, $adjunct, $metadata)
    {
        $result = Result::INTERNAL_ERROR;
        $updatedAdjunct = $adjunct;
        try {
            $rawResult = \push($endpoint, $flag, $adjunct, $metadata);
            if (is_array($rawResult) && count($rawResult) == 2) {
                $result = $rawResult[0];
                $updatedAdjunct = $rawResult[1];
            } else {
                $result = $rawResult;
            }
        } catch (Exception $e) {
            global $ravenEnabled;
            global $ravenClient;
            if ($ravenEnabled) {
                $ravenClient->captureException($e);
            }

            $this->logger->error($e->getMessage());
        }
        return [$result, $updatedAdjunct];
    }

    public function queuePush($jobData)
    {
        $params = $jobData['params'];
        $metadata = new Metadata($jobData['metadata']);
        $parsedTimestamp = DateTime::createFromFormat('Y-m-d\TH:i:sP', $metadata->timestamp);
        $timestampCreated = $parsedTimestamp->getTimestamp();
        $timestampDelivered = microtime(true);

        $res = $this->internalPush(
            $params['endpoint'],
            $params['flag'],
            Base64Url::decode($params['adjunct']),
            $metadata
        );
        $status = $res[0];
        $updatedAdjunct = $res[1];

        $timestampProcessed = microtime(true);

        $jobResult = [
            'status' => $status,
            'flag' => $params['flag'],
            'adjunct' => Base64Url::encode($updatedAdjunct)
        ];

        $deliveryTime = floatSecondsBetween($timestampDelivered, $timestampCreated);
        $processingTime = floatSecondsBetween($timestampProcessed, $timestampDelivered);

        $logMessage = sprintf(
            'PUSH flag `%s` /%d to `%s`@`%s` (%s) - status %s,'.
            ' adjunct `%s` [delivery %.2fs, processing %.2fs]',
            $params['flag'],
            $metadata->round,
            $metadata->serviceName,
            $metadata->teamName,
            $params['endpoint'],
            Result::getName($status),
            $jobResult['adjunct'],
            $deliveryTime,
            $processingTime
        );

        global $ravenEnabled;
        global $ravenClient;
        if ($ravenEnabled) {
            $shortLogMessage = sprintf(
                'PUSH `%s...` /%d to `%s` - status %s',
                substr($params['flag'], 0, 8),
                $metadata->round,
                $metadata->teamName,
                Result::getName($status)
            );

            $ravenData = [
                'level' => 'info',
                'tags' => [
                    'tf_operation' => 'push',
                    'tf_status' => Result::getName($status),
                    'tf_team' => $metadata->teamName,
                    'tf_service' => $metadata->serviceName,
                    'tf_round' => $metadata->round
                ],
                'extra' => [
                    'endpoint' => $params['endpoint'],
                    'flag' => $params['flag'],
                    'adjunct' => $jobResult['adjunct'],
                    'delivery_time' => $deliveryTime,
                    'processing_time' => $processingTime
                ]
            ];

            $ravenClient->captureMessage($shortLogMessage, [], $ravenData);
        }

        $this->logger->info($logMessage);

        $uri = $jobData['report_url'];
        $headers = [
            'Content-Type' => 'application/json'
        ];
        $headers[getenv('THEMIS_FINALS_AUTH_TOKEN_HEADER')] = issueCheckerToken();
        $r = Requests::post($uri, $headers, json_encode($jobResult));
        $this->logger->info($r->status_code);
    }

    function internalPull($endpoint, $flag, $adjunct, $metadata)
    {
        $result = Result::INTERNAL_ERROR;
        try {
            $result = \pull($endpoint, $flag, $adjunct, $metadata);
        } catch (Exception $e) {
            global $ravenEnabled;
            global $ravenClient;
            if ($ravenEnabled) {
                $ravenClient->captureException($e);
            }

            $this->logger->error($e->getMessage());
        }
        return $result;
    }

    public function queuePull($jobData)
    {
        $params = $jobData['params'];
        $metadata = new Metadata($jobData['metadata']);
        $parsedTimestamp = DateTime::createFromFormat('Y-m-d\TH:i:sP', $metadata->timestamp);
        $timestampCreated = $parsedTimestamp->getTimestamp();
        $timestampDelivered = microtime(true);

        $status = $this->internalPull(
            $params['endpoint'],
            $params['flag'],
            Base64Url::decode($params['adjunct']),
            $metadata
        );

        $timestampProcessed = microtime(true);

        $jobResult = [
            'request_id' => $params['request_id'],
            'status' => $status
        ];

        $deliveryTime = floatSecondsBetween($timestampDelivered, $timestampCreated);
        $processingTime = floatSecondsBetween($timestampProcessed, $timestampDelivered);

        $logMessage = sprintf(
            'PULL flag `%s` /%d from `%s`@`%s` (%s) with '.
            'adjunct `%s` - status %s [delivery %.2fs, processing %.2fs]',
            $params['flag'],
            $metadata->round,
            $metadata->serviceName,
            $metadata->teamName,
            $params['endpoint'],
            $params['adjunct'],
            Result::getName($status),
            $deliveryTime,
            $processingTime
        );

        global $ravenEnabled;
        global $ravenClient;
        if ($ravenEnabled) {
            $shortLogMessage = sprintf(
                'PULL `%s...` /%d from `%s` - status %s',
                substr($params['flag'], 0, 8),
                $metadata->round,
                $metadata->teamName,
                Result::getName($status)
            );

            $ravenData = [
                'level' => 'info',
                'tags' => [
                    'tf_operation' => 'pull',
                    'tf_status' => Result::getName($status),
                    'tf_team' => $metadata->teamName,
                    'tf_service' => $metadata->serviceName,
                    'tf_round' => $metadata->round
                ],
                'extra' => [
                    'endpoint' => $params['endpoint'],
                    'flag' => $params['flag'],
                    'adjunct' => $params['adjunct'],
                    'delivery_time' => $deliveryTime,
                    'processing_time' => $processingTime
                ]
            ];
            $ravenClient->captureMessage($shortLogMessage, [], $ravenData);
        }

        $this->logger->info($logMessage);

        $uri = $jobData['report_url'];
        $headers = [
            'Content-Type' => 'application/json'
        ];
        $headers[getenv('THEMIS_FINALS_AUTH_TOKEN_HEADER')] = issueCheckerToken();
        $r = Requests::post($uri, $headers, json_encode($jobResult));
        $this->logger->info($r->status_code);
    }
}

function getConsumerMiddleware() {
    $chain = new Middleware\MiddlewareBuilder;
    $chain->push(new Middleware\ErrorLogFactory);
    $chain->push(new Middleware\FailuresFactory(getQueueFactory()));

    return $chain;
}

function getReceivers() {
    return new SimpleRouter([
        'action' => new ActionService()
    ]);
}

function getConsumer() {
    return new Consumer(getReceivers(), getConsumerMiddleware());
}

function consume() {
    $queues = getQueueFactory();
    $consumer = getConsumer();

    $consumer->consume($queues->create('action'));
}

consume();

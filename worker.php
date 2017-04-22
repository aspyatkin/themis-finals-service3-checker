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

use Jose\Factory\JWKFactory;
use Jose\Loader;

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

    protected function decodeCapsule($capsule) {
        $wrapPrefix = getenv('THEMIS_FINALS_FLAG_WRAP_PREFIX');
        $wrapSuffix = getenv('THEMIS_FINALS_FLAG_WRAP_SUFFIX');
        $encodedPayload = substr(
            $capsule,
            strlen($wrapPrefix),
            strlen($capsule) - strlen($wrapPrefix) - strlen($wrapSuffix)
        );

        $alg = 'ES256';
        $key = JWKFactory::createFromKey(
            str_replace('\n', "\n", getenv('THEMIS_FINALS_FLAG_SIGN_KEY_PUBLIC')),
            '',
            ['use' => 'sig', 'alg'=> $alg]
        );

        $loader = new Loader();
        $decoded = $loader->loadAndVerifySignatureUsingKey(
            $encodedPayload,
            $key,
            [$alg],
            $signature_index
        );

        return $decoded->getClaims()['flag'];
    }

    public function internalPush($endpoint, $capsule, $label, $metadata)
    {
        $result = Result::INTERNAL_ERROR;
        $updatedLabel = $label;
        $message = null;
        try {
            $rawResult = \push($endpoint, $capsule, $label, $metadata);
            if (is_array($rawResult) && count($rawResult) == 3) {
                $result = $rawResult[0];
                $updatedLabel = $rawResult[1];
                $message = $rawResult[2];
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
        return [$result, $updatedLabel, $message];
    }

    public function queuePush($jobData)
    {
        $params = $jobData['params'];
        $metadata = new Metadata($jobData['metadata']);
        $parsedTimestamp = DateTime::createFromFormat('Y-m-d\TH:i:sP', $metadata->timestamp);
        $timestampCreated = $parsedTimestamp->getTimestamp();
        $timestampDelivered = microtime(true);

        $flag = $this->decodeCapsule($params['capsule']);

        $res = $this->internalPush(
            $params['endpoint'],
            $params['capsule'],
            Base64Url::decode($params['label']),
            $metadata
        );
        $status = $res[0];
        $updatedLabel = $res[1];
        $message = $res[2];

        $timestampProcessed = microtime(true);

        $jobResult = [
            'status' => $status,
            'flag' => $flag,
            'label' => Base64Url::encode($updatedLabel),
            'message' => $message
        ];

        $deliveryTime = floatSecondsBetween($timestampDelivered, $timestampCreated);
        $processingTime = floatSecondsBetween($timestampProcessed, $timestampDelivered);

        $logMessage = sprintf(
            'PUSH flag `%s` /%d to `%s`@`%s` (%s) - status %s,'.
            ' label `%s` [delivery %.2fs, processing %.2fs]',
            $flag,
            $metadata->round,
            $metadata->serviceName,
            $metadata->teamName,
            $params['endpoint'],
            Result::getName($status),
            $jobResult['label'],
            $deliveryTime,
            $processingTime
        );

        global $ravenEnabled;
        global $ravenClient;
        if ($ravenEnabled) {
            $shortLogMessage = sprintf(
                'PUSH `%s...` /%d to `%s` - status %s',
                substr($flag, 0, 8),
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
                    'flag' => $flag,
                    'label' => $jobResult['label'],
                    'message' => $jobResult['message'],
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

    function internalPull($endpoint, $capsule, $label, $metadata)
    {
        $result = Result::INTERNAL_ERROR;
        $message = null;
        try {
            $rawResult = \pull($endpoint, $capsule, $label, $metadata);
            if (is_array($rawResult) && count($rawResult) == 2) {
                $result = $rawResult[0];
                $message = $rawResult[1];
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
        return [$result, $message];
    }

    public function queuePull($jobData)
    {
        $params = $jobData['params'];
        $metadata = new Metadata($jobData['metadata']);
        $parsedTimestamp = DateTime::createFromFormat('Y-m-d\TH:i:sP', $metadata->timestamp);
        $timestampCreated = $parsedTimestamp->getTimestamp();
        $timestampDelivered = microtime(true);

        $flag = $this->decodeCapsule($params['capsule']);

        $res = $this->internalPull(
            $params['endpoint'],
            $params['capsule'],
            Base64Url::decode($params['label']),
            $metadata
        );
        $status = $res[0];
        $message = $res[1];

        $timestampProcessed = microtime(true);

        $jobResult = [
            'request_id' => $params['request_id'],
            'status' => $status,
            'message' => $message
        ];

        $deliveryTime = floatSecondsBetween($timestampDelivered, $timestampCreated);
        $processingTime = floatSecondsBetween($timestampProcessed, $timestampDelivered);

        $logMessage = sprintf(
            'PULL flag `%s` /%d from `%s`@`%s` (%s) with '.
            'label `%s` - status %s [delivery %.2fs, processing %.2fs]',
            $flag,
            $metadata->round,
            $metadata->serviceName,
            $metadata->teamName,
            $params['endpoint'],
            $params['label'],
            Result::getName($status),
            $deliveryTime,
            $processingTime
        );

        global $ravenEnabled;
        global $ravenClient;
        if ($ravenEnabled) {
            $shortLogMessage = sprintf(
                'PULL `%s...` /%d from `%s` - status %s',
                substr($flag, 0, 8),
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
                    'flag' => $flag,
                    'label' => $params['label'],
                    'message' => $params['message'],
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

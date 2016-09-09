<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../base.php';
require_once __DIR__ . '/../produce.php';

use Base64Url\Base64Url;

$app = new \Slim\App;
$logger = getLogger();

function getNonceSize() {
    if (getenv('THEMIS_FINALS_NONCE_SIZE') !== false) {
        return (int)getenv('THEMIS_FINALS_NONCE_SIZE');
    }
    return 16;
}

function verifyToken($name, $token) {
    if ($token === null) {
        return false;
    }

    $nonceSize = getNonceSize();

    $tokenBytes = Base64Url::decode($token);

    if (strlen($tokenBytes) !== 32 + $nonceSize) {
        return false;
    }

    $nonce = substr($tokenBytes, 0, $nonceSize);
    $receivedDigestBytes = substr($tokenBytes, $nonceSize);
    $secret = Base64Url::decode(getenv('THEMIS_FINALS_'. $name . '_KEY'));
    $digestBytes = pack('H*', hash('sha256', $nonce . $secret));

    return $digestBytes === $receivedDigestBytes;
}

function verifyMasterToken($token) {
    return verifyToken('MASTER', $token);
}

$authenticationMiddleware = function ($request, $response, $next) use ($logger) {
    $authorized = false;

    $headerName = 'HTTP_' . str_replace('-', '_', strtoupper(getenv('THEMIS_FINALS_AUTH_TOKEN_HEADER')));

    if ($request->hasHeader($headerName)) {
        $authTokenValues = $request->getHeader($headerName);
        if (count($authTokenValues) == 1) {
            $authToken = $authTokenValues[0];
            // $logger->info('AUTH TOKEN ' . $authToken);

            $authorized = verifyMasterToken($authToken);
            // $logger->info('VERIFICATION RESULT ' . print_r($authorized, true));
        }
    }

    if ($authorized) {
        return $next($request, $response);
    }
    return $response->withStatus(401);
};

$app->post('/push', function (Request $request, Response $response) use ($logger) {
    $data = $request->getParsedBody();
    $logger->info('PUSH ' . print_r($data, true));
    enqueueBackgroundJob('push', $data);
    return $response->withStatus(202);
})->add($authenticationMiddleware);

$app->post('/pull', function (Request $request, Response $response) use ($logger) {
    $data = $request->getParsedBody();
    $logger->info('PULL ' . print_r($data, true));
    enqueueBackgroundJob('pull', $data);
    return $response->withStatus(202);
})->add($authenticationMiddleware);


$app->run();

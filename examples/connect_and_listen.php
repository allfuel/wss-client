<?php

declare(strict_types=1);

use Fuel\Wss\Client;
use Fuel\Wss\ClientConfig;

require __DIR__ . '/../vendor/autoload.php';

$host = getenv('FUEL_WSS_HOST') ?: 'wss.vask.dev';
$port = (int) (getenv('FUEL_WSS_PORT') ?: 443);
$useTls = filter_var(getenv('FUEL_WSS_TLS') ?: 'true', FILTER_VALIDATE_BOOL);
$appKey = getenv('FUEL_WSS_APP_KEY') ?: 'vask-homepage';
$appSecret = getenv('FUEL_WSS_APP_SECRET')
    ?: '7b4dae81fba6f43ff3a5cbc0a12b3c3d0840ddbcbea8ee80f2f78c086a00a00b';
$path = getenv('FUEL_WSS_PATH')
    ?: '/app/vask-homepage?protocol=7&client=fuel-wss-php&version=0.1&flash=false';
$subprotocol = getenv('FUEL_WSS_SUBPROTOCOL') ?: 'pusher';

$config = new ClientConfig(
    host: $host,
    port: $port,
    useTls: $useTls,
    appKey: $appKey,
    appSecret: $appSecret,
    path: $path,
    timeoutSeconds: 10.0,
    pingIntervalSeconds: 30.0,
    tlsVerifyPeer: true,
    subprotocol: $subprotocol
);

$client = new Client($config);
$client->on('open', static function (): void {
    echo "Connected.\n";
});
$client->on('message', static function (string $message): void {
    echo "Message: {$message}\n";

    try {
        $decoded = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException) {
        return;
    }

    if (is_array($decoded) && isset($decoded['event'])) {
        $event = (string) $decoded['event'];
        echo "Event: {$event}\n";
    }
});
$client->on('error', static function (\Throwable $error): void {
    echo "Error: {$error->getMessage()}\n";
});
$client->on('close', static function (): void {
    echo "Closed.\n";
});

$client->connect();

$stream = $client->stream();
$deadline = microtime(true) + 5.0;

while (microtime(true) < $deadline) {
    $read = $stream === null ? [] : [$stream];
    $write = null;
    $except = null;

    $ready = $stream === null ? 0 : stream_select($read, $write, $except, 0, 200000);
    if ($ready === false) {
        throw new \RuntimeException('stream_select failed.');
    }

    $client->tick();
}

$client->close();

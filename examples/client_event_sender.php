<?php

declare(strict_types=1);

use Fuel\Wss\Client;
use Fuel\Wss\ClientConfig;

require __DIR__.'/../vendor/autoload.php';

$host = getenv('FUEL_WSS_HOST') ?: 'wss.vask.dev';
$port = (int) (getenv('FUEL_WSS_PORT') ?: 443);
$useTls = filter_var(getenv('FUEL_WSS_TLS') ?: 'true', FILTER_VALIDATE_BOOL);
$appKey = getenv('FUEL_WSS_APP_KEY') ?: 'vask-homepage';
$appSecret = getenv('FUEL_WSS_APP_SECRET')
    ?: '7b4dae81fba6f43ff3a5cbc0a12b3c3d0840ddbcbea8ee80f2f78c086a00a00b';
$path = getenv('FUEL_WSS_PATH')
    ?: '/app/vask-homepage?protocol=7&client=fuel-wss-php&version=0.1&flash=false';
$subprotocol = getenv('FUEL_WSS_SUBPROTOCOL') ?: 'pusher';
$channel = getenv('FUEL_WSS_CHANNEL') ?: 'presence-fuel-websocket-test';
$eventName = getenv('FUEL_WSS_EVENT') ?: 'client-fuel-test';
$duration = (float) (getenv('FUEL_WSS_DURATION') ?: 10.0);
$userId = getenv('FUEL_WSS_USER_ID') ?: sprintf('sender-%d', getmypid());
$userName = getenv('FUEL_WSS_USER_NAME') ?: sprintf('Sender %d', getmypid());

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
$sent = false;

$client->on('open', static function (): void {
    echo "Connected.\n";
});
$client->on('pusher:connection_established', function (mixed $data, ?string $eventChannel, array $payload) use ($client, $channel, $userId, $userName): void {
    echo "Connection established. Subscribing to {$channel}...\n";
    $client->subscribe($channel, [
        'channel_data' => [
            'user_id' => $userId,
            'user_info' => ['name' => $userName],
        ],
    ]);
});
$client->on('pusher_internal:subscription_succeeded', function (mixed $data, ?string $eventChannel, array $payload) use ($client, $channel, $eventName, &$sent): void {
    if ($eventChannel !== $channel || $sent) {
        return;
    }

    $payload = [
        'message' => 'Hello from the sender',
        'sent_at' => date('c'),
    ];
    $client->sendClientEvent($channel, $eventName, $payload);
    $sent = true;
    echo "Sent {$eventName} on {$channel}.\n";
});
$client->on('error', static function (Throwable $error): void {
    echo "Error: {$error->getMessage()}\n";
});
$client->on('close', static function (): void {
    echo "Closed.\n";
});

$client->connect();

$stream = $client->stream();
$deadline = microtime(true) + $duration;

while (microtime(true) < $deadline) {
    $read = $stream === null ? [] : [$stream];
    $write = null;
    $except = null;

    $ready = $stream === null ? 0 : stream_select($read, $write, $except, 0, 200000);
    if ($ready === false) {
        throw new RuntimeException('stream_select failed.');
    }

    $client->tick();
}

$client->close();

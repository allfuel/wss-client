<?php

declare(strict_types=1);

use Fuel\Wss\Client;
use Fuel\Wss\ClientConfig;

require __DIR__.'/../vendor/autoload.php';

$host = getenv('FUEL_WSS_HOST') ?: 'wss.vask.dev';
$port = (int) (getenv('FUEL_WSS_PORT') ?: 443);
$useTls = filter_var(getenv('FUEL_WSS_TLS') ?: 'true', FILTER_VALIDATE_BOOL);
$origin = getenv('FUEL_WSS_ORIGIN');
$origin = ($origin === false || $origin === '')
    ? (($useTls ? 'https' : 'http').'://localhost')
    : $origin;
$appKey = getenv('FUEL_WSS_APP_KEY') ?: 'app_key';
$appSecret = getenv('FUEL_WSS_APP_SECRET')
    ?: 'app_secret';
$path = getenv('FUEL_WSS_PATH')
    ?: sprintf('/app/%s?protocol=7&client=fuel-wss-php&version=0.1&flash=false', $appKey);
$subprotocol = getenv('FUEL_WSS_SUBPROTOCOL') ?: 'pusher';
$channel = getenv('FUEL_WSS_CHANNEL') ?: 'presence-fuel-websocket-test';
$eventName = getenv('FUEL_WSS_EVENT') ?: 'client-fuel-test';
$duration = (float) (getenv('FUEL_WSS_DURATION') ?: 20.0);
$userId = getenv('FUEL_WSS_USER_ID') ?: sprintf('listener-%d', getmypid());
$userName = getenv('FUEL_WSS_USER_NAME') ?: sprintf('Listener %d', getmypid());

$config = new ClientConfig(
    host: $host,
    port: $port,
    useTls: $useTls,
    appKey: $appKey,
    appSecret: $appSecret,
    path: $path,
    origin: $origin,
    timeoutSeconds: 10.0,
    pingIntervalSeconds: 30.0,
    tlsVerifyPeer: true,
    subprotocol: $subprotocol
);

$client = new Client($config);
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
$client->on('pusher_internal:subscription_succeeded', function (mixed $data, ?string $eventChannel, array $payload) use ($channel): void {
    if ($eventChannel !== $channel) {
        return;
    }

    echo "Subscription succeeded. Listening for {$eventChannel}.\n";
});
$client->on($eventName, function (mixed $data, ?string $eventChannel, array $payload) use ($eventName): void {
    $payloadJson = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    echo "Received {$eventName}: {$payloadJson}\n";
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

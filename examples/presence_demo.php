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
$duration = (float) (getenv('FUEL_WSS_DURATION') ?: 20.0);
$userId = getenv('FUEL_WSS_USER_ID') ?: sprintf('php-%d', getmypid());
$userName = getenv('FUEL_WSS_USER_NAME') ?: sprintf('PHP %d', getmypid());

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
$client->on('pusher_internal:subscription_succeeded', function (mixed $data, ?string $eventChannel, array $payload) use ($client, $channel): void {
    if ($eventChannel !== $channel) {
        return;
    }

    $count = $client->presenceCount($channel);
    echo "Subscription succeeded. Members: {$count}.\n";
});
$client->on('pusher_internal:member_added', function (mixed $data, ?string $eventChannel, array $payload) use ($client, $channel): void {
    if ($eventChannel !== $channel) {
        return;
    }

    $count = $client->presenceCount($channel);
    echo "Member joined. Members: {$count}.\n";
});
$client->on('pusher_internal:member_removed', function (mixed $data, ?string $eventChannel, array $payload) use ($client, $channel): void {
    if ($eventChannel !== $channel) {
        return;
    }

    $count = $client->presenceCount($channel);
    echo "Member left. Members: {$count}.\n";
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

<?php

declare(strict_types=1);

use Fuel\Wss\Client;
use Fuel\Wss\ClientConfig;

require __DIR__.'/../vendor/autoload.php';

$config = new ClientConfig(
    host: 'wss.vask.dev',
    port: 443,
    useTls: true,
    appKey: 'hindlepod2-dev-wnffgg',
    appSecret: '157bad2c029878243d2d818bc759faee2c14a8e9bec2728465d37f9da8a3bcac',
    origin: 'https://fuel.test',
    timeoutSeconds: 10.0,
    pingIntervalSeconds: 30.0,
    tlsVerifyPeer: true,
    subprotocol: 'pusher',
    autoReconnect: true
);

$channel = 'presence-fuel-9857ecbe-51dc-4143-90b3-baa1ee036bc0';
$userId = 'listener-'.getmypid();
$userName = 'Listener '.getmypid();

$client = new Client($config);

$client->on('open', static function (): void {
    echo "[OPEN] Connected to server\n";
});

$client->on('pusher:connection_established', static function ($data, $ch, $raw) use ($client, $channel, $userId, $userName): void {
    $socketId = $data['socket_id'] ?? 'unknown';
    echo "[CONNECTION] Socket ID: {$socketId}\n";
    echo "[SUBSCRIBE] Subscribing to presence channel: {$channel}\n";
    $client->subscribe($channel, [
        'channel_data' => [
            'user_id' => $userId,
            'user_info' => ['name' => $userName],
        ],
    ]);
});

$client->on('pusher_internal:subscription_succeeded', static function ($data, $ch) use ($client, $channel): void {
    echo "[SUBSCRIBED] Successfully subscribed to: {$ch}\n";
    $count = $client->presenceCount($channel);
    echo "[PRESENCE] Current members: {$count}\n";
});

$client->on('pusher_internal:member_added', static function ($data, $ch) use ($client, $channel): void {
    $userId = $data['user_id'] ?? 'unknown';
    $userInfo = $data['user_info'] ?? [];
    $name = $userInfo['name'] ?? $userId;
    $count = $client->presenceCount($channel);
    echo "[MEMBER JOINED] {$name} (ID: {$userId}) | Total: {$count}\n";
});

$client->on('pusher_internal:member_removed', static function ($data, $ch) use ($client, $channel): void {
    $userId = $data['user_id'] ?? 'unknown';
    $count = $client->presenceCount($channel);
    echo "[MEMBER LEFT] ID: {$userId} | Total: {$count}\n";
});

$client->on('message', static function (string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] RAW: {$message}\n";

    try {
        $decoded = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded) && isset($decoded['event'])) {
            $event = $decoded['event'];
            $eventChannel = $decoded['channel'] ?? null;
            $data = $decoded['data'] ?? null;

            if (is_string($data)) {
                $data = json_decode($data, true) ?? $data;
            }

            echo "[{$timestamp}] EVENT: {$event}";
            if ($eventChannel !== null) {
                echo " | CHANNEL: {$eventChannel}";
            }
            echo "\n";

            if ($data !== null) {
                echo "[{$timestamp}] DATA: ".json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
            }
        }
    } catch (\JsonException) {
        // Not JSON, already printed raw
    }

    echo str_repeat('-', 60)."\n";
});

$client->on('error', static function (\Throwable $error): void {
    echo "[ERROR] {$error->getMessage()}\n";
});

$client->on('close', static function (): void {
    echo "[CLOSE] Connection closed\n";
});

$client->on('reconnect_scheduled', static function (float $delay): void {
    echo "[RECONNECT] Scheduled in {$delay}s\n";
});

$client->on('reconnecting', static function (): void {
    echo "[RECONNECT] Attempting to reconnect...\n";
});

$client->on('reconnected', static function (): void {
    echo "[RECONNECT] Successfully reconnected\n";
});

echo "Connecting to wss.vask.dev...\n";
echo "Presence Channel: {$channel}\n";
echo "User ID: {$userId}\n";
echo "Press Ctrl+C to exit\n\n";

$client->connect();

$stream = $client->stream();

while (true) {
    $read = $stream === null ? [] : [$stream];
    $write = null;
    $except = null;

    $ready = $stream === null ? 0 : @stream_select($read, $write, $except, 1, 0);
    if ($ready === false) {
        // stream_select can fail on signals, just continue
        continue;
    }

    $client->tick();
    $stream = $client->stream();
}

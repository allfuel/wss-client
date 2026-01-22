<?php

declare(strict_types=1);

use Fuel\Wss\Client;
use Fuel\Wss\ClientConfig;

require __DIR__.'/../vendor/autoload.php';

if (! function_exists('pcntl_fork')) {
    fwrite(STDERR, "pcntl is required to run this demo.\n");
    exit(1);
}

$host = getenv('FUEL_WSS_HOST') ?: 'wss.vask.dev';
$port = (int) (getenv('FUEL_WSS_PORT') ?: 443);
$useTls = filter_var(getenv('FUEL_WSS_TLS') ?: 'true', FILTER_VALIDATE_BOOL);
$origin = getenv('FUEL_WSS_ORIGIN');
$origin = ($origin === false || $origin === '')
    ? 'https://test.test'
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

$debug = filter_var(getenv('FUEL_WSS_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);

$logPusherError = static function (string $label, mixed $data): void {
    if (! is_array($data)) {
        $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES);
        echo "{$label} pusher:error data={$dataJson}\n";

        return;
    }

    $code = $data['code'] ?? null;
    $message = $data['message'] ?? null;

    $suffix = '';
    if (is_scalar($code)) {
        $suffix .= " code={$code}";
    }

    if (is_string($message) && $message !== '') {
        $suffix .= " message=\"{$message}\"";
    }

    $dataJson = json_encode($data, JSON_UNESCAPED_SLASHES);
    echo "{$label} pusher:error{$suffix} data={$dataJson}\n";
};

if ($debug) {
    $logWireMessage = static function (string $label, string $message): void {
        $decoded = json_decode($message, true);
        if (! is_array($decoded) || ! isset($decoded['event'])) {
            echo "{$label} message: {$message}\n";

            return;
        }

        $event = (string) $decoded['event'];
        $channel = isset($decoded['channel']) ? (string) $decoded['channel'] : null;
        $suffix = $channel !== null ? " channel={$channel}" : '';

        echo "{$label} event={$event}{$suffix}\n";
    };
}

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

$pid = pcntl_fork();
if ($pid === -1) {
    throw new RuntimeException('Failed to fork listener process.');
}

if ($pid === 0) {
    $listenerId = sprintf('listener-%d', getmypid());
    $listenerName = sprintf('Listener %d', getmypid());
    $listener = new Client($config);
    $received = false;

    $listener->on('open', static function (): void {
        echo "Listener connected.\n";
    });
    $listener->on('pusher:error', static function (mixed $data) use ($logPusherError): void {
        $logPusherError('Listener', $data);
    });
    if ($debug) {
        $listener->on('message', static function (string $message) use ($logWireMessage): void {
            $logWireMessage('Listener', $message);
        });
    }
    $listener->on('pusher:connection_established', function () use ($listener, $channel, $listenerId, $listenerName): void {
        $listener->subscribe($channel, [
            'channel_data' => [
                'user_id' => $listenerId,
                'user_info' => ['name' => $listenerName],
            ],
        ]);
    });
    $listener->on('pusher_internal:subscription_succeeded', function (mixed $data, ?string $eventChannel) use ($listener, $channel): void {
        if ($eventChannel !== $channel) {
            return;
        }

        $count = $listener->presenceCount($channel);
        echo "Listener subscribed. Members: {$count}.\n";
    });
    $listener->on('pusher_internal:member_added', function (mixed $data, ?string $eventChannel) use ($listener, $channel): void {
        if ($eventChannel !== $channel) {
            return;
        }

        $count = $listener->presenceCount($channel);
        echo "Listener member joined. Members: {$count}.\n";
    });
    $listener->on('pusher_internal:member_removed', function (mixed $data, ?string $eventChannel) use ($listener, $channel): void {
        if ($eventChannel !== $channel) {
            return;
        }

        $count = $listener->presenceCount($channel);
        echo "Listener member left. Members: {$count}.\n";
    });
    $listener->on($eventName, function () use (&$received, $eventName): void {
        $received = true;
        echo "Listener received {$eventName}.\n";
    });
    $listener->on('error', static function (Throwable $error): void {
        echo "Listener error: {$error->getMessage()}\n";
    });

    $listener->connect();
    $deadline = microtime(true) + $duration;

    while (microtime(true) < $deadline && ! $received) {
        $stream = $listener->stream();
        $read = $stream === null ? [] : [$stream];
        $write = null;
        $except = null;

        $ready = $stream === null ? 0 : stream_select($read, $write, $except, 0, 200000);
        if ($ready === false) {
            throw new RuntimeException('Listener stream_select failed.');
        }

        $listener->tick();
    }

    $listener->close();
    exit($received ? 0 : 1);
}

$senderId = sprintf('sender-%d', getmypid());
$senderName = sprintf('Sender %d', getmypid());
$sender = new Client($config);
$sent = false;

$sender->on('open', static function (): void {
    echo "Sender connected.\n";
});
$sender->on('pusher:error', static function (mixed $data) use ($logPusherError): void {
    $logPusherError('Sender', $data);
});
if ($debug) {
    $sender->on('message', static function (string $message) use ($logWireMessage): void {
        $logWireMessage('Sender', $message);
    });
}
$sender->on('pusher:connection_established', function () use ($sender, $channel, $senderId, $senderName): void {
    $sender->subscribe($channel, [
        'channel_data' => [
            'user_id' => $senderId,
            'user_info' => ['name' => $senderName],
        ],
    ]);
});
$sender->on('pusher_internal:subscription_succeeded', function (mixed $data, ?string $eventChannel) use ($sender, $channel, $eventName, &$sent): void {
    if ($eventChannel !== $channel) {
        return;
    }

    $count = $sender->presenceCount($channel);
    echo "Sender subscribed. Members: {$count}.\n";
    if (! $sent) {
        usleep(500000);
        $sender->sendClientEvent($channel, $eventName, ['message' => 'hello from sender']);
        echo "Sender emitted {$eventName}.\n";
        $sent = true;
    }
});
$sender->on('pusher_internal:member_added', function (mixed $data, ?string $eventChannel) use ($sender, $channel): void {
    if ($eventChannel !== $channel) {
        return;
    }

    $count = $sender->presenceCount($channel);
    echo "Sender member joined. Members: {$count}.\n";
});
$sender->on('pusher_internal:member_removed', function (mixed $data, ?string $eventChannel) use ($sender, $channel): void {
    if ($eventChannel !== $channel) {
        return;
    }

    $count = $sender->presenceCount($channel);
    echo "Sender member left. Members: {$count}.\n";
});
$sender->on('error', static function (Throwable $error): void {
    echo "Sender error: {$error->getMessage()}\n";
});

$sender->connect();
$deadline = microtime(true) + $duration;

while (microtime(true) < $deadline) {
    $stream = $sender->stream();
    $read = $stream === null ? [] : [$stream];
    $write = null;
    $except = null;

    $ready = $stream === null ? 0 : stream_select($read, $write, $except, 0, 200000);
    if ($ready === false) {
        throw new RuntimeException('Sender stream_select failed.');
    }

    $sender->tick();
}

$sender->close();

$status = 0;
pcntl_waitpid($pid, $status);
$listenerExit = pcntl_wexitstatus($status);

if ($sent && $listenerExit === 0) {
    echo "Deterministic demo complete.\n";
    exit(0);
}

echo "Deterministic demo failed.\n";
exit(1);

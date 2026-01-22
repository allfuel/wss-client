## Fuel\Wss PHP Client API

This library is a small, hookable WebSocket client that speaks the Pusher protocol. It is designed to
be driven by your own event loop (e.g. a Fuel daemon loop).

This file documents the public surface area you typically need to integrate it.

### Install

```bash
composer require fuel/wss
```

### Core Types

#### `Fuel\Wss\ClientConfig`

Constructor:

```php
new ClientConfig(
    string $host,
    int $port,
    bool $useTls,
    string $appKey,
    ?string $appSecret = null,
    ?string $path = null,
    ?string $origin = null,
    float $timeoutSeconds = 10.0,
    float $pingIntervalSeconds = 30.0,
    bool $tlsVerifyPeer = true,
    ?string $subprotocol = null,
    bool $autoReconnect = true,
    float $reconnectIntervalSeconds = 1.0,
    float $maxReconnectIntervalSeconds = 30.0,
)
```

Notes:

- `path` should include the Pusher query params when talking to a Pusher-compatible server.
  Common default:

  ```text
  /app/{APP_KEY}?protocol=7&client=fuel-wss-php&version=0.1&flash=false
  ```

- `origin` controls the HTTP `Origin` header sent during the WebSocket handshake. Some servers/WAFs
  require this to match an allow-list.

#### `Fuel\Wss\Client`

Create a client:

```php
$client = new Client($config);
```

Key methods:

- `connect(): void` open the socket + perform the WebSocket handshake
- `tick(?float $now = null): void` read/write frames, parse messages, send ping/pong, reconnect when enabled
- `close(): void` request a clean close
- `isConnected(): bool`
- `stream(): resource|null` underlying stream for use with `stream_select()`

Pusher-specific helpers:

- `subscribe(string $channel, array $options = []): void`
  - For private/presence channels, the client will auto-sign when `appSecret` is configured
  - `options['channel_data']` is required for presence channels
- `sendClientEvent(string $channel, string $eventName, array $payload): void`
  - `eventName` must start with `client-`
  - `channel` must be `private-*` or `presence-*`

Presence helpers:

- `presenceState(string $channel): ?Fuel\Wss\Pusher\PresenceState`
- `presenceCount(string $channel): ?int`

### Driving The Client (No Built-in Loop)

The client does not own an event loop. Use your own loop and call `tick()` regularly.

```php
$client->connect();

while (true) {
    $stream = $client->stream();
    $read = $stream === null ? [] : [$stream];
    $write = null;
    $except = null;

    if ($stream !== null) {
        stream_select($read, $write, $except, 0, 200_000);
    }

    $client->tick();
}
```

### Integration Pattern (Fuel Daemon)

In a long-running daemon you typically:

- connect once
- use `stream_select()` to block briefly for IO
- call `tick()` every loop iteration
- subscribe in `pusher:connection_established`
- log `pusher:error` events (they include server-side error codes)

```php
$client->on('open', static fn () => fwrite(STDOUT, "wss connected\n"));
$client->on('close', static fn () => fwrite(STDOUT, "wss closed\n"));
$client->on('error', static fn (Throwable $e) => fwrite(STDERR, $e->getMessage()."\n"));
$client->on('pusher:error', static function (mixed $data): void {
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, "pusher:error {$json}\n");
});

$client->on('pusher:connection_established', static function () use ($client): void {
    // Subscribe once the socket_id is known.
    $client->subscribe('presence-your-channel', [
        'channel_data' => [
            'user_id' => 'daemon-1',
            'user_info' => ['name' => 'Fuel daemon'],
        ],
    ]);
});

$client->connect();

while (true) {
    $stream = $client->stream();
    $read = $stream === null ? [] : [$stream];
    $write = null;
    $except = null;

    if ($stream !== null) {
        // Wait up to 200ms for socket activity.
        stream_select($read, $write, $except, 0, 200_000);
    } else {
        // When disconnected, tick() still drives reconnect timers.
        usleep(200_000);
    }

    $client->tick();
}
```

### Events

Register listeners:

```php
$client->on('open', fn () => print("Connected\n"));
$client->on('message', fn (string $raw) => print($raw."\n"));
$client->on('error', fn (Throwable $e) => print($e->getMessage()."\n"));
```

Event names you can subscribe to:

- `open`: handshake finished
- `message`: raw JSON string
- `pusher:*`: decoded Pusher events (e.g. `pusher:connection_established`, `pusher:error`)
- `error`: `(Throwable $error)`
- `close`: socket closed
- `reconnect_scheduled`: `(float $delaySeconds, float $reconnectAtTimestamp)`
- `reconnecting`: `(float $delaySeconds)`
- `reconnected`: reconnect succeeded
- `reconnect_failed`: `(Throwable $error)`

For Pusher events, your handler receives:

```php
function (mixed $data, ?string $channel, array $fullPayload): void
```

### Examples

- `examples/connect_and_listen.php`
- `examples/presence_demo.php`
- `examples/client_event_listener.php`
- `examples/client_event_sender.php`
- `examples/deterministic_demo.php`

## Fuel WSS PHP Client

Fuel\Wss is a hookable PHP websocket client that speaks the Pusher protocol over TLS. It is designed
to be driven by an external loop, so you can integrate it with Fuel daemons or any custom event loop.

### Quickstart

```bash
composer install
php examples/connect_and_listen.php
```

The examples default to the public demo server at `wss.vask.dev`. Override settings with environment
variables if you want to point at a local Soketi instance.

Fuel\Wss does not own an event loop. Use `stream_select()` (or your own loop) and call `tick()` to
drive IO and timers.

```php
$client->connect();

$stream = $client->stream();
while (true) {
    $read = $stream === null ? [] : [$stream];
    $write = null;
    $except = null;

    if ($stream !== null) {
        stream_select($read, $write, $except, 0, 200000);
    }

    $client->tick();
}
```

### Events

- `open`: websocket handshake finished
- `message`: raw JSON string
- `pusher:*`: decoded Pusher events
- `error`: \Throwable instance
- `close`: socket closed
- `reconnect_scheduled`: `(float $delay, float $at)`
- `reconnecting`: `(float $delay)`
- `reconnected`: reconnect succeeded
- `reconnect_failed`: \Throwable instance

### Environment Variables

- `FUEL_WSS_HOST` (default `wss.vask.dev`)
- `FUEL_WSS_PORT` (default `443`)
- `FUEL_WSS_TLS` (default `true`)
- `FUEL_WSS_APP_KEY` (default `vask-homepage`)
- `FUEL_WSS_APP_SECRET` (default demo secret)
- `FUEL_WSS_PATH` (default `/app/vask-homepage?protocol=7&client=fuel-wss-php&version=0.1&flash=false`)
- `FUEL_WSS_SUBPROTOCOL` (default `pusher`)
- `FUEL_WSS_CHANNEL` (default `presence-fuel-websocket-test` for presence/client demos)
- `FUEL_WSS_EVENT` (default `client-fuel-test`)
- `FUEL_WSS_DURATION` (default `20` seconds)
- `FUEL_WSS_USER_ID` and `FUEL_WSS_USER_NAME` (presence demos only)

### Reconnects

Auto reconnect is enabled by default. Configure it via `ClientConfig`:

```php
$config = new ClientConfig(
    host: $host,
    port: $port,
    useTls: $useTls,
    appKey: $appKey,
    appSecret: $appSecret,
    autoReconnect: true,
    reconnectIntervalSeconds: 1.0,
    maxReconnectIntervalSeconds: 30.0,
);
```

### Examples

- `php examples/connect_and_listen.php`
  - Connects and prints raw messages plus Pusher events.
- `php examples/presence_demo.php`
  - Subscribes to the presence channel and prints member count changes.
- `php examples/client_event_listener.php`
  - Listens for client events on a private/presence channel.
- `php examples/client_event_sender.php`
  - Sends a `client-*` event after subscription succeeds.
- `php examples/deterministic_demo.php`
  - Forks a listener + sender, then verifies presence updates and client event roundtrip.

### Local Soketi (optional)

```bash
docker run --rm -p 6001:6001 \
  -e SOKETI_DEFAULT_APP_ID=vask-homepage \
  -e SOKETI_DEFAULT_APP_KEY=vask-homepage \
  -e SOKETI_DEFAULT_APP_SECRET=7b4dae81fba6f43ff3a5cbc0a12b3c3d0840ddbcbea8ee80f2f78c086a00a00b \
  -e SOKETI_DEFAULT_APP_ENABLE_CLIENT_MESSAGES=true \
  quay.io/soketi/soketi:latest

FUEL_WSS_HOST=127.0.0.1 FUEL_WSS_PORT=6001 FUEL_WSS_TLS=false php examples/presence_demo.php
```

### Deterministic demo (Soketi)

```bash
docker run --rm -p 6001:6001 \
  -e SOKETI_DEFAULT_APP_ID=vask-homepage \
  -e SOKETI_DEFAULT_APP_KEY=vask-homepage \
  -e SOKETI_DEFAULT_APP_SECRET=7b4dae81fba6f43ff3a5cbc0a12b3c3d0840ddbcbea8ee80f2f78c086a00a00b \
  -e SOKETI_DEFAULT_APP_ENABLE_CLIENT_MESSAGES=true \
  quay.io/soketi/soketi:latest

FUEL_WSS_HOST=127.0.0.1 FUEL_WSS_PORT=6001 FUEL_WSS_TLS=false php examples/deterministic_demo.php
```

The deterministic demo exits with status `0` when the listener receives the client event.

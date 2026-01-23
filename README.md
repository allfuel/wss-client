WARNING: this isn't useful, don't use it


---


## Fuel WSS PHP Client

Fuel\Wss is a hookable PHP websocket client that speaks the Pusher protocol over TLS. It is designed
to be driven by an external loop, so you can integrate it with Fuel daemons or any custom event loop.

### Quickstart

```bash
composer install

# If you're using the public demo host, you must provide a valid app key/secret.
FUEL_WSS_APP_KEY=your_app_key FUEL_WSS_APP_SECRET=your_app_secret \
  php examples/connect_and_listen.php
```

The examples default to `wss.vask.dev` but use placeholder app credentials. Override settings with
environment variables, or point them at a local Soketi instance.

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
- `FUEL_WSS_ORIGIN` (default depends on example; overrides HTTP `Origin` header)
- `FUEL_WSS_APP_KEY` (default `app_key`)
- `FUEL_WSS_APP_SECRET` (default `app_secret`)
- `FUEL_WSS_PATH` (default `/app/{FUEL_WSS_APP_KEY}?protocol=7&client=fuel-wss-php&version=0.1&flash=false`)
- `FUEL_WSS_SUBPROTOCOL` (default `pusher`)
- `FUEL_WSS_CHANNEL` (default `presence-fuel-websocket-test` for presence/client demos)
- `FUEL_WSS_EVENT` (default `client-fuel-test`)
- `FUEL_WSS_DURATION` (default `20` seconds)
- `FUEL_WSS_USER_ID` and `FUEL_WSS_USER_NAME` (presence demos only)
- `FUEL_WSS_DEBUG` (default `false`, deterministic demo only)

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
  -e SOKETI_DEFAULT_APP_ID=fuel-wss-demo \
  -e SOKETI_DEFAULT_APP_KEY=fuel-wss-demo \
  -e SOKETI_DEFAULT_APP_SECRET=dev-secret \
  -e SOKETI_DEFAULT_APP_ENABLE_CLIENT_MESSAGES=true \
  quay.io/soketi/soketi:latest

FUEL_WSS_HOST=127.0.0.1 FUEL_WSS_PORT=6001 FUEL_WSS_TLS=false \
  FUEL_WSS_APP_KEY=fuel-wss-demo FUEL_WSS_APP_SECRET=dev-secret \
  php examples/presence_demo.php
```

### Deterministic demo (Soketi)

```bash
docker run --rm -p 6001:6001 \
  -e SOKETI_DEFAULT_APP_ID=fuel-wss-demo \
  -e SOKETI_DEFAULT_APP_KEY=fuel-wss-demo \
  -e SOKETI_DEFAULT_APP_SECRET=dev-secret \
  -e SOKETI_DEFAULT_APP_ENABLE_CLIENT_MESSAGES=true \
  quay.io/soketi/soketi:latest

FUEL_WSS_HOST=127.0.0.1 FUEL_WSS_PORT=6001 FUEL_WSS_TLS=false \
  FUEL_WSS_APP_KEY=fuel-wss-demo FUEL_WSS_APP_SECRET=dev-secret \
  php examples/deterministic_demo.php
```

The deterministic demo exits with status `0` when the listener receives the client event.

# Epic: PHP Pusher-Compatible WSS Client (e-0d75ee)

## Human Guidance

feel free to add fuel follow up tasks for another agent to work on while you do your primary work

## Plan

Build a hookable PHP websocket client (Fuel\Wss) that speaks Pusher protocol over wss/https, supports channel + presence subscriptions, integrates with Fuel daemon loop (no owned loop). Includes QA tooling (pint/phpstan/pest/rector) and deterministic demo proof. After completion unpause f-280b86 and f-f5abb9.

### Goal

Create a Composer-installable PHP library in the `Fuel\Wss` namespace that can connect to a Pusher-compatible websocket server via `wss` (TLS) using a host/port/app key/secret, and supports:

- Subscribing to channels and listening for events.
- Private + presence authentication (HMAC signing).
- Presence state (member list, member count, member add/remove events).
- Client events (`client-*`) so two PHP processes can communicate through the server.

Critical: the library must be "hookable" for Fuel's daemon loop.

- It must not own an infinite loop.
- It must expose the underlying stream resource.
- It must offer a `tick()` / `poll()` method so Fuel can drive IO + timers.

### Constraints

- PHP 8.5 allowed.
- Use `declare(strict_types=1);` everywhere.
- Prefer built-in PHP functions and stream APIs.
- Assume Fuel's packaged PHP binary does not include `curl` and only includes a minimal set of extensions (e.g. `openssl`, `mbstring`, `pcntl`, `posix`, `zlib`, `sqlite3`, etc.).
- Use PHPStan-friendly array-shapes for payloads.

### High-level Design

Implement two layers:

1) RFC6455 WebSocket client over PHP streams

- Connect via `stream_socket_client()` with TLS context for `wss`.
- Perform the HTTP Upgrade handshake (client key, server accept validation).
- Set non-blocking mode and integrate with external event loops via:
  - `Client::stream(): resource` (socket)
  - `Client::tick(float $now = microtime(true)): void` (read/write frames, handle timers)
- Implement frame encode/decode:
  - client->server MUST be masked
  - server->client is not masked
  - handle fragmentation only if needed; start with text frames and close/ping/pong
- Implement ping/pong and close semantics.

2) Pusher protocol on top

- Build the correct connect URL:
  - `/app/{app_key}?protocol=7&client=fuel-wss-php&version=0.1&flash=false`
- Parse incoming JSON messages into typed array-shapes.
- Handle standard events:
  - `pusher:connection_established` => store `socket_id`
  - `pusher_internal:subscription_succeeded`
  - `pusher_internal:member_added` / `pusher_internal:member_removed`
  - `pusher:error`
- Provide `subscribe()` helpers:
  - public channels: no auth
  - private channels: auth = `{app_key}:{hmac}` with string-to-sign `{socket_id}:{channel}`
  - presence channels: auth as above but string-to-sign `{socket_id}:{channel}:{channel_data_json}`
  - `channel_data` JSON must be stable and deterministic (use `json_encode` with strict options)
- Provide `sendClientEvent()` enforcing `client-` prefix.

Hookability requirements:

- No internal `while (true)` loops.
- No sleeps inside the library.
- All time-based behavior (keepalive, reconnect backoff) is progressed via `tick()`.
- Provide enough surface area for Fuel to:
  - `stream_select()` on the socket
  - call `tick()` when readable/writable or periodically

### Proposed Public API (initial)

- `Fuel\Wss\ClientConfig` (readonly typed properties)
  - `host: string`, `port: int`, `useTls: bool`
  - `appKey: string`, `appSecret: ?string`
  - `path: string` (default `/app/{appKey}`)
  - `timeoutSeconds: float`, `pingIntervalSeconds: float`
  - `tlsVerifyPeer: bool` (default true)
- `Fuel\Wss\Client`
  - `__construct(ClientConfig $config)`
  - `connect(): void`
  - `stream(): mixed` (return socket resource)
  - `socketId(): ?string`
  - `subscribe(string $channel, array $options = []): void`
  - `on(string $event, callable $listener): void`
  - `sendClientEvent(string $channel, string $eventName, array $payload): void`
  - `tick(float $now = microtime(true)): void`
  - `close(): void`

Implementation detail: keep event dispatch simple (small event emitter) and ensure payload types are explicit.

### File Layout

- `src/` (PSR-4 `Fuel\Wss\`)
  - `Client.php`, `ClientConfig.php`
  - `WebSocket/Handshake.php`, `WebSocket/Frame.php`, `WebSocket/Parser.php`
  - `Pusher/Auth.php`, `Pusher/Protocol.php`, `Pusher/PresenceState.php`
  - `Support/EventEmitter.php`
- `examples/`
  - `connect_and_listen.php`
  - `presence_demo.php`
  - `client_event_sender.php`
  - `client_event_listener.php`
- QA tooling config files (pint/phpstan/pest/rector)

### Testing Strategy

Unit tests (Pest):

- WebSocket frame encoding/decoding roundtrips.
- Handshake accept computation.
- Pusher auth signature correctness against known test vectors.
- Presence state transitions given member events.

Integration test / demo proof (required):

Primary target: `wss://wss.vask.dev`

- app_key: `vask-homepage`
- app_id: `vask-homepage`
- secret: `7b4dae81fba6f43ff3a5cbc0a12b3c3d0840ddbcbea8ee80f2f78c086a00a00b`
- channels:
  - public: `fuel-websocket-test`
  - presence: `presence-fuel-websocket-test`

If wss.vask.dev is unreachable, do not stop.

Fallback: start a local Pusher-compatible server (Soketi) and point examples at it.

- Prefer Docker (most deterministic). Example target: `ws://127.0.0.1:6001`.
- Alternative: `npx soketi start` with env vars.

Success proof requirement:

- Provide a demo script (or two scripts) that reliably shows:
  - connect -> subscribe -> receive `pusher:*` internal events
  - presence member count changes (start two clients, observe join/leave)
  - client event roundtrip: sender emits `client-fuel-test` and listener receives it

### QA Tooling

Set up and wire to Composer scripts:

- `composer pint`
- `composer phpstan`
- `composer test`
- `composer rector` (dry-run mode for CI; apply mode for local)

### Fuel Follow-up

After this epic is complete, unpause Fuel tasks `f-280b86` and `f-f5abb9`.

## Acceptance Criteria

- [x] Library provides a hookable API: no internal infinite loop; `stream()` + `tick()` work with `stream_select()`.
- [ ] Successful websocket handshake + message exchange with a Pusher-compatible server (wss.vask.dev OR local soketi fallback).
- [ ] Channel subscription works; user can register listeners and receive events.
- [ ] Presence channels work: subscription succeeded, member add/remove processed, member count exposed.
- [ ] Client events work: one PHP process sends `client-*` event and another receives it.
- [x] Composer package is usable: PSR-4 autoload, strict types everywhere, PHPStan-friendly array shapes.
- [ ] QA tooling installed and runnable: pint/phpstan/pest/rector all pass locally.
- [ ] Examples exist under `examples/` and are documented in `README.md`.
- [ ] "Taylor Otwell would be happy": API feels Laravel-quality (clean naming, predictable behavior), docs/examples are crisp, errors are actionable, and there are no footguns (sane defaults, strict validation, graceful reconnect strategy).
- [ ] "Guaranteed to work" standard: repo includes deterministic integration proof (real server OR local soketi), and a demo script (or two scripts) that reliably shows: connect -> subscribe -> presence count changes -> client event roundtrip between two PHP processes.
- [ ] After epic completion: unpause Fuel tasks `f-280b86` and `f-f5abb9`.

## Progress Log

- Iteration 1: added PSR-4 autoloading and a strict ClientConfig with validation, then smoke-tested autoload.
- Iteration 2: added WebSocket client/handshake/frame parsing with hookable `stream()` + `tick()` and smoke-tested via local loop.

## Implementation Notes
<!-- Tasks: append discoveries, decisions, gotchas here -->

## Interfaces Created
<!-- Tasks: document interfaces/contracts created -->

# Reality

## Architecture
Fuel\Wss is a PHP WebSocket client that speaks the Pusher protocol over TLS. The library provides a
non-blocking client driven by an external event loop (call `tick()` with `stream_select()` or another
loop), handles handshake, frame parsing, and reconnect logic, and exposes events via a lightweight
emitter.

## Modules
| Module | Purpose | Entry Point |
|--------|---------|-------------|
| Client | Public API for connections, subscriptions, events, reconnects | `src/Client.php` |
| Configuration | Connection and timing settings with validation | `src/ClientConfig.php` |
| WebSocket | Handshake, frame encoding/decoding, frame parsing | `src/WebSocket/Handshake.php` |
| Pusher | Auth signing and presence state tracking | `src/Pusher/Auth.php` |
| Support | Event emitter for internal/public events | `src/Support/EventEmitter.php` |
| Examples | Runnable demos for connect/listen/presence/client events | `examples/connect_and_listen.php` |

## Entry Points
- `src/Client.php` exposes the primary `Client` API for consumers.
- `examples/*.php` scripts demonstrate typical usage and are runnable via `php`.
- `composer.json` scripts expose `pint`, `phpstan`, `test`, and `rector` workflows.
- `.fuel/quality-gate` runs all quality checks (pint → phpstan → pest) in sequence; use before committing.

## Patterns
- `declare(strict_types=1)` and `final` classes with typed properties and explicit validation.
- Pusher event handling decodes JSON, emits raw + parsed events, and tracks presence state.
- WebSocket frames are encoded/decoded manually; `Parser` consumes a buffer incrementally.
- Error handling uses exceptions and emits error events instead of silent failures.
- JSON encoding consistently uses `JSON_THROW_ON_ERROR` and `JSON_UNESCAPED_SLASHES`.

## Quality Gates
| Tool | Command | Purpose |
|------|---------|---------|
| Pint | `vendor/bin/pint` | Formatting (Laravel Pint preset) |
| PHPStan | `vendor/bin/phpstan analyse` | Static analysis (level 6) |
| Pest | `vendor/bin/pest` | Test runner |
| Rector | `vendor/bin/rector process --dry-run` | Refactor/quality checks (dry-run) |

## Recent Changes
- 2026-02-22: Added `.fuel/quality-gate` executable script (pint → phpstan → pest)

_Last updated: 2026-02-22 by UpdateReality_

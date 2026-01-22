<?php

declare(strict_types=1);

namespace Fuel\Wss;

use InvalidArgumentException;

final class ClientConfig
{
    public readonly string $host;

    public readonly int $port;

    public readonly bool $useTls;

    public readonly string $appKey;

    public readonly ?string $appSecret;

    public readonly ?string $subprotocol;

    public readonly string $path;

    /**
     * Value used for the HTTP Origin header during the WebSocket handshake.
     *
     * Some hosts (e.g. behind Cloudflare/WAF) validate Origin against an allow-list,
     * which may differ from the websocket host.
     */
    public readonly ?string $origin;

    public readonly float $timeoutSeconds;

    public readonly float $pingIntervalSeconds;

    public readonly bool $tlsVerifyPeer;

    public readonly bool $autoReconnect;

    public readonly float $reconnectIntervalSeconds;

    public readonly float $maxReconnectIntervalSeconds;

    public function __construct(
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
        float $maxReconnectIntervalSeconds = 30.0
    ) {
        if ($host === '') {
            throw new InvalidArgumentException('Host must not be empty.');
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Port must be between 1 and 65535.');
        }

        if ($appKey === '') {
            throw new InvalidArgumentException('App key must not be empty.');
        }

        $resolvedPath = $path ?? "/app/{$appKey}";
        if ($resolvedPath === '') {
            throw new InvalidArgumentException('Path must not be empty.');
        }

        if ($resolvedPath[0] !== '/') {
            $resolvedPath = '/'.$resolvedPath;
        }

        if ($origin !== null && $origin === '') {
            throw new InvalidArgumentException('Origin must not be empty when provided.');
        }

        if ($timeoutSeconds <= 0.0) {
            throw new InvalidArgumentException('Timeout must be greater than zero.');
        }

        if ($pingIntervalSeconds <= 0.0) {
            throw new InvalidArgumentException('Ping interval must be greater than zero.');
        }

        if ($subprotocol !== null && $subprotocol === '') {
            throw new InvalidArgumentException('Subprotocol must not be empty when provided.');
        }

        if ($reconnectIntervalSeconds <= 0.0) {
            throw new InvalidArgumentException('Reconnect interval must be greater than zero.');
        }

        if ($maxReconnectIntervalSeconds <= 0.0) {
            throw new InvalidArgumentException('Max reconnect interval must be greater than zero.');
        }

        if ($maxReconnectIntervalSeconds < $reconnectIntervalSeconds) {
            throw new InvalidArgumentException('Max reconnect interval must be greater than or equal to reconnect interval.');
        }

        $this->host = $host;
        $this->port = $port;
        $this->useTls = $useTls;
        $this->appKey = $appKey;
        $this->appSecret = $appSecret;
        $this->path = $resolvedPath;
        $this->origin = $origin;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->pingIntervalSeconds = $pingIntervalSeconds;
        $this->tlsVerifyPeer = $tlsVerifyPeer;
        $this->subprotocol = $subprotocol;
        $this->autoReconnect = $autoReconnect;
        $this->reconnectIntervalSeconds = $reconnectIntervalSeconds;
        $this->maxReconnectIntervalSeconds = $maxReconnectIntervalSeconds;
    }
}

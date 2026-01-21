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
    public readonly float $timeoutSeconds;
    public readonly float $pingIntervalSeconds;
    public readonly bool $tlsVerifyPeer;

    public function __construct(
        string $host,
        int $port,
        bool $useTls,
        string $appKey,
        ?string $appSecret = null,
        ?string $path = null,
        float $timeoutSeconds = 10.0,
        float $pingIntervalSeconds = 30.0,
        bool $tlsVerifyPeer = true,
        ?string $subprotocol = null
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
            $resolvedPath = '/' . $resolvedPath;
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

        $this->host = $host;
        $this->port = $port;
        $this->useTls = $useTls;
        $this->appKey = $appKey;
        $this->appSecret = $appSecret;
        $this->path = $resolvedPath;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->pingIntervalSeconds = $pingIntervalSeconds;
        $this->tlsVerifyPeer = $tlsVerifyPeer;
        $this->subprotocol = $subprotocol;
    }
}

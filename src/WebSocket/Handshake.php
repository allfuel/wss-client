<?php

declare(strict_types=1);

namespace Fuel\Wss\WebSocket;

use Fuel\Wss\ClientConfig;
use RuntimeException;

final class Handshake
{
    private const MAGIC_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(16));
    }

    public static function buildRequest(ClientConfig $config, string $key): string
    {
        $host = $config->host;
        $port = $config->port;
        $path = $config->path;
        $defaultPort = $config->useTls ? 443 : 80;
        $hostHeader = $port === $defaultPort ? $host : sprintf('%s:%d', $host, $port);
        $originScheme = $config->useTls ? 'https' : 'http';

        $origin = $config->origin;
        if ($origin === null) {
            $origin = sprintf('%s://%s', $originScheme, $host);
        }

        $headers = [
            sprintf('GET %s HTTP/1.1', $path),
            sprintf('Host: %s', $hostHeader),
            sprintf('Origin: %s', $origin),
            'User-Agent: fuel-wss-php/0.1',
            'Upgrade: websocket',
            'Connection: Upgrade',
            sprintf('Sec-WebSocket-Key: %s', $key),
            'Sec-WebSocket-Version: 13',
        ];

        if ($config->subprotocol !== null) {
            $headers[] = sprintf('Sec-WebSocket-Protocol: %s', $config->subprotocol);
        }

        $headers[] = "\r\n";

        return implode("\r\n", $headers);
    }

    public static function validateResponse(string $response, string $key): void
    {
        if (! str_contains($response, "\r\n\r\n")) {
            throw new RuntimeException('Handshake response is incomplete.');
        }

        $headerBlock = strstr($response, "\r\n\r\n", true);
        $lines = preg_split('/\r\n/', $headerBlock);
        $statusLine = $lines[0] ?? '';

        if (! str_starts_with($statusLine, 'HTTP/1.1 101')) {
            throw new RuntimeException(sprintf('Handshake failed: expected HTTP/1.1 101 but got "%s".', $statusLine));
        }

        $accept = null;
        foreach (array_slice($lines, 1) as $line) {
            if (str_starts_with(strtolower($line), 'sec-websocket-accept:')) {
                $accept = trim(substr($line, strlen('sec-websocket-accept:')));
                break;
            }
        }

        if ($accept === null) {
            throw new RuntimeException('Handshake failed: missing Sec-WebSocket-Accept header.');
        }

        $expected = self::expectedAccept($key);
        if (! hash_equals($expected, $accept)) {
            throw new RuntimeException('Handshake failed: invalid Sec-WebSocket-Accept header.');
        }
    }

    private static function expectedAccept(string $key): string
    {
        return base64_encode(sha1($key.self::MAGIC_GUID, true));
    }
}

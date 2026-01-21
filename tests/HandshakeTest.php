<?php

declare(strict_types=1);

use Fuel\Wss\WebSocket\Handshake;

it('validates websocket handshake response', function (): void {
    $key = 'dGhlIHNhbXBsZSBub25jZQ==';
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        ."Upgrade: websocket\r\n"
        ."Connection: Upgrade\r\n"
        ."Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n\r\n";

    Handshake::validateResponse($response, $key);

    expect(true)->toBeTrue();
});

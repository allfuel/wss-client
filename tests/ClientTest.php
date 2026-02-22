<?php

declare(strict_types=1);

use Fuel\Wss\Client;
use Fuel\Wss\ClientConfig;
use Fuel\Wss\WebSocket\Parser;

it('responds to pusher:ping with pusher:pong', function (): void {
    $client = new Client(new ClientConfig(
        host: 'example.com',
        port: 443,
        useTls: true,
        appKey: 'app-key',
        autoReconnect: false,
    ));

    $stream = fopen('php://temp', 'r+');
    expect($stream)->not->toBeFalse();

    // Write an unmasked server text frame containing {"event":"pusher:ping"}
    $payload = '{"event":"pusher:ping"}';
    $frame = chr(0x81).chr(strlen($payload)).$payload;
    fwrite($stream, $frame);
    rewind($stream);

    $reflector = new ReflectionClass($client);
    $streamProperty = $reflector->getProperty('stream');
    $streamProperty->setValue($client, $stream);

    $lastPingProperty = $reflector->getProperty('lastPingAt');
    $lastPingProperty->setValue($client, microtime(true));

    $client->tick();

    // Read what the client wrote back to the stream
    rewind($stream);
    $written = stream_get_contents($stream);
    $parser = new Parser;
    $parser->append($written);
    $frames = $parser->parse();
    $pongFrames = array_filter($frames, static fn (array $frame): bool => str_contains($frame['payload'], 'pusher:pong'));

    // The response should contain a pusher:pong event
    expect($pongFrames)->not->toBeEmpty();
});

it('handles a close frame without reading feof on a null stream', function (): void {
    $client = new Client(new ClientConfig(
        host: 'example.com',
        port: 443,
        useTls: true,
        appKey: 'app-key',
        autoReconnect: false,
    ));

    $stream = fopen('php://temp', 'r+');
    expect($stream)->not->toBeFalse();

    fwrite($stream, "\x88\x00");
    rewind($stream);

    $reflector = new ReflectionClass($client);
    $streamProperty = $reflector->getProperty('stream');
    $streamProperty->setValue($client, $stream);

    $lastPingProperty = $reflector->getProperty('lastPingAt');
    $lastPingProperty->setValue($client, 0.5);

    $closedCount = 0;
    $client->on('close', function () use (&$closedCount): void {
        $closedCount++;
    });

    $client->tick(1.0);

    expect($client->isConnected())->toBeFalse();
    expect($closedCount)->toBe(1);
});

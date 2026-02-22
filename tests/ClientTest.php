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

    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    expect($pair)->not->toBeFalse();

    [$clientStream, $peerStream] = $pair;
    stream_set_blocking($clientStream, false);
    stream_set_blocking($peerStream, false);

    // Write an unmasked server text frame containing {"event":"pusher:ping"}
    $payload = '{"event":"pusher:ping"}';
    $frame = chr(0x81).chr(strlen($payload)).$payload;
    fwrite($peerStream, $frame);

    $reflector = new ReflectionClass($client);
    $streamProperty = $reflector->getProperty('stream');
    $streamProperty->setValue($client, $clientStream);

    $lastPingProperty = $reflector->getProperty('lastPingAt');
    $lastPingProperty->setValue($client, microtime(true));

    $client->tick();

    // Read what the client wrote back to the stream
    $written = stream_get_contents($peerStream);

    $parser = new Parser;
    $parser->append($written);
    $frames = $parser->parse();

    expect($frames)->toHaveCount(1);
    expect($frames[0]['payload'])->toContain('pusher:pong');
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

<?php

declare(strict_types=1);

use Fuel\Wss\Client;
use Fuel\Wss\ClientConfig;

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

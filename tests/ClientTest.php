<?php

declare(strict_types=1);

use Fuel\Wss\Client;
use Fuel\Wss\ClientConfig;
use Fuel\Wss\WebSocket\Parser;

final class StalledWriteStream
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_cast(int $castAs)
    {
        return false;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_close(): void {}
}

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

it('emits close metadata for remote close frames', function (): void {
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

    $payload = pack('n', 4201).'Pong reply not received';
    $frame = chr(0x88).chr(strlen($payload)).$payload;
    fwrite($peerStream, $frame);

    $reflector = new ReflectionClass($client);
    $streamProperty = $reflector->getProperty('stream');
    $streamProperty->setValue($client, $clientStream);

    $socketIdProperty = $reflector->getProperty('socketId');
    $socketIdProperty->setValue($client, '123.456');

    $closeMeta = null;
    $client->on('close_meta', function (array $meta) use (&$closeMeta): void {
        $closeMeta = $meta;
    });

    $client->tick();

    expect($client->isConnected())->toBeFalse();
    expect($closeMeta)->not->toBeNull();
    expect($closeMeta['source'])->toBe('remote_close_frame');
    expect($closeMeta['socket_id'])->toBe('123.456');
    expect($closeMeta['close_code'])->toBe(4201);
    expect($closeMeta['close_reason'])->toBe('Pong reply not received');
});

it('disconnects when writes stall beyond configured timeout', function (): void {
    if (in_array('stallws', stream_get_wrappers(), true)) {
        stream_wrapper_unregister('stallws');
    }
    stream_wrapper_register('stallws', StalledWriteStream::class);

    try {
        $client = new Client(new ClientConfig(
            host: 'example.com',
            port: 443,
            useTls: true,
            appKey: 'app-key',
            autoReconnect: false,
            writeStallTimeoutSeconds: 0.01,
            writePollIntervalSeconds: 0.001,
        ));

        $stream = fopen('stallws://socket', 'w+');
        expect($stream)->not->toBeFalse();

        $reflector = new ReflectionClass($client);
        $streamProperty = $reflector->getProperty('stream');
        $streamProperty->setValue($client, $stream);

        $errors = [];
        $closedCount = 0;
        $closeMeta = null;
        $client->on('error', function (mixed $error) use (&$errors): void {
            $errors[] = $error instanceof \RuntimeException ? $error->getMessage() : gettype($error);
        });
        $client->on('close_meta', function (array $meta) use (&$closeMeta): void {
            $closeMeta = $meta;
        });
        $client->on('close', function () use (&$closedCount): void {
            $closedCount++;
        });

        $client->sendText(str_repeat('x', 1024));

        expect($client->isConnected())->toBeFalse();
        expect($closedCount)->toBe(1);
        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('Socket write stalled after');
        expect($closeMeta)->toBeArray();
        expect($closeMeta['source'])->toBe('write_stall');
        expect($closeMeta['error'])->toContain('Socket write stalled after');
    } finally {
        if (in_array('stallws', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('stallws');
        }
    }
});

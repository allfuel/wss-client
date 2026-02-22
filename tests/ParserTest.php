<?php

declare(strict_types=1);

use Fuel\Wss\WebSocket\Frame;
use Fuel\Wss\WebSocket\Parser;

it('decodes masked text frames across payload sizes', function (string $payload): void {
    $frame = Frame::encodeText($payload);
    $parser = new Parser;

    $parser->append($frame);
    $frames = $parser->parse();

    expect($frames)->toHaveCount(1);
    expect($frames[0]['opcode'])->toBe(Frame::OPCODE_TEXT);
    expect($frames[0]['masked'])->toBeTrue();
    expect($frames[0]['payload'])->toBe($payload);
})->with([
    'empty (0 bytes)' => '',
    '1 byte' => 'x',
    '4 bytes' => 'abcd',
    '125 bytes' => str_repeat('a', 125),
    '126 bytes' => str_repeat('b', 126),
    '65535 bytes' => str_repeat('c', 65535),
    '65536 bytes' => str_repeat('d', 65536),
    '1 MB' => str_repeat('e', 1024 * 1024),
]);

it('throws when the buffer exceeds the max size', function (): void {
    $parser = new Parser;
    $parser->append(str_repeat('a', Parser::MAX_BUFFER_SIZE));

    expect(fn () => $parser->append('b'))
        ->toThrow(OverflowException::class, sprintf('WebSocket read buffer exceeded %d bytes', Parser::MAX_BUFFER_SIZE));
});

it('allows normal-sized appends', function (): void {
    $parser = new Parser;

    expect(fn () => $parser->append(str_repeat('a', 1024)))
        ->not->toThrow(OverflowException::class);
});

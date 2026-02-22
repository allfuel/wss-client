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
    'empty' => '',
    'small' => 'hello',
    'medium' => str_repeat('b', 1024),
    'large' => str_repeat('c', 1024 * 1024),
]);

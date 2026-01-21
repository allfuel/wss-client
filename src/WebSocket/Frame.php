<?php

declare(strict_types=1);

namespace Fuel\Wss\WebSocket;

final class Frame
{
    public const OPCODE_CONTINUATION = 0x0;
    public const OPCODE_TEXT = 0x1;
    public const OPCODE_BINARY = 0x2;
    public const OPCODE_CLOSE = 0x8;
    public const OPCODE_PING = 0x9;
    public const OPCODE_PONG = 0xA;

    public static function encodeText(string $payload): string
    {
        return self::encode($payload, self::OPCODE_TEXT, true);
    }

    public static function encodePing(string $payload = ''): string
    {
        return self::encode($payload, self::OPCODE_PING, true);
    }

    public static function encodePong(string $payload = ''): string
    {
        return self::encode($payload, self::OPCODE_PONG, true);
    }

    public static function encodeClose(string $payload = ''): string
    {
        return self::encode($payload, self::OPCODE_CLOSE, true);
    }

    private static function encode(string $payload, int $opcode, bool $masked): string
    {
        $finAndOpcode = 0x80 | ($opcode & 0x0F);
        $length = strlen($payload);
        $maskBit = $masked ? 0x80 : 0x00;

        $header = chr($finAndOpcode);

        if ($length <= 125) {
            $header .= chr($maskBit | $length);
        } elseif ($length <= 65535) {
            $header .= chr($maskBit | 126) . pack('n', $length);
        } else {
            $header .= chr($maskBit | 127) . pack('N2', ($length >> 32) & 0xFFFFFFFF, $length & 0xFFFFFFFF);
        }

        if (!$masked) {
            return $header . $payload;
        }

        $maskingKey = random_bytes(4);
        $maskedPayload = '';

        for ($i = 0; $i < $length; $i++) {
            $maskedPayload .= $payload[$i] ^ $maskingKey[$i % 4];
        }

        return $header . $maskingKey . $maskedPayload;
    }
}

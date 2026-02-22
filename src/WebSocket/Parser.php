<?php

declare(strict_types=1);

namespace Fuel\Wss\WebSocket;

final class Parser
{
    private string $buffer = '';

    public function append(string $data): void
    {
        if ($data === '') {
            return;
        }

        $this->buffer .= $data;
    }

    /**
     * @return array<int, array{fin: bool, opcode: int, payload: string, masked: bool}>
     */
    public function parse(): array
    {
        $frames = [];

        while (true) {
            if (strlen($this->buffer) < 2) {
                break;
            }

            $firstByte = ord($this->buffer[0]);
            $secondByte = ord($this->buffer[1]);

            $fin = (bool) ($firstByte & 0x80);
            $opcode = $firstByte & 0x0F;
            $masked = (bool) ($secondByte & 0x80);
            $length = $secondByte & 0x7F;
            $offset = 2;

            if ($length === 126) {
                if (strlen($this->buffer) < $offset + 2) {
                    break;
                }

                $length = unpack('n', substr($this->buffer, $offset, 2))[1];
                $offset += 2;
            } elseif ($length === 127) {
                if (strlen($this->buffer) < $offset + 8) {
                    break;
                }

                $parts = unpack('N2', substr($this->buffer, $offset, 8));
                $length = ($parts[1] << 32) | $parts[2];
                $offset += 8;
            }

            $mask = '';
            if ($masked) {
                if (strlen($this->buffer) < $offset + 4) {
                    break;
                }

                $mask = substr($this->buffer, $offset, 4);
                $offset += 4;
            }

            if (strlen($this->buffer) < $offset + $length) {
                break;
            }

            $payload = substr($this->buffer, $offset, $length);
            $offset += $length;

            if ($masked) {
                $expandedMask = substr(str_repeat($mask, intdiv($length + 3, 4)), 0, $length);
                $payload = $payload ^ $expandedMask;
            }

            $frames[] = [
                'fin' => $fin,
                'opcode' => $opcode,
                'payload' => $payload,
                'masked' => $masked,
            ];

            $this->buffer = substr($this->buffer, $offset);
        }

        return $frames;
    }
}

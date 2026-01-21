<?php

declare(strict_types=1);

namespace Fuel\Wss;

use Fuel\Wss\Support\EventEmitter;
use Fuel\Wss\WebSocket\Frame;
use Fuel\Wss\WebSocket\Handshake;
use Fuel\Wss\WebSocket\Parser;
use RuntimeException;

final class Client
{
    private ClientConfig $config;
    private EventEmitter $emitter;
    private Parser $parser;

    /** @var resource|null */
    private $stream = null;
    private bool $closing = false;
    private float $lastPingAt = 0.0;

    public function __construct(ClientConfig $config)
    {
        $this->config = $config;
        $this->emitter = new EventEmitter();
        $this->parser = new Parser();
    }

    public function connect(): void
    {
        if ($this->stream !== null) {
            return;
        }

        $scheme = $this->config->useTls ? 'tls' : 'tcp';
        $uri = sprintf('%s://%s:%d', $scheme, $this->config->host, $this->config->port);
        $context = null;

        if ($this->config->useTls) {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => $this->config->tlsVerifyPeer,
                    'verify_peer_name' => $this->config->tlsVerifyPeer,
                    'peer_name' => $this->config->host,
                    'SNI_enabled' => true,
                    'SNI_server_name' => $this->config->host,
                ],
            ]);
        }

        $timeout = $this->config->timeoutSeconds;
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $uri,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($stream === false) {
            throw new RuntimeException(sprintf('Failed to connect: %s (%d)', $errstr, $errno));
        }

        $seconds = (int) floor($timeout);
        $microseconds = (int) (($timeout - $seconds) * 1_000_000);
        stream_set_timeout($stream, $seconds, $microseconds);

        $key = Handshake::generateKey();
        $request = Handshake::buildRequest($this->config, $key);

        if (fwrite($stream, $request) === false) {
            fclose($stream);
            throw new RuntimeException('Failed to write handshake request.');
        }

        $response = '';
        while (!str_contains($response, "\r\n\r\n")) {
            $chunk = fread($stream, 1024);
            if ($chunk === false) {
                fclose($stream);
                throw new RuntimeException('Failed to read handshake response.');
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($stream);
                if (!empty($meta['timed_out'])) {
                    fclose($stream);
                    throw new RuntimeException('Handshake timed out.');
                }
                continue;
            }

            $response .= $chunk;
        }

        Handshake::validateResponse($response, $key);

        stream_set_blocking($stream, false);
        $this->stream = $stream;
        $this->lastPingAt = microtime(true);
        $this->emitter->emit('open');
    }

    /** @return resource|null */
    public function stream()
    {
        return $this->stream;
    }

    public function on(string $event, callable $listener): void
    {
        $this->emitter->on($event, $listener);
    }

    public function sendText(string $payload): void
    {
        $this->send(Frame::encodeText($payload));
    }

    public function tick(?float $now = null): void
    {
        if ($this->stream === null) {
            return;
        }

        $now = $now ?? microtime(true);
        $this->handlePing($now);

        $data = stream_get_contents($this->stream);
        if ($data === false) {
            $this->emitter->emit('error', new RuntimeException('Failed to read from socket.'));
            return;
        }

        if ($data !== '') {
            $this->parser->append($data);
        }

        foreach ($this->parser->parse() as $frame) {
            if (!$frame['fin']) {
                $this->emitter->emit('error', new RuntimeException('Fragmented frames are not supported yet.'));
                continue;
            }

            $this->handleFrame($frame['opcode'], $frame['payload']);
        }

        if (feof($this->stream)) {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }

        if (!$this->closing) {
            $this->closing = true;
            $this->send(Frame::encodeClose());
        }

        fclose($this->stream);
        $this->stream = null;
        $this->emitter->emit('close');
    }

    private function handlePing(float $now): void
    {
        if ($this->closing) {
            return;
        }

        if (($now - $this->lastPingAt) < $this->config->pingIntervalSeconds) {
            return;
        }

        $this->lastPingAt = $now;
        $this->send(Frame::encodePing());
    }

    private function handleFrame(int $opcode, string $payload): void
    {
        switch ($opcode) {
            case Frame::OPCODE_TEXT:
                $this->emitter->emit('message', $payload);
                break;
            case Frame::OPCODE_PING:
                $this->send(Frame::encodePong($payload));
                $this->emitter->emit('ping', $payload);
                break;
            case Frame::OPCODE_PONG:
                $this->emitter->emit('pong', $payload);
                break;
            case Frame::OPCODE_CLOSE:
                if (!$this->closing) {
                    $this->closing = true;
                    $this->send(Frame::encodeClose());
                }
                $this->close();
                break;
            default:
                $this->emitter->emit('error', new RuntimeException('Unhandled opcode received.'));
                break;
        }
    }

    private function send(string $frame): void
    {
        if ($this->stream === null) {
            throw new RuntimeException('Cannot send frame; socket is closed.');
        }

        $written = fwrite($this->stream, $frame);
        if ($written === false) {
            $this->emitter->emit('error', new RuntimeException('Failed to write to socket.'));
        }
    }
}

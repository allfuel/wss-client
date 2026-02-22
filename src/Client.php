<?php

declare(strict_types=1);

namespace Fuel\Wss;

use Fuel\Wss\Pusher\Auth;
use Fuel\Wss\Pusher\PresenceState;
use Fuel\Wss\Support\EventEmitter;
use Fuel\Wss\WebSocket\Frame;
use Fuel\Wss\WebSocket\Handshake;
use Fuel\Wss\WebSocket\Parser;
use InvalidArgumentException;
use RuntimeException;

final class Client
{
    private ClientConfig $config;

    private EventEmitter $emitter;

    private Parser $parser;

    /** @var resource|null */
    private $stream;

    private bool $closing = false;

    private float $lastPingAt = 0.0;

    private ?string $socketId = null;

    private bool $allowReconnect = true;

    private ?float $nextReconnectAt = null;

    private float $reconnectDelay = 0.0;

    /** @var array<string, PresenceState> */
    private array $presenceStates = [];

    public function __construct(ClientConfig $config)
    {
        $this->config = $config;
        $this->emitter = new EventEmitter;
        $this->parser = new Parser;
    }

    public function connect(): void
    {
        if ($this->stream !== null) {
            return;
        }

        $this->closing = false;
        $this->allowReconnect = true;
        $this->socketId = null;
        $this->presenceStates = [];
        $this->parser = new Parser;

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
        while (! str_contains($response, "\r\n\r\n")) {
            $chunk = fread($stream, 1024);
            if ($chunk === false) {
                fclose($stream);
                throw new RuntimeException('Failed to read handshake response.');
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($stream);
                if (! empty($meta['timed_out'])) {
                    fclose($stream);
                    throw new RuntimeException('Handshake timed out.');
                }

                continue;
            }

            $response .= $chunk;
        }

        Handshake::validateResponse($response, $key);

        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd !== false) {
            $remaining = substr($response, $headerEnd + 4);
            if ($remaining !== '') {
                $this->parser->append($remaining);
            }
        }

        stream_set_blocking($stream, false);
        $this->stream = $stream;
        $this->lastPingAt = microtime(true);
        $this->nextReconnectAt = null;
        $this->reconnectDelay = 0.0;
        $this->emitter->emit('open');
    }

    public function isConnected(): bool
    {
        return $this->stream !== null;
    }

    /** @return resource|null */
    public function stream()
    {
        return $this->stream;
    }

    public function socketId(): ?string
    {
        return $this->socketId;
    }

    public function presenceState(string $channel): ?PresenceState
    {
        return $this->presenceStates[$channel] ?? null;
    }

    public function presenceCount(string $channel): ?int
    {
        $state = $this->presenceStates[$channel] ?? null;

        return $state?->count();
    }

    public function on(string $event, callable $listener): void
    {
        $this->emitter->on($event, $listener);
    }

    /**
     * @param  array{auth?: string, channel_data?: array<string, mixed>|string}  $options
     */
    public function subscribe(string $channel, array $options = []): void
    {
        $this->assertConnected('subscribe');

        if ($channel === '') {
            throw new InvalidArgumentException('Channel name must not be empty.');
        }

        $data = ['channel' => $channel];
        $channelData = $options['channel_data'] ?? null;
        $auth = $options['auth'] ?? null;

        if ($auth === null && $this->requiresAuth($channel)) {
            $auth = $this->buildAuth($channel, $channelData);
            if ($channelData !== null) {
                $data['channel_data'] = $this->normalizeChannelData($channelData);
            }
        }

        if ($auth !== null) {
            $data['auth'] = $auth;
        }

        if ($channelData !== null && ! array_key_exists('channel_data', $data)) {
            $data['channel_data'] = $this->normalizeChannelData($channelData);
        }

        $this->sendPusherEvent('pusher:subscribe', $data);
    }

    public function sendText(string $payload): void
    {
        $this->assertConnected('send a message');
        $this->send(Frame::encodeText($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function sendClientEvent(string $channel, string $eventName, array $payload): void
    {
        $this->assertConnected('send a client event');

        if (! str_starts_with($eventName, 'client-')) {
            throw new InvalidArgumentException('Client events must start with "client-".');
        }

        if (! $this->requiresAuth($channel)) {
            throw new InvalidArgumentException('Client events must target private or presence channels.');
        }

        $innerJson = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        unset($payload);

        $json = json_encode([
            'event' => $eventName,
            'channel' => $channel,
            'data' => $innerJson,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        unset($innerJson);
        $this->sendText($json);
    }

    public function tick(?float $now = null): void
    {
        $now = $now ?? microtime(true);

        if ($this->stream === null) {
            $this->attemptReconnect($now);

            return;
        }

        $this->handlePing($now);

        if ($this->stream === null) {
            return;
        }

        $data = stream_get_contents($this->stream);
        if ($data === false) {
            $this->handleDisconnect(new RuntimeException('Failed to read from socket.'), $now);

            return;
        }

        if ($data !== '') {
            $this->parser->append($data);
        }

        foreach ($this->parser->parse() as $frame) {
            if (! $frame['fin']) {
                $this->emitter->emit('error', new RuntimeException('Fragmented frames are not supported yet.'));

                continue;
            }

            $this->handleFrame($frame['opcode'], $frame['payload']);

            if ($this->stream === null) {
                return;
            }
        }

        if ($this->stream !== null && feof($this->stream)) {
            $this->handleDisconnect(null, $now);
        }
    }

    public function close(): void
    {
        $this->allowReconnect = false;
        $this->nextReconnectAt = null;
        $this->reconnectDelay = 0.0;

        if ($this->stream === null) {
            return;
        }

        if (! $this->closing) {
            $this->closing = true;
            $this->send(Frame::encodeClose());
        }

        if ($this->stream === null) {
            return;
        }

        $this->handleDisconnect(null, microtime(true));
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
                $this->handleMessage($payload);
                break;
            case Frame::OPCODE_PING:
                $this->send(Frame::encodePong($payload));
                $this->emitter->emit('ping', $payload);
                break;
            case Frame::OPCODE_PONG:
                $this->emitter->emit('pong', $payload);
                break;
            case Frame::OPCODE_CLOSE:
                if (! $this->closing) {
                    $this->closing = true;
                    $this->send(Frame::encodeClose());
                }
                $this->handleDisconnect(null, microtime(true));
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

        $totalWritten = 0;
        $length = strlen($frame);
        $attempts = 0;
        $maxAttempts = 100;

        while ($totalWritten < $length) {
            $written = @fwrite($this->stream, substr($frame, $totalWritten));
            if ($written === false) {
                $this->handleDisconnect(new RuntimeException('Failed to write to socket.'), microtime(true));

                return;
            }
            if ($written === 0) {
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    $this->handleDisconnect(new RuntimeException('Socket write stalled.'), microtime(true));

                    return;
                }
                usleep(1000);

                continue;
            }
            $attempts = 0;
            $totalWritten += $written;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sendPusherEvent(string $event, array $data): void
    {
        $payload = [
            'event' => $event,
            'data' => $data,
        ];

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->sendText($json);
    }

    private function handleMessage(string $payload): void
    {
        $this->emitter->emit('message', $payload);

        $decoded = json_decode($payload, true);
        if (! is_array($decoded) || ! isset($decoded['event'])) {
            return;
        }

        $event = (string) $decoded['event'];
        $data = $decoded['data'] ?? null;
        $channel = isset($decoded['channel']) ? (string) $decoded['channel'] : null;

        if (is_string($data)) {
            $data = $this->decodeJsonString($data);
        }

        if ($event === 'pusher:ping') {
            $this->sendPusherEvent('pusher:pong', []);
        }

        if ($event === 'pusher:connection_established' && is_array($data)) {
            $socketId = $data['socket_id'] ?? null;
            if (is_string($socketId) && $socketId !== '') {
                $this->socketId = $socketId;
            }
        }

        if ($event === 'pusher_internal:subscription_succeeded' && $channel !== null && is_array($data) && str_starts_with($channel, 'presence-')) {
            $this->presenceStates[$channel] = PresenceState::fromSubscription($data);
        }

        if ($event === 'pusher_internal:member_added' && $channel !== null && is_array($data)) {
            $state = $this->presenceStates[$channel] ?? PresenceState::empty();
            $state->applyMemberAdded($data);
            $this->presenceStates[$channel] = $state;
        }

        if ($event === 'pusher_internal:member_removed' && $channel !== null && is_array($data) && isset($this->presenceStates[$channel])) {
            $this->presenceStates[$channel]->applyMemberRemoved($data);
        }

        $this->emitter->emit($event, $data, $channel, $decoded);
    }

    private function decodeJsonString(string $payload): mixed
    {
        $decoded = json_decode($payload, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        return $payload;
    }

    private function requiresAuth(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');
    }

    private function buildAuth(string $channel, mixed $channelData): string
    {
        if ($this->config->appSecret === null) {
            throw new RuntimeException('App secret is required to subscribe to private channels.');
        }

        if ($this->socketId === null) {
            throw new RuntimeException('Socket ID is required before subscribing to private channels. Wait for the pusher:connection_established event.');
        }

        $encodedChannelData = null;
        if ($channelData !== null) {
            $encodedChannelData = $this->normalizeChannelData($channelData);
        }

        return Auth::sign($this->config->appKey, $this->config->appSecret, $this->socketId, $channel, $encodedChannelData);
    }

    private function normalizeChannelData(mixed $channelData): string
    {
        if (is_string($channelData)) {
            return $channelData;
        }

        if (is_array($channelData)) {
            return json_encode($channelData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        throw new InvalidArgumentException('Channel data must be a string or array.');
    }

    private function assertConnected(string $action): void
    {
        if ($this->stream === null) {
            throw new RuntimeException(sprintf('Cannot %s before connect().', $action));
        }
    }

    private function handleDisconnect(?\Throwable $error, float $now): void
    {
        if ($this->stream === null) {
            if ($error !== null) {
                $this->emitter->emit('error', $error);
            }

            return;
        }

        if ($error !== null) {
            $this->emitter->emit('error', $error);
        }

        fclose($this->stream);
        $this->stream = null;

        $this->closing = false;
        $this->socketId = null;
        $this->presenceStates = [];
        $this->emitter->emit('close');

        if ($this->allowReconnect && $this->config->autoReconnect) {
            $this->scheduleReconnect($now);
        }
    }

    private function scheduleReconnect(float $now): void
    {
        if ($this->reconnectDelay <= 0.0) {
            $this->reconnectDelay = $this->config->reconnectIntervalSeconds;
        } else {
            $this->reconnectDelay = min(
                $this->reconnectDelay * 2,
                $this->config->maxReconnectIntervalSeconds
            );
        }

        $this->nextReconnectAt = $now + $this->reconnectDelay;
        $this->emitter->emit('reconnect_scheduled', $this->reconnectDelay, $this->nextReconnectAt);
    }

    private function attemptReconnect(float $now): void
    {
        if (! $this->allowReconnect || ! $this->config->autoReconnect || $this->nextReconnectAt === null) {
            return;
        }

        if ($now < $this->nextReconnectAt) {
            return;
        }

        $this->emitter->emit('reconnecting', $this->reconnectDelay);

        try {
            $this->connect();
            $this->emitter->emit('reconnected');
        } catch (\Throwable $error) {
            $this->scheduleReconnect($now);
            $this->emitter->emit('reconnect_failed', $error);
        }
    }
}

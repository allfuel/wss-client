<?php

declare(strict_types=1);

namespace Fuel\Wss\Pusher;

final class Auth
{
    public static function sign(
        string $appKey,
        string $appSecret,
        string $socketId,
        string $channel,
        ?string $channelData = null
    ): string {
        $stringToSign = $channelData === null
            ? sprintf('%s:%s', $socketId, $channel)
            : sprintf('%s:%s:%s', $socketId, $channel, $channelData);

        $signature = hash_hmac('sha256', $stringToSign, $appSecret);

        return sprintf('%s:%s', $appKey, $signature);
    }
}

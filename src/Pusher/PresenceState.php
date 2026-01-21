<?php

declare(strict_types=1);

namespace Fuel\Wss\Pusher;

final class PresenceState
{
    /** @var array<string, array{user_id: string, user_info?: array<string, mixed>}> */
    private array $members;

    private int $count;

    /** @param array<string, array{user_id: string, user_info?: array<string, mixed>}> $members */
    private function __construct(array $members, int $count)
    {
        $this->members = $members;
        $this->count = $count;
    }

    /** @param array<string, mixed> $payload */
    public static function fromSubscription(array $payload): self
    {
        $presence = $payload['presence'] ?? null;
        if (! is_array($presence)) {
            return new self([], 0);
        }

        $members = [];
        $hash = $presence['hash'] ?? null;
        if (is_array($hash)) {
            foreach ($hash as $userId => $userInfo) {
                if (! is_string($userId) || $userId === '') {
                    continue;
                }

                $member = ['user_id' => $userId];
                if (is_array($userInfo)) {
                    $member['user_info'] = $userInfo;
                }

                $members[$userId] = $member;
            }
        }

        $ids = $presence['ids'] ?? null;
        if (is_array($ids)) {
            foreach ($ids as $userId) {
                if (! is_string($userId) || $userId === '') {
                    continue;
                }

                $members[$userId] = $members[$userId] ?? ['user_id' => $userId];
            }
        }

        $count = is_int($presence['count'] ?? null) ? $presence['count'] : count($members);
        if ($count < count($members)) {
            $count = count($members);
        }

        return new self($members, $count);
    }

    public static function empty(): self
    {
        return new self([], 0);
    }

    /** @param array<string, mixed> $payload */
    public function applyMemberAdded(array $payload): void
    {
        $userId = $payload['user_id'] ?? null;
        if (! is_string($userId) || $userId === '') {
            return;
        }

        $member = ['user_id' => $userId];
        $userInfo = $payload['user_info'] ?? null;
        if (is_array($userInfo)) {
            $member['user_info'] = $userInfo;
        }

        $isNew = ! isset($this->members[$userId]);
        $this->members[$userId] = $member;
        if ($isNew) {
            $this->count += 1;
        }

        $this->syncCount();
    }

    /** @param array<string, mixed> $payload */
    public function applyMemberRemoved(array $payload): void
    {
        $userId = $payload['user_id'] ?? null;
        if (! is_string($userId) || $userId === '') {
            return;
        }

        $existed = isset($this->members[$userId]);
        unset($this->members[$userId]);

        if ($existed && $this->count > 0) {
            $this->count -= 1;
        } elseif (! $existed && $this->count > 0) {
            $this->count -= 1;
        }

        $this->syncCount();
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return array<string, array{user_id: string, user_info?: array<string, mixed>}> */
    public function members(): array
    {
        return $this->members;
    }

    /** @return list<string> */
    public function memberIds(): array
    {
        return array_keys($this->members);
    }

    /** @return array{user_id: string, user_info?: array<string, mixed>}|null */
    public function member(string $userId): ?array
    {
        return $this->members[$userId] ?? null;
    }

    private function syncCount(): void
    {
        $memberCount = count($this->members);
        if ($this->count < $memberCount) {
            $this->count = $memberCount;
        }
    }
}

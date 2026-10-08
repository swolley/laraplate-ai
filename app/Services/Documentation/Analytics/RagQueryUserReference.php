<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Analytics;

/**
 * The reference that stands for a user in the documentation query log: an HMAC-SHA256 of their id
 * under `APP_KEY`, so the log never holds the id itself.
 *
 * `APP_KEY` is rotated only when it is compromised, through `APP_PREVIOUS_KEYS`. A document written
 * before a rotation carries the hash of the old key, which is why erasure matches every key.
 */
final class RagQueryUserReference
{
    public function for(string $userId): string
    {
        return hash_hmac('sha256', $userId, $this->currentKey());
    }

    /**
     * The references of the user under the current key and under every previous key.
     *
     * @return list<string>
     */
    public function candidatesFor(string $userId): array
    {
        return array_values(array_unique(array_map(
            static fn (string $key): string => hash_hmac('sha256', $userId, $key),
            [$this->currentKey(), ...$this->previousKeys()],
        )));
    }

    private function currentKey(): string
    {
        $key = config('app.key');

        return is_string($key) ? $key : '';
    }

    /**
     * @return list<string>
     */
    private function previousKeys(): array
    {
        $keys = config('app.previous_keys', []);

        return is_array($keys)
            ? array_values(array_filter($keys, static fn (mixed $key): bool => is_string($key) && $key !== ''))
            : [];
    }
}

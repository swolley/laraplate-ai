<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use JsonException;

/**
 * Where an embedding model switch stands: idle, running a phase, or failed. Kept as JSON in the
 * managed setting `features.embeddings.switch`, so the panel and the command read the same progress.
 */
final readonly class EmbeddingSwitchState
{
    public const array STATUSES = ['idle', 'running', 'failed'];

    public const array PHASES = ['preflight', 'embeddings', 'indexes', 'verify', 'activate'];

    public function __construct(
        public string $status = 'idle',
        public ?string $phase = null,
        public ?string $target = null,
        public ?string $previous = null,
        public int $total = 0,
        public int $done = 0,
        public ?string $error = null,
        public ?string $startedAt = null,
    ) {}

    public static function idle(): self
    {
        return new self;
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || $json === '') {
            return self::idle();
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return self::idle();
        }

        if (! is_array($data) || ! in_array($data['status'] ?? null, self::STATUSES, true)) {
            return self::idle();
        }

        $phase = $data['phase'] ?? null;

        return new self(
            status: $data['status'],
            phase: in_array($phase, self::PHASES, true) ? $phase : null,
            target: self::nullableString($data['target'] ?? null),
            previous: self::nullableString($data['previous'] ?? null),
            total: (int) ($data['total'] ?? 0),
            done: (int) ($data['done'] ?? 0),
            error: self::nullableString($data['error'] ?? null),
            startedAt: self::nullableString($data['startedAt'] ?? null),
        );
    }

    public function toJson(): string
    {
        return json_encode([
            'status' => $this->status,
            'phase' => $this->phase,
            'target' => $this->target,
            'previous' => $this->previous,
            'total' => $this->total,
            'done' => $this->done,
            'error' => $this->error,
            'startedAt' => $this->startedAt,
        ], JSON_THROW_ON_ERROR);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}

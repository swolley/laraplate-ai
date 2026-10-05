<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Connection;
use Modules\Core\Search\Contracts\IProfileVectorIndex;
use RuntimeException;

/**
 * Records the calls of the per-profile vector index; `$supported` stands for a pgvector connection.
 */
final class RecordingProfileVectorIndex implements IProfileVectorIndex
{
    /**
     * @var list<array{0: string, 1: string, 2?: int, 3?: string}>
     */
    public array $calls = [];

    public bool $failOnDrop = false;

    public function __construct(public bool $supported) {}

    public function supports(Connection $connection): bool
    {
        return $this->supported;
    }

    public function ensure(Connection $connection, string $modelKey, int $dimensions, string $similarity): void
    {
        $this->calls[] = ['ensure', $modelKey, $dimensions, $similarity];
    }

    public function ensureOnce(Connection $connection, string $modelKey, int $dimensions, string $similarity): void
    {
        $this->calls[] = ['ensureOnce', $modelKey, $dimensions, $similarity];
    }

    public function drop(Connection $connection, string $modelKey): void
    {
        $this->calls[] = ['drop', $modelKey];

        if ($this->failOnDrop) {
            throw new RuntimeException('drop failed');
        }
    }
}

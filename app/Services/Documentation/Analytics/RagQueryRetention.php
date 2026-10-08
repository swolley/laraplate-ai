<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Analytics;

use function ai_config_int;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Response\Elasticsearch;

/**
 * Deletes documentation query log documents: those past the retention window, and those of a user
 * who asked to be erased. Both act on the index even when the log is switched off, since documents
 * written before the switch went off are still there.
 */
final readonly class RagQueryRetention
{
    public function __construct(
        private Client $client,
        private RagQueryUserReference $references,
    ) {}

    /**
     * @return int the documents deleted
     */
    public function prune(): int
    {
        $retention_days = max(1, ai_config_int('ai.features.faq.query_logging.retention_days', 30));

        return $this->deleteWhere([
            'range' => ['logged_at' => ['lt' => now()->subDays($retention_days)->toIso8601ZuluString('millisecond')]],
        ]);
    }

    /**
     * @return int the documents deleted
     */
    public function eraseUser(string $userId): int
    {
        return $this->deleteWhere([
            'terms' => ['user_ref' => $this->references->candidatesFor($userId)],
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function deleteWhere(array $query): int
    {
        $index = RagQueryLogIndex::name();
        $exists = $this->client->indices()->exists(['index' => $index]);

        if (! $exists instanceof Elasticsearch || ! $exists->asBool()) {
            return 0;
        }

        $response = $this->client->deleteByQuery([
            'index' => $index,
            'body' => ['query' => $query],
            'refresh' => true,
        ]);

        if (! $response instanceof Elasticsearch) {
            return 0;
        }

        $deleted = $response->asArray()['deleted'] ?? 0;

        return is_int($deleted) ? $deleted : 0;
    }
}

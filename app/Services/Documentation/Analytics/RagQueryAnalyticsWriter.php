<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Analytics;

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes one query log document. It never fails its caller: the log is telemetry, and a missing
 * index or an unreachable cluster costs a log line, not an answer.
 */
final readonly class RagQueryAnalyticsWriter
{
    public function __construct(private Client $client) {}

    /**
     * @param  array<string, string|int|float|bool>  $document  a {@see RagQueryLog::toDocument()}
     */
    public function write(array $document): void
    {
        try {
            $this->client->index([
                'index' => RagQueryLogIndex::name(),
                'id' => (string) $document['id'],
                'body' => $document,
            ]);
        } catch (Throwable $throwable) {
            // The exception class only: an Elasticsearch error may echo the document, question included.
            Log::warning('rag_query_analytics_write_failed', [
                'exception' => $throwable::class,
                'index' => RagQueryLogIndex::name(),
            ]);
        }
    }
}

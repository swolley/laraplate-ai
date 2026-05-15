<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Http;
use Modules\Core\Search\Contracts\IReranker;

/**
 * HTTP client for a Python cross-encoder microservice.
 *
 * Sends query-document pairs and receives relevance scores in [0, 1].
 *
 * @implements IReranker
 */
final readonly class CrossEncoderService implements IReranker
{
    private string $endpoint;

    public function __construct(?string $endpoint = null)
    {
        $this->endpoint = $endpoint ?? (string) config('ai.providers.cross_encoder.endpoint', 'http://127.0.0.1:8001/score');
    }

    public function score(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $pairs = array_slice($pairs, 0, 64);

        $response = Http::timeout(10)
            ->retry(2, 200)
            ->post($this->endpoint, ['pairs' => $pairs]);

        if (! $response->successful()) {
            return array_fill(0, count($pairs), 0.0);
        }

        $data = $response->json();

        if (! isset($data['scores'])) {
            return array_fill(0, count($pairs), 0.0);
        }

        return array_map(
            static fn (mixed $s): float => max(0.0, min(1.0, (float) $s)),
            $data['scores'],
        );
    }
}

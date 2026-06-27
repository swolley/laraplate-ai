<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Support\Facades\Http;
use Modules\Core\Search\Contracts\IReranker;

use function ai_config_string;

/**
 * HTTP client for a Python cross-encoder microservice.
 *
 * Sends query-document pairs and receives relevance scores in [0, 1].
 */
final readonly class CrossEncoderService implements IReranker
{
    private string $endpoint;

    public function __construct(?string $endpoint = null)
    {
        $this->endpoint = $endpoint ?? ai_config_string('ai.providers.cross_encoder.endpoint', 'http://127.0.0.1:8001/score');
    }

    /**
     * @param  list<array{query: string, text: string}>  $pairs
     * @return list<float>
     */
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

        $scores = $this->parseScores($response->json(), count($pairs));

        return $scores;
    }

    /**
     * @return list<float>
     */
    private function parseScores(mixed $payload, int $expected_count): array
    {
        if (! is_array($payload) || ! isset($payload['scores']) || ! is_array($payload['scores'])) {
            return array_fill(0, $expected_count, 0.0);
        }

        $scores = [];

        foreach ($payload['scores'] as $score) {
            if (! is_int($score) && ! is_float($score)) {
                $scores[] = 0.0;

                continue;
            }

            $scores[] = max(0.0, min(1.0, (float) $score));
        }

        if (count($scores) < $expected_count) {
            return array_pad($scores, $expected_count, 0.0);
        }

        return array_slice($scores, 0, $expected_count);
    }
}

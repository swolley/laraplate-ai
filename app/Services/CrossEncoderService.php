<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use function ai_config_string;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\Core\Search\Contracts\IReranker;
use RuntimeException;

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
     * Scores at most 64 pairs per request. The service either answers with one score per pair or this
     * throws: `EnsembleSearchService::rerankTopK()` catches the failure, logs it and keeps the fused order
     * with `meta['reranked'] = false`, which is how a caller (and the evaluation report) learns that no
     * reranking happened. Zeros made up for an unusable answer would pass for a real, unhelpful rerank.
     *
     * @param  list<array{query: string, text: string}>  $pairs
     *
     * @throws RequestException when the service is unreachable or keeps answering with an error status
     * @throws RuntimeException when the answer is not one numeric score per pair
     *
     * @return list<float>
     */
    public function score(array $pairs): array
    {
        if ($pairs === []) {
            return [];
        }

        $pairs = array_slice($pairs, 0, 64);

        // `retry()` rethrows the last failure once its attempts are spent, so a response that gets here is a success.
        $response = Http::timeout(10)
            ->retry(2, 200)
            ->post($this->endpoint, ['pairs' => $pairs]);

        return $this->parseScores($response->json(), count($pairs));
    }

    /**
     * @throws RuntimeException
     *
     * @return list<float>
     */
    private function parseScores(mixed $payload, int $expected_count): array
    {
        if (! is_array($payload) || ! isset($payload['scores']) || ! is_array($payload['scores'])) {
            throw new RuntimeException('Cross-encoder answer is malformed: it carries no "scores" list.');
        }

        $scores = [];

        foreach (array_values($payload['scores']) as $index => $score) {
            if (! is_int($score) && ! is_float($score)) {
                throw new RuntimeException("Cross-encoder score #{$index} is not a number.");
            }

            $scores[] = max(0.0, min(1.0, (float) $score));
        }

        if ($expected_count !== count($scores)) {
            throw new RuntimeException('Cross-encoder returned ' . count($scores) . " scores for {$expected_count} pairs.");
        }

        return $scores;
    }
}

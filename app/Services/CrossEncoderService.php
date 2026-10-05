<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use function ai_config_nullable_string;
use function ai_config_string;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\Core\Search\Contracts\IRerankerWithModel;
use Modules\Core\Search\DTOs\RerankResult;
use RuntimeException;

/**
 * HTTP client for a Python cross-encoder microservice.
 *
 * Sends query-document pairs to `POST {url}/score` and receives relevance scores in [0, 1]. The URL is a
 * base URL, `ai.providers.cross_encoder.url`, which falls back to the embedding service's: the same service
 * can serve `/score` next to `/embed`, and then shares its API key. There is no built-in address, so a
 * host with neither configured cannot rerank and says so instead of calling a guessed one.
 */
final readonly class CrossEncoderService implements IRerankerWithModel
{
    /**
     * @param  string|null  $url  base URL of the service; the configured one when null
     * @param  string|null  $api_key  bearer token for `$url`; never taken from the configuration for a given URL,
     *                                so a key is not sent to a host it was not configured for
     */
    public function __construct(
        private ?string $url = null,
        private ?string $api_key = null,
    ) {}

    /**
     * Scores at most 64 pairs per request. The service either answers with one score per pair or this
     * throws: `EnsembleSearchService::rerankTopK()` catches the failure, logs it and keeps the fused order
     * with `meta['reranked'] = false`, which is how a caller (and the evaluation report) learns that no
     * reranking happened. Zeros made up for an unusable answer would pass for a real, unhelpful rerank.
     *
     * @param  list<array{query: string, text: string}>  $pairs
     *
     * @throws RequestException when the service is unreachable or keeps answering with an error status
     * @throws RuntimeException when no service URL is configured, or the answer is not one numeric score per pair
     *
     * @return list<float>
     */
    public function score(array $pairs): array
    {
        return $this->scoreWithModel($pairs)->scores;
    }

    /**
     * Same as {@see self::score()}, with the model the service answered with: it names the model that scored
     * the pairs, so a report can say which one it measured. Null when the service does not name one.
     *
     * @param  list<array{query: string, text: string}>  $pairs
     *
     * @throws RequestException when the service is unreachable or keeps answering with an error status
     * @throws RuntimeException when no service URL is configured, or the answer is not one numeric score per pair
     */
    public function scoreWithModel(array $pairs): RerankResult
    {
        if ($pairs === []) {
            return new RerankResult([]);
        }

        [$url, $api_key] = $this->target();
        $pairs = array_slice($pairs, 0, 64);

        $request = Http::timeout(10)->retry(2, 200);

        if ($api_key !== null && $api_key !== '') {
            $request = $request->withToken($api_key);
        }

        // `retry()` rethrows the last failure once its attempts are spent, so a response that gets here is a success.
        $response = $request->post(mb_rtrim($url, '/') . '/score', ['pairs' => $pairs]);

        $payload = $response->json();
        $model = is_array($payload) && is_string($payload['model'] ?? null) && $payload['model'] !== '' ? $payload['model'] : null;

        return new RerankResult($this->parseScores($payload, count($pairs)), $model);
    }

    /**
     * The service URL and the key to send to it. Read when scoring, not when constructed: this class is
     * resolved with the search service, and a missing URL must fail the rerank (which search survives),
     * not the resolution (which it would not).
     *
     * @throws RuntimeException
     *
     * @return array{0: string, 1: string|null}
     */
    private function target(): array
    {
        if ($this->url !== null && $this->url !== '') {
            return [$this->url, $this->api_key];
        }

        $url = ai_config_string('ai.providers.cross_encoder.url');

        if ($url === '') {
            throw new RuntimeException('Cross-encoder service URL is not configured: set CROSS_ENCODER_URL, or SENTENCE_TRANSFORMERS_URL when the embedding service also serves /score.');
        }

        return [$url, ai_config_nullable_string('ai.providers.cross_encoder.api_key')];
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

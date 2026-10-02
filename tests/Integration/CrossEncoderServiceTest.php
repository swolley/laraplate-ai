<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\AI\Services\CrossEncoderService;
use Modules\Core\Search\Contracts\IReranker;

it('implements IReranker contract', function (): void {
    expect(new CrossEncoderService('http://test:8001/score'))->toBeInstanceOf(IReranker::class);
});

it('returns empty array for empty pairs', function (): void {
    $service = new CrossEncoderService('http://test:8001/score');
    expect($service->score([]))->toBe([]);
});

/**
 * A reranker that cannot score has to fail, not to answer: {@see EnsembleSearchService} catches the failure,
 * logs it and reports `reranked = false`, while a made-up answer (zeros) is taken for a successful rerank
 * that happens to change nothing.
 *
 * @return list<array{query: string, text: string}>
 */
function cross_encoder_pairs(int $count): array
{
    return array_map(static fn (int $i): array => ['query' => 'q', 'text' => "text {$i}"], range(1, $count));
}

beforeEach(function (): void {
    Sleep::fake();
});

it('returns the scores of the service, kept within 0 and 1', function (): void {
    Http::fake(['*/score' => Http::response(['scores' => [0.25, 1.7, -0.3]])]);

    expect((new CrossEncoderService('http://test:8001/score'))->score(cross_encoder_pairs(3)))->toBe([0.25, 1.0, 0.0]);
});

it('fails when the service answers with an error', function (): void {
    Http::fake(['*/score' => Http::response('boom', 500)]);

    expect(fn () => (new CrossEncoderService('http://test:8001/score'))->score(cross_encoder_pairs(2)))
        ->toThrow(RequestException::class);
});

it('fails instead of inventing zero scores when the answer is not usable', function (mixed $answer, string $reason): void {
    Http::fake(['*/score' => Http::response($answer)]);

    expect(fn () => (new CrossEncoderService('http://test:8001/score'))->score(cross_encoder_pairs(3)))
        ->toThrow(RuntimeException::class, $reason);
})->with([
    'a payload without scores' => [['oops' => true], 'malformed'],
    'scores that are not a list' => [['scores' => 'high'], 'malformed'],
    'a score that is not a number' => [['scores' => [0.5, 'high', 0.2]], 'not a number'],
    'fewer scores than pairs' => [['scores' => [0.5, 0.2]], 'scores for 3 pairs'],
    'more scores than pairs' => [['scores' => [0.5, 0.2, 0.1, 0.9]], 'scores for 3 pairs'],
]);

it('scores at most 64 pairs in one request', function (): void {
    Http::fake(['*/score' => static fn ($request) => Http::response(['scores' => array_fill(0, count($request['pairs']), 0.5)])]);

    expect((new CrossEncoderService('http://test:8001/score'))->score(cross_encoder_pairs(70)))->toHaveCount(64);
});

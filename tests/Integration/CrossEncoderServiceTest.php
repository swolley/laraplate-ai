<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Modules\AI\Services\CrossEncoderService;
use Modules\Core\Search\Contracts\IReranker;

it('implements IReranker contract', function (): void {
    expect(new CrossEncoderService('http://test:8001'))->toBeInstanceOf(IReranker::class);
});

it('returns empty array for empty pairs', function (): void {
    $service = new CrossEncoderService('http://test:8001');
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

    expect((new CrossEncoderService('http://test:8001'))->score(cross_encoder_pairs(3)))->toBe([0.25, 1.0, 0.0]);
});

it('fails when the service answers with an error', function (): void {
    Http::fake(['*/score' => Http::response('boom', 500)]);

    expect(fn () => (new CrossEncoderService('http://test:8001'))->score(cross_encoder_pairs(2)))
        ->toThrow(RequestException::class);
});

it('fails instead of inventing zero scores when the answer is not usable', function (mixed $answer, string $reason): void {
    Http::fake(['*/score' => Http::response($answer)]);

    expect(fn () => (new CrossEncoderService('http://test:8001'))->score(cross_encoder_pairs(3)))
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

    expect((new CrossEncoderService('http://test:8001'))->score(cross_encoder_pairs(70)))->toHaveCount(64);
});

/**
 * The service is the embedding service (or its own host): the URL is a base URL and `/score` is the client's,
 * with no built-in address. The key is the service's own, so it is sent where the URL came from the same place.
 */
it('posts to /score under the configured service url, whatever trailing slash it has', function (string $url): void {
    config(['ai.providers.cross_encoder.url' => $url]);
    Http::fake(['*' => Http::response(['scores' => [0.5]])]);

    (new CrossEncoderService)->score(cross_encoder_pairs(1));

    Http::assertSent(fn ($request): bool => $request->url() === 'http://st:8000/score');
})->with([
    'no slash' => 'http://st:8000',
    'a trailing slash' => 'http://st:8000/',
]);

it('prefers the url given to the constructor over the configured one', function (): void {
    config(['ai.providers.cross_encoder.url' => 'http://configured:8000']);
    Http::fake(['*' => Http::response(['scores' => [0.5]])]);

    (new CrossEncoderService('http://given:9000'))->score(cross_encoder_pairs(1));

    Http::assertSent(fn ($request): bool => $request->url() === 'http://given:9000/score');
});

it('sends the configured api key as a bearer token', function (): void {
    config(['ai.providers.cross_encoder.url' => 'http://st:8000', 'ai.providers.cross_encoder.api_key' => 'secret']);
    Http::fake(['*' => Http::response(['scores' => [0.5]])]);

    (new CrossEncoderService)->score(cross_encoder_pairs(1));

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer secret'));
});

it('sends no authorization header when there is no api key', function (?string $key): void {
    config(['ai.providers.cross_encoder.url' => 'http://st:8000', 'ai.providers.cross_encoder.api_key' => $key]);
    Http::fake(['*' => Http::response(['scores' => [0.5]])]);

    (new CrossEncoderService)->score(cross_encoder_pairs(1));

    Http::assertSent(fn ($request): bool => ! $request->hasHeader('Authorization'));
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('does not send the configured api key to a url given to the constructor', function (): void {
    config(['ai.providers.cross_encoder.api_key' => 'secret']);
    Http::fake(['*' => Http::response(['scores' => [0.5]])]);

    (new CrossEncoderService('http://elsewhere:9000'))->score(cross_encoder_pairs(1));

    Http::assertSent(fn ($request): bool => ! $request->hasHeader('Authorization'));
});

it('sends the api key given to the constructor along with its url', function (): void {
    Http::fake(['*' => Http::response(['scores' => [0.5]])]);

    (new CrossEncoderService('http://given:9000', 'own-key'))->score(cross_encoder_pairs(1));

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer own-key'));
});

it('fails without calling anything when no service url is configured', function (?string $url): void {
    config(['ai.providers.cross_encoder.url' => $url]);
    Http::fake();

    expect(fn () => (new CrossEncoderService)->score(cross_encoder_pairs(1)))
        ->toThrow(RuntimeException::class, 'CROSS_ENCODER_URL');

    Http::assertNothingSent();
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('needs no service url to score nothing', function (): void {
    config(['ai.providers.cross_encoder.url' => null]);

    expect((new CrossEncoderService)->score([]))->toBe([]);
});

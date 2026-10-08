<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Endpoints\Indices;
use Illuminate\Console\Scheduling\Schedule;

/**
 * @return Client&Mockery\MockInterface
 */
function rag_query_retention_client(bool $indexExists = true): Client
{
    config()->set('ai.features.faq.query_logging.index', 'laraplate_rag_queries_test');

    $indices = Mockery::mock(Indices::class);
    $indices->shouldReceive('exists')
        ->with(['index' => 'laraplate_rag_queries_test'])
        ->andReturn(make_elasticsearch_response([], $indexExists ? 200 : 404));

    $client = Mockery::mock(Client::class);
    $client->shouldReceive('indices')->andReturn($indices);
    app()->instance(Client::class, $client);

    return $client;
}

it('deletes the documents older than the retention window', function (): void {
    $this->freezeTime();
    config()->set('ai.features.faq.query_logging.retention_days', 30);
    $client = rag_query_retention_client();
    $client->shouldReceive('deleteByQuery')->once()->with([
        'index' => 'laraplate_rag_queries_test',
        'body' => ['query' => ['range' => ['logged_at' => ['lt' => now()->subDays(30)->toIso8601ZuluString('millisecond')]]]],
        'refresh' => true,
    ])->andReturn(make_elasticsearch_response(['deleted' => 3]));

    $this->artisan('ai:prune-rag-queries')
        ->expectsOutputToContain('Deleted 3')
        ->assertExitCode(0);
});

it('follows the retention setting', function (): void {
    $this->freezeTime();
    config()->set('ai.features.faq.query_logging.retention_days', 7);
    $client = rag_query_retention_client();
    $client->shouldReceive('deleteByQuery')->once()->withArgs(
        static fn (array $params): bool => $params['body']['query']['range']['logged_at']['lt'] === now()->subDays(7)->toIso8601ZuluString('millisecond'),
    )->andReturn(make_elasticsearch_response(['deleted' => 0]));

    $this->artisan('ai:prune-rag-queries')->assertExitCode(0);
});

it('does nothing when the log index was never created', function (): void {
    $client = rag_query_retention_client(indexExists: false);
    $client->shouldNotReceive('deleteByQuery');

    $this->artisan('ai:prune-rag-queries')->assertExitCode(0);
});

it('erases every document of a user, under the current and the previous keys', function (): void {
    config()->set('app.key', 'base64:current-test-key');
    config()->set('app.previous_keys', ['base64:previous-test-key']);
    $client = rag_query_retention_client();
    $client->shouldReceive('deleteByQuery')->once()->with([
        'index' => 'laraplate_rag_queries_test',
        'body' => ['query' => ['terms' => ['user_ref' => [
            hash_hmac('sha256', '42', 'base64:current-test-key'),
            hash_hmac('sha256', '42', 'base64:previous-test-key'),
        ]]]],
        'refresh' => true,
    ])->andReturn(make_elasticsearch_response(['deleted' => 2]));

    $this->artisan('ai:erase-rag-queries', ['user' => '42'])
        ->expectsOutputToContain('Deleted 2')
        ->assertExitCode(0);
});

it('fails when Elasticsearch refuses the deletion', function (): void {
    $client = rag_query_retention_client();
    $client->shouldReceive('deleteByQuery')->andThrow(new RuntimeException('cluster down'));

    $this->artisan('ai:prune-rag-queries')
        ->expectsOutputToContain('cluster down')
        ->assertExitCode(1);
});

it('prunes every day', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(static fn ($event): bool => str_contains((string) $event->command, 'ai:prune-rag-queries'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *');
});

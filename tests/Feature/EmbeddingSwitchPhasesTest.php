<?php

declare(strict_types=1);

use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\EmbeddingSwitchHarness as Harness;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;

beforeEach(function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);
    $this->seed(AIDatabaseSeeder::class);
    $this->engine = Harness::boot();
    $this->records = Harness::corpus($this->engine, 'One', 'Two', 'Three');
    $this->index = (new EmbeddableTestModel)->searchableAs();
});

it('switches between models of equal dimensions: re-embeds everything, keeps the mapping, verifies and activates', function (): void {
    Harness::start(Harness::TARGET);
    Harness::$embeddedWith = [];

    $state = Harness::advanceUntil();

    expect($state->status)->toBe('idle')
        ->and(app(EmbeddingSwitchStore::class)->get()->status)->toBe('idle')
        ->and(Harness::$embeddedWith)->toBe([Harness::TARGET, Harness::TARGET, Harness::TARGET])
        ->and($this->engine->forcedIndexes())->toBe([])
        ->and(Harness::storedModelKeys())->toBe([Harness::TARGET])
        ->and(Harness::rowsOf(Harness::TARGET))->toBe(3)
        ->and(Harness::setting('features.embeddings.active'))->toBe(Harness::TARGET)
        ->and(Harness::setting('search.vector.model'))->toBe(Harness::TARGET)
        ->and(Harness::setting('search.vector.dimensions'))->toBe(384)
        ->and(Harness::setting('search.vector.suspended_reason'))->toBeNull()
        ->and(config('ai.features.embeddings.active'))->toBe(Harness::TARGET)
        ->and(config('core.search.vector.model'))->toBe(Harness::TARGET)
        ->and(config('core.search.vector.suspended_reason'))->toBeNull()
        ->and(Harness::$ragRebuilds)->toBe([['target' => Harness::TARGET, 'dimensions' => 384]])
        ->and($this->engine->vectorQueries)->toHaveCount(1);

    expect($this->engine->documentsOf($this->index))->toHaveCount(3);
});

it('recreates the index with the new dimensions when they differ, and activates them', function (): void {
    Harness::start(Harness::WIDE);

    Harness::advanceUntil('verify');

    expect($this->engine->forcedIndexes())->toBe([[
        'index' => $this->index,
        'force' => true,
        'dimensions' => 768,
        'model_key' => Harness::WIDE,
    ]])
        ->and(config('core.search.vector.dimensions'))->toBe(384);

    foreach ($this->engine->documentsOf($this->index) as $document) {
        expect($document['embeddings'])->toHaveCount(1)
            ->and($document['embeddings'][0]['vector'])->toHaveCount(768);
    }

    $state = Harness::advanceUntil();

    expect($state->status)->toBe('idle')
        ->and(config('core.search.vector.dimensions'))->toBe(768)
        ->and(Harness::setting('search.vector.dimensions'))->toBe(768)
        ->and(Harness::storedModelKeys())->toBe([Harness::WIDE])
        ->and(Harness::$ragRebuilds)->toBe([['target' => Harness::WIDE, 'dimensions' => 768]]);
});

it('fails the verification when a record has no row of the target, leaving the previous model serving nothing but intact', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('verify');
    ModelEmbedding::query()->forModel($this->records[1])->where('model_key', Harness::TARGET)->delete();

    $state = Harness::advanceUntil();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('verify')
        ->and($state->error)->toContain('#' . $this->records[1]->getKey())
        ->and(Harness::setting('features.embeddings.active'))->toBe(Harness::ACTIVE)
        ->and(config('ai.features.embeddings.active'))->toBe(Harness::ACTIVE)
        ->and(Harness::rowsOf(Harness::ACTIVE))->toBe(3)
        ->and(Harness::setting('search.vector.suspended_reason'))->toBe('switching')
        ->and(app(IVectorSearchAvailability::class)->check(new EmbeddableTestModel)->reason)->toBe('suspended');
});

it('fails the verification when the index does not hold one document per searchable record, pointing to --resume', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('verify');
    $this->engine->documents[$this->index]['999'] = ['id' => '999'];

    $state = Harness::advanceUntil();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('verify')
        ->and($state->error)->toContain('4 document(s)')
        ->and($state->error)->toContain('3 searchable record(s)')
        ->and($state->error)->toContain('ai:embeddings:switch --resume')
        ->and($state->error)->not->toContain('scout:import');
});

it('fails the verification when the index mapping reports other dimensions', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('verify');
    $this->engine->dimensions[$this->index] = 512;

    $state = Harness::advanceUntil();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('verify')
        ->and($state->error)->toContain('512');
});

it('fails the verification when the smoke vector query fails', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('verify');
    $this->engine->failVectorQueries = 'knn rejected';

    $state = Harness::advanceUntil();

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('verify')
        ->and($state->error)->toContain('knn rejected');
});

it('dispatches the embedding jobs once and waits while they are queued', function (): void {
    Harness::start(Harness::TARGET);
    Queue::fake();
    $orchestrator = app(EmbeddingSwitchOrchestrator::class);

    $orchestrator->advance();
    $state = $orchestrator->advance();

    expect($state->status)->toBe('running')
        ->and($state->phase)->toBe('embeddings')
        ->and($state->total)->toBe(3)
        ->and($state->done)->toBe(0)
        ->and($state->rounds)->toBe(1);
    Queue::assertPushed(GenerateEmbeddingsJob::class, 3);
    Queue::assertPushed(GenerateEmbeddingsJob::class, static fn (GenerateEmbeddingsJob $job): bool => (fn (): ?string => $this->profile)->call($job) === Harness::TARGET);

    $state = $orchestrator->advance();

    expect($state->phase)->toBe('embeddings')
        ->and($state->rounds)->toBe(1);
    Queue::assertPushed(GenerateEmbeddingsJob::class, 3);
});

it('fails the embeddings phase when a record still has no row of the target after its rounds', function (): void {
    Harness::start(Harness::TARGET);

    $state = Harness::refusing(['Two'], static fn (): EmbeddingSwitchState => Harness::advanceUntil());

    expect($state->status)->toBe('failed')
        ->and($state->phase)->toBe('embeddings')
        ->and($state->error)->toContain('#' . $this->records[1]->getKey())
        ->and(Harness::setting('features.embeddings.active'))->toBe(Harness::ACTIVE);
});

it('re-dispatches the switch job while the switch runs, and stops once it no longer runs', function (): void {
    Queue::fake();
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'preflight', Harness::TARGET, Harness::ACTIVE));

    (new SwitchEmbeddingModelJob)->handle(app(EmbeddingSwitchOrchestrator::class));

    Queue::assertPushed(SwitchEmbeddingModelJob::class, 1);

    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('failed', 'verify', Harness::TARGET, Harness::ACTIVE, error: 'x'));

    (new SwitchEmbeddingModelJob)->handle(app(EmbeddingSwitchOrchestrator::class));

    Queue::assertPushed(SwitchEmbeddingModelJob::class, 1);
});

it('does not advance while another run of the switch job holds its lock', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'preflight', Harness::TARGET, Harness::ACTIVE));
    $lock = Cache::lock(SwitchEmbeddingModelJob::overlapLockKey(), 60);
    $lock->get();

    SwitchEmbeddingModelJob::dispatch();

    expect(app(EmbeddingSwitchStore::class)->get()->phase)->toBe('preflight');

    $lock->release();
    SwitchEmbeddingModelJob::dispatch();

    expect(app(EmbeddingSwitchStore::class)->get()->phase)->not->toBe('preflight');
});

it('empties an index of equal dimensions before writing it, so a document of a record no longer searchable is gone', function (): void {
    $this->engine->documents[$this->index]['999'] = ['id' => '999', 'embeddings' => [['vector' => [0.2]]]];
    Harness::start(Harness::TARGET);

    $state = Harness::advanceUntil();

    expect($state->status)->toBe('idle')
        ->and($this->engine->forcedIndexes())->toBe([])
        ->and($this->engine->documentsOf($this->index))->not->toHaveKey('999')
        ->and($this->engine->documentsOf($this->index))->toHaveCount(3);
});

it('rewrites at the start of the verification the documents written meanwhile with the previous model\'s vectors', function (): void {
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('verify');
    $record = $this->records[0];
    $key = (string) $record->getKey();

    // What the normal pipeline writes for a record edited now: the serving (previous) model's rows.
    $this->engine->update(collect([$record->fresh()]));
    expect($this->engine->documentsOf($this->index)[$key]['embeddings'][0]['vector'][0])->toBe(Harness::vectorValue(Harness::ACTIVE));

    app(EmbeddingSwitchOrchestrator::class)->advance();

    $vectors = collect($this->engine->documentsOf($this->index)[$key]['embeddings'])->pluck('vector');

    expect(app(EmbeddingSwitchStore::class)->get()->phase)->toBe('activate')
        ->and($vectors)->toHaveCount(1)
        ->and($vectors->flatten()->unique()->values()->all())->toBe([Harness::vectorValue(Harness::TARGET)]);
});

it('does not count a target row written before the record was edited, and embeds the record again', function (): void {
    foreach ($this->records as $record) {
        new GenerateEmbeddingsJob($record, null, Harness::TARGET)->handle(app(IEmbeddingService::class));
    }

    $edited = $this->records[1];
    $edited->title = 'Two, edited while the switch was failed';
    $edited->saveQuietly();
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'embeddings', Harness::TARGET, Harness::ACTIVE));
    Harness::$embeddedWith = [];

    $state = app(EmbeddingSwitchOrchestrator::class)->advance();

    expect($state->phase)->toBe('embeddings')
        ->and($state->done)->toBe(2)
        ->and($state->total)->toBe(3)
        ->and(Harness::$embeddedWith)->toBe([Harness::TARGET])
        ->and(ModelEmbedding::query()->forModel($edited)->where('model_key', Harness::TARGET)->value('content_hash'))
        ->toBe(ModelEmbeddingSynchronizer::contentHash('Two, edited while the switch was failed'));

    expect(app(EmbeddingSwitchOrchestrator::class)->advance()->phase)->toBe('indexes');
});

it('fails the switch when its job times out, naming the timeout', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE));

    (new SwitchEmbeddingModelJob)->failed(new TimeoutExceededException('SwitchEmbeddingModelJob has timed out.'));

    $state = app(EmbeddingSwitchStore::class)->get();

    expect((new SwitchEmbeddingModelJob)->failOnTimeout)->toBeTrue()
        ->and($state->status)->toBe('failed')
        ->and($state->phase)->toBe('indexes')
        ->and($state->error)->toContain('timed out after 900 s');
});

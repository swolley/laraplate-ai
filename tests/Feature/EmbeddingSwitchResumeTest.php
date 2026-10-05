<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\AI\Tests\Stubs\EmbeddingSwitchHarness as Harness;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Models\Setting;

beforeEach(function (): void {
    Event::fake([ModelPreProcessingCompleted::class]);
    $this->seed(AIDatabaseSeeder::class);
    $this->engine = Harness::boot();
    $this->records = Harness::corpus($this->engine, 'One', 'Two', 'Three');
    $this->index = (new EmbeddableTestModel)->searchableAs();
});

/**
 * Drives a switch to `$target` until its verification fails on a record with no row of the target.
 */
function resume_fail_verification(string $target, EmbeddableTestModel $record): void
{
    Harness::start($target);
    Harness::advanceUntil('verify');
    ModelEmbedding::query()->forModel($record)->where('model_key', $target)->delete();
    Harness::advanceUntil();
}

function resume_store(): EmbeddingSwitchStore
{
    return app(EmbeddingSwitchStore::class);
}

it('resumes a switch failed in its verification once the cause is fixed, and completes it', function (): void {
    resume_fail_verification(Harness::TARGET, $this->records[1]);
    expect(resume_store()->get()->status)->toBe('failed');

    new GenerateEmbeddingsJob($this->records[1], null, Harness::TARGET)->handle(app(IEmbeddingService::class));

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.active'))->toBe(Harness::TARGET)
        ->and(Harness::storedModelKeys())->toBe([Harness::TARGET])
        ->and(Harness::setting('search.vector.suspended_reason'))->toBeNull();
});

it('leaves an activation whose settings write throws at the activate phase, and resumes it', function (): void {
    $thrown = false;
    Setting::saving(static function (Setting $setting) use (&$thrown): void {
        if ($setting->name === 'search.vector.dimensions' && ! $thrown) {
            $thrown = true;

            throw new RuntimeException('settings store unavailable');
        }
    });
    Harness::start(Harness::TARGET);
    Harness::advanceUntil('activate');

    $failure = null;

    try {
        app(EmbeddingSwitchOrchestrator::class)->advance();
    } catch (RuntimeException $exception) {
        $failure = $exception;
    }

    $state = resume_store()->get();

    expect($failure?->getMessage())->toBe('settings store unavailable')
        ->and($state->status)->toBe('running')
        ->and($state->phase)->toBe('activate')
        ->and(Harness::setting('features.embeddings.active'))->toBe(Harness::ACTIVE)
        ->and(config('ai.features.embeddings.active'))->toBe(Harness::ACTIVE)
        ->and(config('core.search.vector.model'))->toBe(Harness::ACTIVE)
        ->and(Harness::rowsOf(Harness::ACTIVE))->toBe(3);

    (new SwitchEmbeddingModelJob)->failed($failure);
    expect(resume_store()->get()->status)->toBe('failed');

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.active'))->toBe(Harness::TARGET)
        ->and(Harness::setting('search.vector.model'))->toBe(Harness::TARGET)
        ->and(Harness::storedModelKeys())->toBe([Harness::TARGET]);
});

it('resumes a running switch that stopped making progress', function (): void {
    Queue::fake();
    resume_store()->put(new EmbeddingSwitchState('running', 'indexes', Harness::TARGET, Harness::ACTIVE, updatedAt: now()->subHours(2)->toIso8601String()));

    $this->artisan('ai:embeddings:switch', ['--resume' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('running')
        ->and(resume_store()->get()->phase)->toBe('indexes');
    Queue::assertPushed(SwitchEmbeddingModelJob::class, 1);
});

it('refuses to resume or abandon a switch that is running normally', function (string $option): void {
    Queue::fake();
    $state = new EmbeddingSwitchState('running', 'embeddings', Harness::TARGET, Harness::ACTIVE, updatedAt: now()->toIso8601String());
    resume_store()->put($state);

    $this->artisan('ai:embeddings:switch', [$option => true])
        ->expectsOutputToContain('running')
        ->assertFailed();

    expect(resume_store()->get())->toEqual($state);
    Queue::assertNothingPushed();
})->with(['--resume', '--abandon']);

it('refuses to resume a refused start and points to --abandon', function (): void {
    Queue::fake();
    resume_store()->put(new EmbeddingSwitchState('failed', 'preflight', Harness::TARGET, Harness::ACTIVE, error: 'probe failed'));

    $this->artisan('ai:embeddings:switch', ['--resume' => true])
        ->expectsOutputToContain('--abandon')
        ->assertFailed();

    expect(resume_store()->get()->status)->toBe('failed');
    Queue::assertNothingPushed();
});

it('refuses --resume and --abandon together, and either with no switch to act on', function (array $options, string $message): void {
    Queue::fake();

    $this->artisan('ai:embeddings:switch', $options)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(resume_store()->get()->status)->toBe('idle');
    Queue::assertNothingPushed();
})->with([
    'both' => [['--resume' => true, '--abandon' => true], 'not both'],
    'resume idle' => [['--resume' => true], 'No embedding model switch'],
    'abandon idle' => [['--abandon' => true], 'No embedding model switch'],
    'nothing' => [[], 'profile'],
]);

it('abandons a refused start: idle again and the chosen model back to the active one, nothing re-embedded', function (): void {
    Queue::fake();
    Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.model')->toBase()->update(['value' => json_encode(Harness::TARGET)]);
    resume_store()->put(new EmbeddingSwitchState('failed', 'preflight', Harness::TARGET, Harness::ACTIVE, error: 'probe failed'));

    $this->artisan('ai:embeddings:switch', ['--abandon' => true])->assertSuccessful();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.model'))->toBe(Harness::ACTIVE)
        ->and(config('ai.features.embeddings.model'))->toBe(Harness::ACTIVE)
        ->and(Harness::storedModelKeys())->toBe([Harness::ACTIVE]);
    Queue::assertNothingPushed();
    expect($this->engine->createdIndexes)->toBe([]);
});

it('abandons a switch failed after it changed the index: rebuilds for the previous model and activates it again', function (): void {
    Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.model')->toBase()->update(['value' => json_encode(Harness::WIDE)]);
    resume_fail_verification(Harness::WIDE, $this->records[1]);
    expect(resume_store()->get()->status)->toBe('failed')
        ->and(Harness::rowsOf(Harness::WIDE))->toBe(2);

    $this->artisan('ai:embeddings:switch', ['--abandon' => true])->assertSuccessful();

    $forced = $this->engine->forcedIndexes();

    expect(resume_store()->get()->status)->toBe('idle')
        ->and(Harness::setting('features.embeddings.active'))->toBe(Harness::ACTIVE)
        ->and(Harness::setting('features.embeddings.model'))->toBe(Harness::ACTIVE)
        ->and(Harness::setting('search.vector.dimensions'))->toBe(384)
        ->and(Harness::setting('search.vector.suspended_reason'))->toBeNull()
        ->and($forced)->toHaveCount(2)
        ->and(end($forced)['dimensions'])->toBe(384)
        ->and(end($forced)['model_key'])->toBe(Harness::ACTIVE)
        ->and(Harness::storedModelKeys())->toBe([Harness::ACTIVE])
        ->and(Harness::rowsOf(Harness::ACTIVE))->toBe(3);
});

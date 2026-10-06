<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Listeners\HandleModelTranslationListener;
use Modules\AI\Tests\Stubs\Translation\CompoundKeySearchableTranslatableTestModel;
use Modules\AI\Tests\Stubs\Translation\SearchableTranslatableTestModel;
use Modules\AI\Tests\Unit\TranslatableModelStub;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\TranslatedModelSaved;

beforeEach(function (): void {
    Config::set('ai.features.translation.enabled', true);
    Queue::fake();
});

it('does nothing when translation feature disabled', function (): void {
    Config::set('ai.features.translation.enabled', false);

    $model = new TranslatableModelStub;
    $model->id = 1;

    $event = new TranslatedModelSaved($model, [], false);
    $listener = app(HandleModelTranslationListener::class);
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('does nothing when model does not use HasTranslations', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('getKey')->andReturn(1);

    $event = new TranslatedModelSaved($model, [], false);
    $listener = app(HandleModelTranslationListener::class);
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('dispatches TranslateModelJob', function (): void {
    $model = new TranslatableModelStub;
    $model->id = 1;

    $event = new TranslatedModelSaved($model, ['it'], false);
    $listener = app(HandleModelTranslationListener::class);
    $listener->handle($event);

    Queue::assertPushed(TranslateModelJob::class);
});

it('registers translation for indexing when model is Searchable', function (): void {
    $model = new SearchableTranslatableTestModel;
    $model->id = 1;

    $indexingEvent = new ModelRequiresIndexing($model, false);
    $cacheKey = "model_indexing:{$model->getTable()}:{$model->getKey()}";
    Cache::put($cacheKey, $indexingEvent, now()->addMinutes(10));

    $event = new TranslatedModelSaved($model, ['it'], false);
    $listener = app(HandleModelTranslationListener::class);
    $listener->handle($event);

    $cached = Cache::get($cacheKey);
    expect($cached)->toBeInstanceOf(ModelRequiresIndexing::class)
        ->and($cached->required_pre_processing)->toContain('translation');
});

it('skips indexing cache registration when searchable model key is not scalar', function (): void {
    Cache::spy();

    $model = new CompoundKeySearchableTranslatableTestModel;

    $event = new TranslatedModelSaved($model, ['it'], false);
    $listener = app(HandleModelTranslationListener::class);
    $listener->handle($event);

    Queue::assertPushed(TranslateModelJob::class);
    Cache::shouldNotHaveReceived('get');
    Cache::shouldNotHaveReceived('put');
});

it('does nothing when the translation module allowlist excludes the model module', function (): void {
    // TranslatableModelStub is Modules\AI\..., so a CMS-only allowlist excludes it.
    Config::set('ai.features.translation.modules', ['cms']);

    $model = new TranslatableModelStub;
    $model->id = 1;

    $event = new TranslatedModelSaved($model, ['it'], false);
    app(HandleModelTranslationListener::class)->handle($event);

    Queue::assertNothingPushed();
});

it('dispatches when the translation module allowlist includes the model module', function (): void {
    Config::set('ai.features.translation.modules', ['ai']);

    $model = new TranslatableModelStub;
    $model->id = 1;

    $event = new TranslatedModelSaved($model, ['it'], false);
    app(HandleModelTranslationListener::class)->handle($event);

    Queue::assertPushed(TranslateModelJob::class);
});

<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Services\Translation\TranslationService;
use Stubs\TranslateModelJobStub;

beforeEach(function (): void {
    Config::set('app.locale', 'en');
});

it('has correct properties', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('fresh')->andReturn($model);

    $job = new TranslateModelJob($model, [], false);

    expect($job->tries)->toBe(3)
        ->and($job->deleteWhenMissingModels)->toBeTrue()
        ->and($job->backoff)->toBe([30, 60, 120])
        ->and($job->timeout)->toBe(300);
});

it('middleware returns RateLimited', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');

    $job = new TranslateModelJob($model, [], false);
    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(Illuminate\Queue\Middleware\RateLimited::class);
});

it('returns early when model not found', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('fresh')->andReturn(null);

    $translationService = Mockery::mock(TranslationService::class);
    $translationService->shouldNotReceive('translate');

    $job = new TranslateModelJob($model, [], false);
    $job->handle($translationService);
});

it('returns early when default translation not found', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('fresh')->andReturn($model);
    $model->shouldReceive('getTranslation')->with('en')->andReturn(null);

    $translationService = Mockery::mock(TranslationService::class);
    $translationService->shouldNotReceive('translate');

    $job = new TranslateModelJob($model, ['it'], false);
    $job->handle($translationService);
});

it('translates model for each locale', function (): void {
    $defaultTranslation = Mockery::mock(Model::class)->makePartial();
    $defaultTranslation->title = 'Hello';
    $defaultTranslation->content = 'World';

    $model = new TranslateModelJobStub;
    $model->defaultTranslation = $defaultTranslation;
    $model->hasTranslationResult = false;
    TranslateModelJobStub::$translatableFields = ['title', 'content'];

    $translationService = Mockery::mock(TranslationService::class);
    $translationService->shouldReceive('translate')
        ->with('Hello', 'en', 'it')
        ->andReturn('Ciao');
    $translationService->shouldReceive('translate')
        ->with('World', 'en', 'it')
        ->andReturn('Mondo');

    $job = new TranslateModelJob($model, ['it'], true);
    $job->handle($translationService);

    expect($model->setTranslationCalls)->toHaveCount(1)
        ->and($model->setTranslationCalls[0]['locale'])->toBe('it')
        ->and($model->setTranslationCalls[0]['data'])->toBe(['title' => 'Ciao', 'content' => 'Mondo']);
});

it('skips existing translations when force is false', function (): void {
    $defaultTranslation = Mockery::mock(Model::class)->makePartial();
    $defaultTranslation->title = 'Hello';

    $model = new TranslateModelJobStub;
    $model->defaultTranslation = $defaultTranslation;
    $model->hasTranslationResult = true;

    $translationService = Mockery::mock(TranslationService::class);
    $translationService->shouldNotReceive('translate');

    $job = new TranslateModelJob($model, ['it'], false);
    $job->handle($translationService);

    expect($model->setTranslationCalls)->toBeEmpty();
});

it('translates components recursively', function (): void {
    $defaultTranslation = Mockery::mock(Model::class)->makePartial();
    $defaultTranslation->components = ['block' => ['title' => 'Block Title']];

    $model = new TranslateModelJobStub;
    $model->defaultTranslation = $defaultTranslation;
    $model->hasTranslationResult = false;
    TranslateModelJobStub::$translatableFields = ['components'];

    $translationService = Mockery::mock(TranslationService::class);
    $translationService->shouldReceive('translate')
        ->with('Block Title', 'en', 'it')
        ->andReturn('Titolo Blocco');

    $job = new TranslateModelJob($model, ['it'], true);
    $job->handle($translationService);

    expect($model->setTranslationCalls)->toHaveCount(1)
        ->and($model->setTranslationCalls[0]['locale'])->toBe('it')
        ->and($model->setTranslationCalls[0]['data'])->toBe(['components' => ['block' => ['title' => 'Titolo Blocco']]]);
});

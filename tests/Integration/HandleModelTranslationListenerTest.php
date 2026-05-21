<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Listeners\HandleModelTranslationListener;
use Modules\AI\Tests\Unit\TranslatableModelStub;
use Modules\AI\Tests\Unit\TranslatableModelStubTranslation;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Helpers\HasTranslations;

beforeEach(function (): void {
    Config::set('ai.features.translation.enabled', true);
    Queue::fake();
});

it('does nothing when translation feature disabled', function (): void {
    Config::set('ai.features.translation.enabled', false);

    $model = new TranslatableModelStub;
    $model->id = 1;

    $event = new TranslatedModelSaved($model, [], false);
    $listener = new HandleModelTranslationListener();
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('does nothing when model does not use HasTranslations', function (): void {
    $model = Mockery::mock(Model::class)->makePartial();
    $model->id = 1;
    $model->shouldReceive('getTable')->andReturn('test');
    $model->shouldReceive('getKey')->andReturn(1);

    $event = new TranslatedModelSaved($model, [], false);
    $listener = new HandleModelTranslationListener();
    $listener->handle($event);

    Queue::assertNothingPushed();
});

it('dispatches TranslateModelJob', function (): void {
    $model = new TranslatableModelStub;
    $model->id = 1;

    $event = new TranslatedModelSaved($model, ['it'], false);
    $listener = new HandleModelTranslationListener();
    $listener->handle($event);

    Queue::assertPushed(TranslateModelJob::class);
});

it('registers translation for indexing when model is Searchable', function (): void {
    $model = new class extends Model
    {
        use HasTranslations;
        use Illuminate\Database\Eloquent\Factories\HasFactory;
        use Modules\Core\Search\Traits\Searchable;

        protected bool $auto_translate_enabled = true;

        public function getTable(): string
        {
            return 'test_searchable_translatable';
        }

        public function vectorSearchEnabled(): bool
        {
            return true;
        }

        protected static function getTranslationModelClass(): string
        {
            return TranslatableModelStubTranslation::class;
        }
    };
    $model->id = 1;

    $indexingEvent = new ModelRequiresIndexing($model, false);
    $cacheKey = "model_indexing:{$model->getTable()}:{$model->getKey()}";
    Cache::put($cacheKey, $indexingEvent, now()->addMinutes(10));

    $event = new TranslatedModelSaved($model, ['it'], false);
    $listener = new HandleModelTranslationListener();
    $listener->handle($event);

    $cached = Cache::get($cacheKey);
    expect($cached)->toBeInstanceOf(ModelRequiresIndexing::class)
        ->and($cached->required_pre_processing)->toContain('translation');
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Listeners\HandleTranslationReembeddingListener;
use Modules\AI\Tests\Unit\SearchableModelStub;
use Modules\Core\Events\TranslationRequiresReembedding;

beforeEach(function (): void {
    Queue::fake();
});

/**
 * Pull the private constructor-promoted $model/$locale out of a dispatched
 * GenerateEmbeddingsJob (it has no public accessors) so the test can assert
 * what it was scoped to.
 *
 * @return array{0: \Illuminate\Database\Eloquent\Model, 1: ?string}
 */
function translationReembedJobArgs(GenerateEmbeddingsJob $job): array
{
    $reflection = new ReflectionClass($job);

    $model_property = $reflection->getProperty('model');
    $model_property->setAccessible(true);

    $locale_property = $reflection->getProperty('locale');
    $locale_property->setAccessible(true);

    return [$model_property->getValue($job), $locale_property->getValue($job)];
}

it('dispatches GenerateEmbeddingsJob scoped to the event model and locale', function (): void {
    $model = new SearchableModelStub;
    $model->id = 1;

    $event = new TranslationRequiresReembedding($model, 'it');
    $listener = new HandleTranslationReembeddingListener();
    $listener->handle($event);

    Queue::assertPushed(GenerateEmbeddingsJob::class, 1);
    Queue::assertPushed(GenerateEmbeddingsJob::class, function (GenerateEmbeddingsJob $job) use ($model): bool {
        [$job_model, $job_locale] = translationReembedJobArgs($job);

        return $job_model->is($model) && $job_locale === 'it';
    });
});

it('dispatches a separate GenerateEmbeddingsJob per event, each scoped to its own locale', function (): void {
    $model = new SearchableModelStub;
    $model->id = 1;

    $listener = new HandleTranslationReembeddingListener();
    $listener->handle(new TranslationRequiresReembedding($model, 'it'));
    $listener->handle(new TranslationRequiresReembedding($model, 'en'));

    Queue::assertPushed(GenerateEmbeddingsJob::class, 2);
    Queue::assertPushed(GenerateEmbeddingsJob::class, fn (GenerateEmbeddingsJob $job): bool => translationReembedJobArgs($job)[1] === 'it');
    Queue::assertPushed(GenerateEmbeddingsJob::class, fn (GenerateEmbeddingsJob $job): bool => translationReembedJobArgs($job)[1] === 'en');
});

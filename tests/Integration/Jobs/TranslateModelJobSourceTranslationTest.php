<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Tests\Stubs\OriginalTranslationTestModel;
use Modules\AI\Tests\Stubs\TranslatableTestModelTranslation;

function resolveTranslationSource(Model $model, string $default_locale): ?Model
{
    $method = new ReflectionMethod(TranslateModelJob::class, 'resolveSourceTranslation');

    return $method->invoke(new TranslateModelJob($model), $model, $default_locale);
}

it('translates from the original translation when the model knows it', function (): void {
    $original = new TranslatableTestModelTranslation(['locale' => 'it', 'title' => 'Primo testo']);
    $default = new TranslatableTestModelTranslation(['locale' => 'en', 'title' => 'Later English']);

    $source = resolveTranslationSource(new OriginalTranslationTestModel($original, $default), 'en');

    expect($source)->toBe($original);
});

it('falls back to the default-locale translation when there is no original', function (): void {
    $default = new TranslatableTestModelTranslation(['locale' => 'en', 'title' => 'Later English']);

    $source = resolveTranslationSource(new OriginalTranslationTestModel(null, $default), 'en');

    expect($source)->toBe($default);
});

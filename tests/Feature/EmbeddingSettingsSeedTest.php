<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\Core\Models\Setting;

function embeddingSetting(string $name): Setting
{
    return Setting::query()->withoutGlobalScopes()->where('name', $name)->sole();
}

it('seeds the model, active and switch settings with the right managed flags', function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');
    $this->seed(AIDatabaseSeeder::class);

    $model = embeddingSetting('features.embeddings.model');
    $active = embeddingSetting('features.embeddings.active');
    $switch = embeddingSetting('features.embeddings.switch');

    expect($model->group_name)->toBe('ai')
        ->and($model->managed)->toBeFalse()
        ->and($model->choices)->toBe(app(EmbeddingModelRegistry::class)->keys())
        ->and($model->value)->toBe('sentence_transformers:intfloat/multilingual-e5-small')
        ->and($active->managed)->toBeTrue()
        ->and($active->value)->toBe('sentence_transformers:intfloat/multilingual-e5-small')
        ->and($switch->managed)->toBeTrue()
        ->and(json_decode((string) $switch->value, true)['status'])->toBe('idle');
});

it('leaves out the profiles whose provider is not configured', function (): void {
    config()->set('ai.providers.sentence_transformers.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'sentence_transformers:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);
    config()->set('ai.features.embeddings.active', null);

    $definitions = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name');

    expect($definitions['features.embeddings.model']['choices'])->toBe(['voyageai:voyage-3-lite'])
        ->and($definitions['features.embeddings.model']['value'])->toBe('voyageai:voyage-3-lite')
        ->and($definitions['features.embeddings.active']['value'])->toBe('voyageai:voyage-3-lite');
});

it('always keeps the active profile among the choices', function (): void {
    config()->set('ai.providers.sentence_transformers.url', '');
    config()->set('ai.providers.voyageai.api_key', 'k');
    config()->set('ai.features.embeddings.models', [
        'sentence_transformers:intfloat/multilingual-e5-small' => ['dimensions' => 384],
        'voyageai:voyage-3-lite' => ['dimensions' => 512],
    ]);
    config()->set('ai.features.embeddings.active', 'sentence_transformers:intfloat/multilingual-e5-small');

    $choices = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name')['features.embeddings.model']['choices'];

    expect($choices)->toContain('sentence_transformers:intfloat/multilingual-e5-small')
        ->and($choices)->toContain('voyageai:voyage-3-lite');
});

it('does not overwrite a managed value on a re-seed', function (): void {
    config()->set('ai.providers.sentence_transformers.url', 'http://localhost:8000');
    $this->seed(AIDatabaseSeeder::class);

    Setting::writeManaged('features.embeddings.active', 'sentence_transformers:all-MiniLM-L6-v2');
    $this->seed(AIDatabaseSeeder::class);

    expect(embeddingSetting('features.embeddings.active')->value)->toBe('sentence_transformers:all-MiniLM-L6-v2');
});

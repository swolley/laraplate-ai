<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\AI\Services\Translation\TranslationService;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Helpers\HasTranslations;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Traits\Searchable;

final class TranslateModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public $deleteWhenMissingModels = true;

    /**
     * @var array|int[]
     */
    public array $backoff = [30, 60, 120];

    public int $timeout = 300;

    /**
     * @param  Model&HasTranslations  $model
     * @param  array<string>  $locales
     */
    public function __construct(
        private readonly Model $model,
        private readonly array $locales = [],
        private readonly bool $force = false,
    ) {
        $this->onQueue('translations');
    }

    public function middleware(): array
    {
        return [
            new RateLimited('translations'),
        ];
    }

    public function handle(TranslationService $translation_service): void
    {
        /** @var Model&HasTranslations $model */
        $model = $this->model->fresh();

        if (! $model) {
            Log::warning('Model not found for translation', [
                'model_class' => $this->model::class,
                'model_id' => $this->model->id,
            ]);

            return;
        }

        $default_locale = config('app.locale');
        $default_translation = $model->getTranslation($default_locale);

        if (! $default_translation) {
            Log::warning('Default translation not found', [
                'model_class' => $model::class,
                'model_id' => $model->id,
            ]);

            return;
        }

        $locales_to_translate = $this->locales === [] ? LocaleContext::getAvailable() : $this->locales;
        $locales_to_translate = array_filter($locales_to_translate, fn ($locale): bool => $locale !== $default_locale);

        foreach ($locales_to_translate as $locale) {
            // Skip if translation exists and force is false
            if (! $this->force && $model->hasTranslation($locale)) {
                continue;
            }

            try {
                $this->translateModel($model, $default_translation, $locale, $translation_service);
            } catch (Exception $e) {
                Log::error('Translation failed for model', [
                    'model_class' => $model::class,
                    'model_id' => $model->id,
                    'locale' => $locale,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If the model is searchable, emit event to synchronize with indexing
        // The indexed document contains translations, so they must be ready
        if (class_uses_trait($model, Searchable::class)) {
            event(new ModelPreProcessingCompleted($model, 'translation'));
        }
    }

    /**
     * @param  Model&HasTranslations  $model
     */
    private function translateModel(
        Model $model,
        Model $default_translation,
        string $locale,
        TranslationService $translation_service,
    ): void {
        $default_locale = config('app.locale');
        $translatable_fields = $model->getTranslatableFields();
        $translated_data = [];

        foreach ($translatable_fields as $field) {
            $value = $default_translation->{$field};

            if (empty($value)) {
                continue;
            }

            if ($field === 'components' && is_array($value)) {
                // Translate components JSON recursively
                $translated_data[$field] = $this->translateComponents($value, $default_locale, $locale, $translation_service);
            } elseif (is_string($value)) {
                // Translate string field
                $translated_data[$field] = $translation_service->translate($value, $default_locale, $locale);
            } else {
                // Keep non-translatable values as-is
                $translated_data[$field] = $value;
            }
        }

        $model->setTranslation($locale, $translated_data);
    }

    /**
     * Translate components JSON recursively.
     *
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     */
    private function translateComponents(
        array $components,
        string $from_locale,
        string $to_locale,
        TranslationService $translation_service,
    ): array {
        $translated = [];

        foreach ($components as $key => $value) {
            if (is_string($value) && ($value !== '' && $value !== '0')) {
                $translated[$key] = $translation_service->translate($value, $from_locale, $to_locale);
            } elseif (is_array($value)) {
                $translated[$key] = $this->translateComponents($value, $from_locale, $to_locale, $translation_service);
            } else {
                $translated[$key] = $value;
            }
        }

        return $translated;
    }
}

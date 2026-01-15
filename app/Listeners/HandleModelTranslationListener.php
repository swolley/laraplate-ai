<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Helpers\HasTranslations;
use Modules\Core\Search\Traits\Searchable;

final class HandleModelTranslationListener
{
    public function handle(TranslatedModelSaved $event): void
    {
        if (! $this->shouldHandle($event->model)) {
            return;
        }

        // If the model is also searchable, register 'translation' in ModelRequiresIndexing
        // to synchronize translations with indexing
        if (class_uses_trait($event->model, Searchable::class)) {
            $this->registerTranslationForIndexing($event->model);
        }

        // Dispatch translation job
        dispatch(new TranslateModelJob($event->model, $event->locales, $event->force));
        $event->markAsHandled();
    }

    private function shouldHandle(Model $model): bool
    {
        // Check if AI translation feature is enabled
        if (! config('ai.features.translation.enabled', true)) {
            return false;
        }

        // Check if model has HasTranslations trait
        return ! (! class_uses_trait($model, HasTranslations::class));
    }

    private function registerTranslationForIndexing(Model $model): void
    {
        $cache_key = "model_indexing:{$model->getTable()}:{$model->getKey()}";
        $indexing_event = Cache::get($cache_key);

        if ($indexing_event instanceof ModelRequiresIndexing) {
            $indexing_event->addRequiredPreProcessing('translation');
            Cache::put($cache_key, $indexing_event, now()->addMinutes(10));
        }
        // If the event is not in cache, it means indexing hasn't been requested yet
        // or has already been completed. In this case, translations proceed independently.
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Search\Traits\Searchable;

use function ai_config_bool;

final class HandleModelTranslationListener
{
    public function handle(TranslatedModelSaved $event): void
    {
        if (! $this->shouldHandle($event->model)) {
            return;
        }

        if (class_uses_trait($event->model, Searchable::class)) {
            $this->registerTranslationForIndexing($event->model);
        }

        dispatch(new TranslateModelJob($event->model, $event->locales, $event->force));
        $event->markAsHandled();
    }

    private function shouldHandle(Model $model): bool
    {
        if (! ai_config_bool('ai.features.translation.enabled', true)) {
            return false;
        }

        if (! $this->isTranslatable($model)) {
            return false;
        }

        return $model->autoTranslateEnabledBySettings();
    }

    private function registerTranslationForIndexing(Model $model): void
    {
        $model_key = $model->getKey();

        if (! is_int($model_key) && ! is_string($model_key)) {
            return;
        }

        $cache_key = "model_indexing:{$model->getTable()}:{$model_key}";
        $indexing_event = Cache::get($cache_key);

        if ($indexing_event instanceof ModelRequiresIndexing) {
            $indexing_event->addRequiredPreProcessing('translation');
            Cache::put($cache_key, $indexing_event, now()->addMinutes(10));
        }
    }

    /**
     * @phpstan-assert-if-true ITranslatableModel&Model $model
     */
    private function isTranslatable(Model $model): bool
    {
        return in_array(HasTranslations::class, class_uses_recursive($model), true);
    }
}

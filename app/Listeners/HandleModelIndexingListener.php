<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Services\EmbeddingsGate;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Models\Media;

final readonly class HandleModelIndexingListener
{
    public function __construct(private EmbeddingsGate $gate) {}

    public function handle(ModelRequiresIndexing $event): void
    {
        if (! $this->shouldHandle($event->model)) {
            return;
        }

        // Register that embeddings is required BEFORE dispatching
        $event->addRequiredPreProcessing('embeddings');

        // Save updated event to cache (for the finalize listener)
        $this->saveEventToCache($event);

        // Dispatch only the embeddings job, NOT IndexInSearchJob
        // When sync=true in a web context, force async to avoid blocking the HTTP response.
        // When sync=true in CLI context, execute synchronously as requested.
        if ($event->sync && app()->runningInConsole()) {
            app()->call([new GenerateEmbeddingsJob($event->model), 'handle']);
        } else {
            dispatch(new GenerateEmbeddingsJob($event->model));
        }

        $event->markAsHandled();
    }

    private function shouldHandle(Model $model): bool
    {
        // Media has its own analysis-first pipeline (HandleMediaAnalysisListener):
        // its embeddings are chained after AI analysis persists, so the generic
        // embeddings path must not also process it here.
        if ($model instanceof Media) {
            return false;
        }

        return $this->gate->allows($model);
    }

    private function saveEventToCache(ModelRequiresIndexing $event): void
    {
        if ($event->sync) {
            return;
        }

        $model_key = $event->model->getKey();

        if (! is_int($model_key) && ! is_string($model_key)) {
            return;
        }

        Cache::put(ModelRequiresIndexing::cacheKey($event->model), $event, now()->addMinutes(ModelRequiresIndexing::CACHE_TTL_MINUTES));
    }
}

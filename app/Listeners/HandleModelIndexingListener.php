<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Search\Traits\Searchable;

final class HandleModelIndexingListener
{
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
        if ($event->sync) {
            (new GenerateEmbeddingsJob($event->model))->handle();
        } else {
            dispatch(new GenerateEmbeddingsJob($event->model));
        }

        $event->markAsHandled();
    }

    private function shouldHandle(Model $model): bool
    {
        // Check if AI embeddings feature is enabled
        if (! config('ai.features.embeddings.enabled', true)) {
            return false;
        }

        // Check if model supports embeddings (has embed property and vector search enabled)
        if (! $this->modelSupportsEmbeddings($model)) {
            return false;
        }

        return true;
    }

    private function modelSupportsEmbeddings(Model $model): bool
    {
        // Check if model uses Searchable trait
        if (! class_uses_trait($model, Searchable::class)) {
            return false;
        }

        // Check if model has embed property
        if (! isset($model->embed) || $model->embed === []) {
            return false;
        }

        // Check if vector search is enabled for this model
        if (! method_exists($model, 'vectorSearchEnabled') || ! $model->vectorSearchEnabled()) {
            return false;
        }

        return true;
    }

    private function saveEventToCache(ModelRequiresIndexing $event): void
    {
        if (! $event->sync) {
            $cache_key = "model_indexing:{$event->model->getTable()}:{$event->model->getKey()}";
            Cache::put($cache_key, $event, now()->addMinutes(10));
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Services\FeatureModuleGate;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\Core\Events\ModelsRequireIndexing;
use Modules\Core\Search\Traits\Searchable;

/**
 * Batch pre-processing on the bulk indexing path: embeds every embeddable model
 * in the chunk with a single batched call through {@see ModelEmbeddingSynchronizer},
 * instead of one queued GenerateEmbeddingsJob per model. Mirrors the eligibility
 * checks of {@see HandleModelIndexingListener} so the same models are covered.
 */
final readonly class HandleBulkModelIndexingListener
{
    public function __construct(private ModelEmbeddingSynchronizer $synchronizer) {}

    public function handle(ModelsRequireIndexing $event): void
    {
        $embeddable = $event->models
            ->filter(fn (Model $model): bool => $this->shouldEmbed($model))
            ->values();

        if ($embeddable->isEmpty()) {
            return;
        }

        // announceCompletion: false — the bulk path writes the engine itself in
        // adaptive batches (Searchable::adaptiveBulkIndex), so emitting the
        // per-model completion event would double-index every model.
        $this->synchronizer->sync($embeddable, announceCompletion: false);
    }

    private function shouldEmbed(Model $model): bool
    {
        if (! config('ai.features.embeddings.enabled', true)) {
            return false;
        }

        if (! FeatureModuleGate::allows('embeddings', $model)) {
            return false;
        }

        if (! class_uses_trait($model, Searchable::class)) {
            return false;
        }

        return method_exists($model, 'isEmbeddable') && $model->isEmbeddable();
    }
}

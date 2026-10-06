<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Services\EmbeddingsGate;
use Modules\AI\Services\ModelEmbeddingSynchronizer;
use Modules\Core\Events\ModelsRequireIndexing;

/**
 * Batch pre-processing on the bulk indexing path: embeds every embeddable model
 * in the chunk with a single batched call through {@see ModelEmbeddingSynchronizer},
 * instead of one queued GenerateEmbeddingsJob per model. Mirrors the eligibility
 * checks of {@see HandleModelIndexingListener} so the same models are covered.
 */
final readonly class HandleBulkModelIndexingListener
{
    public function __construct(private ModelEmbeddingSynchronizer $synchronizer, private EmbeddingsGate $gate) {}

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
        // reload: false — the models arrive fresh from the import query with their
        // embeddings/translations eager-loaded, so fresh() would only re-query.
        $this->synchronizer->sync($embeddable, announceCompletion: false, reload: false);
    }

    private function shouldEmbed(Model $model): bool
    {
        return $this->gate->allows($model);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Services\FeatureModuleGate;
use Modules\Core\Events\TranslationRequiresReembedding;

/**
 * Reacts to a single-locale content change (e.g. CMS's
 * `ContentTranslationObserver`) by dispatching the AI module's own embedding
 * job for that model/locale. Keeps other modules decoupled from
 * `Modules\AI\Jobs\GenerateEmbeddingsJob` — they fire the generic
 * {@see TranslationRequiresReembedding} event instead.
 *
 * Gates the dispatch with the same global kill switch and per-module
 * allowlist {@see HandleModelIndexingListener::shouldHandle()} applies to
 * whole-model indexing, so a single-locale re-embed can't bypass them.
 */
final class HandleTranslationReembeddingListener
{
    public function handle(TranslationRequiresReembedding $event): void
    {
        if (! $this->shouldHandle($event->model)) {
            return;
        }

        GenerateEmbeddingsJob::dispatch($event->model, $event->locale);
    }

    private function shouldHandle(Model $model): bool
    {
        // Check if AI embeddings feature is enabled
        if (! config('ai.features.embeddings.enabled', true)) {
            return false;
        }

        // Respect the optional per-module allowlist for embeddings.
        return FeatureModuleGate::allows('embeddings', $model);
    }
}

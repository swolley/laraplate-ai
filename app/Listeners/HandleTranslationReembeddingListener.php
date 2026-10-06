<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Services\EmbeddingsGate;
use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Events\TranslationRequiresReembedding;
use Modules\Core\Search\DeferredSearchIndexing;

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
 *
 * During a {@see DeferredSearchIndexing} run (a bulk import) the model is
 * recorded instead: the next flush embeds every stale locale in one batch.
 */
final class HandleTranslationReembeddingListener
{
    public function __construct(private readonly DeferredSearchIndexing $deferredIndexing, private readonly EmbeddingsGate $gate) {}

    public function handle(TranslationRequiresReembedding $event): void
    {
        if (! $this->shouldHandle($event->model)) {
            return;
        }

        if ($event->model instanceof ISearchableModel && $this->deferredIndexing->defer([$event->model])) {
            return;
        }

        GenerateEmbeddingsJob::dispatch($event->model, $event->locale);
    }

    private function shouldHandle(Model $model): bool
    {
        return $this->gate->allows($model, requireEmbeddable: false);
    }
}

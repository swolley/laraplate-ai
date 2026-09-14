<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\Core\Events\TranslationRequiresReembedding;

/**
 * Reacts to a single-locale content change (e.g. CMS's
 * `ContentTranslationObserver`) by dispatching the AI module's own embedding
 * job for that model/locale. Keeps other modules decoupled from
 * `Modules\AI\Jobs\GenerateEmbeddingsJob` — they fire the generic
 * {@see TranslationRequiresReembedding} event instead.
 */
final class HandleTranslationReembeddingListener
{
    public function handle(TranslationRequiresReembedding $event): void
    {
        GenerateEmbeddingsJob::dispatch($event->model, $event->locale);
    }
}

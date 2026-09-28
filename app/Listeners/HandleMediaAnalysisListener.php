<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Support\Facades\Cache;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisGate;
use Modules\AI\Jobs\AnalyzeMediaJob;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Models\Media;

/**
 * Intercepts indexing of a claimed {@see Media} to run AI analysis first (M6,
 * M11, M14, M18). Registered before Core's fallback listener: when the runtime
 * master switch and per-module gate allow, it registers the `media_analysis`
 * pre-processing step, dispatches {@see AnalyzeMediaJob}, and marks the event
 * handled so the deterministic fallback stands down and Core's finalize listener
 * indexes once analysis completes. When AI is off it does nothing, and the
 * fallback indexes the deterministic layer (M12).
 */
final class HandleMediaAnalysisListener
{
    public function __construct(private readonly MediaAnalysisGate $gate) {}

    public function handle(ModelRequiresIndexing $event): void
    {
        $model = $event->model;

        if (! $model instanceof Media) {
            return;
        }

        // Only claimed media (real owner, not a MediaDraft) and only when the AI
        // media subsystem is enabled for this model.
        if (! $model->shouldBeSearchable() || ! $this->gate->allows($model)) {
            return;
        }

        $event->addRequiredPreProcessing('media_analysis');
        $this->saveEventToCache($event);

        if ($event->sync && app()->runningInConsole()) {
            app()->call([new AnalyzeMediaJob($model), 'handle']);
        } else {
            dispatch(new AnalyzeMediaJob($model));
        }

        $event->markAsHandled();
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

        $cache_key = "model_indexing:{$event->model->getTable()}:{$model_key}";
        Cache::put($cache_key, $event, now()->addMinutes(10));
    }
}

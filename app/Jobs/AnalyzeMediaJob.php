<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\Media;
use Throwable;

/**
 * Async AI analysis of a claimed media (M6, M11). Registered as the
 * `media_analysis` pre-processing step by {@see \Modules\AI\Listeners\HandleMediaAnalysisListener};
 * on completion it emits {@see ModelPreProcessingCompleted} so Core's finalize
 * listener indexes the media. On failure it degrades the same way, so a failed
 * analysis still lets the deterministic layer index (M12).
 *
 * Task 6 wires the pipeline; Task 7 fills the per-mime analysis (caption/OCR/
 * transcription/idea/intent), writes the {@see \Modules\AI\Models\MediaAnalysis}
 * row keyed by content hash, and chains embeddings after the analysis persists.
 */
final class AnalyzeMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(private readonly Media $media)
    {
        $this->onQueue('media_analysis');
    }

    public function handle(): void
    {
        // TODO (Task 7): per-mime analysis, MediaAnalysis row, embeddings chaining.
        event(new ModelPreProcessingCompleted($this->media, 'media_analysis'));
    }

    public function failed(Throwable $exception): void
    {
        // Degrade gracefully: signal completion so the document still finalizes
        // with the deterministic layer instead of being held out of the index.
        event(new ModelPreProcessingCompleted($this->media, 'media_analysis'));
    }
}

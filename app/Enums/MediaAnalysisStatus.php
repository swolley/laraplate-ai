<?php

declare(strict_types=1);

namespace Modules\AI\Enums;

/**
 * Lifecycle of a media's AI analysis row (M3b). A row is created `Pending`,
 * moves to `Processing` while the job runs, and ends `Completed` or `Failed`.
 * On failure the media still indexes on the deterministic layer (M12).
 */
enum MediaAnalysisStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}

<?php

declare(strict_types=1);

namespace Modules\AI\Observers;

use Modules\AI\Models\MediaAnalysis;
use Modules\Core\Models\Media;

/**
 * The analysis is shared by every media of one file (M15), so it is refcounted (M19): it is
 * deleted only when the last media with its `content_hash` is force-deleted. A soft-deleted
 * media still counts, since it may be restored.
 */
final class MediaAnalysisRefcountObserver
{
    public function forceDeleted(Media $media): void
    {
        $hash = $media->custom_properties['content_hash'] ?? null;

        if (! is_string($hash) || $hash === '') {
            return;
        }

        $still_referenced = Media::query()
            ->withoutGlobalScopes()
            ->where('custom_properties->content_hash', $hash)
            ->exists();

        if (! $still_referenced) {
            MediaAnalysis::query()->where('content_hash', $hash)->delete();
        }
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Search;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Models\MediaAnalysis;
use Modules\Core\Models\Media;
use Modules\Core\Search\Contracts\ISearchableContributor;
use Modules\Core\Search\Schema\FieldDefinition;
use Modules\Core\Search\Schema\FieldType;
use Modules\Core\Search\Schema\IndexType;

/**
 * Surfaces a media's AI analysis into its searchable document and vector (M4a),
 * without Core referencing AI. The analysis row is looked up by the media's
 * `content_hash` (M15), so duplicated media rows share the same analysis. The
 * compact surrogate (idea/intent/entities) becomes searchable/facet fields; the
 * heavy tracks (transcript/OCR) plus the surrogate feed the embeddable text.
 */
final class MediaAnalysisSearchContributor implements ISearchableContributor
{
    public function contributesTo(): string
    {
        return Media::class;
    }

    public function searchableFields(Model $model): array
    {
        $analysis = $this->analysisFor($model);

        if (! $analysis instanceof MediaAnalysis) {
            return [];
        }

        return array_filter([
            'idea' => $analysis->idea,
            'intent' => $analysis->intent,
            'entities' => $analysis->entities ?? [],
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function searchableMapping(): array
    {
        return [
            new FieldDefinition('idea', FieldType::Text, [IndexType::Searchable]),
            new FieldDefinition('intent', FieldType::Text, [IndexType::Searchable]),
            new FieldDefinition('entities', FieldType::Keyword, [IndexType::Filterable, IndexType::Facetable]),
        ];
    }

    public function embeddableText(Model $model): ?string
    {
        $analysis = $this->analysisFor($model);

        if (! $analysis instanceof MediaAnalysis) {
            return null;
        }

        $parts = array_filter([
            $analysis->idea,
            $analysis->intent,
            $analysis->transcript,
            $analysis->ocr_text,
            implode(' ', $analysis->entities ?? []),
        ], static fn (mixed $value): bool => is_string($value) && mb_trim($value) !== '');

        return $parts === [] ? null : mb_trim(implode(' ', $parts));
    }

    private function analysisFor(Model $model): ?MediaAnalysis
    {
        if (! $model instanceof Media) {
            return null;
        }

        $hash = $model->custom_properties['content_hash'] ?? null;

        return is_string($hash) ? MediaAnalysis::query()->firstWhere('content_hash', $hash) : null;
    }
}

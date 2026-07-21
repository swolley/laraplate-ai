<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent;

use Modules\Core\ApplicationContent\Data\ApplicationContentResult;

final readonly class ApplicationContentPromptProjector
{
    /**
     * @return array{available: true, source: string, items: list<array<string, mixed>>, truncated: bool}
     */
    public function project(ApplicationContentResult $result): array
    {
        $items = [];

        foreach ($result->hits as $index => $hit) {
            $items[] = array_filter([
                'trust' => 'untrusted_application_data',
                'content' => [
                    'kind' => 'application_evidence',
                    'value' => $hit->excerpt,
                ],
                'title' => $hit->label,
                'safe_citation' => [
                    'label' => $hit->label,
                    'reference' => $hit->canonicalReference,
                ],
                'locale' => $hit->locale,
                'revision' => $hit->revision,
                'rank' => $index + 1,
                'truncated' => $hit->truncated,
            ], static fn (mixed $value): bool => $value !== null);
        }

        return [
            'available' => true,
            'source' => $result->source,
            'items' => $items,
            'truncated' => $result->truncated,
        ];
    }
}

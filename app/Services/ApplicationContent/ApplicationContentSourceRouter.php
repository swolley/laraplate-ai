<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent;

use Modules\AI\Services\ApplicationContent\Data\ApplicationContentRequestContext;
use Modules\AI\Services\ApplicationContent\Data\ApplicationContentRoutingDecision;
use Modules\AI\Services\ApplicationContent\Enums\ApplicationContentRoutingStatus;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;

final readonly class ApplicationContentSourceRouter
{
    /**
     * @param  list<ApplicationContentSourceDescriptor>  $authorizedSources
     */
    public function route(
        string $query,
        array $authorizedSources,
        ?ApplicationContentRequestContext $context = null,
        ?string $explicitSource = null,
    ): ApplicationContentRoutingDecision {
        $sources = $this->sourcesByKey($authorizedSources);

        if ($explicitSource !== null) {
            $explicit_source = mb_strtolower(mb_trim($explicitSource));

            return isset($sources[$explicit_source])
                ? $this->selected($explicit_source)
                : $this->noMatch();
        }

        if ($context instanceof ApplicationContentRequestContext) {
            $context_matching = array_filter(
                $sources,
                static fn (ApplicationContentSourceDescriptor $descriptor): bool => $descriptor->module === $context->module
                    && ($context->entity === null || $descriptor->entity === $context->entity),
            );
            $query_matching = array_filter(
                $sources,
                fn (ApplicationContentSourceDescriptor $descriptor): bool => $this->matchesQuery($descriptor, mb_strtolower(mb_trim($query))),
            );

            if (count($query_matching) === 1) {
                $explicit_source = (string) array_key_first($query_matching);
                $context_source = count($context_matching) === 1
                    ? (string) array_key_first($context_matching)
                    : null;

                if ($context_source === null || $explicit_source !== $context_source) {
                    return $this->selected($explicit_source);
                }
            }

            return $this->fromMatches(array_keys($context_matching), contextual: true);
        }

        if ($sources === []) {
            return $this->noMatch();
        }

        $normalized_query = mb_strtolower(mb_trim($query));
        $matching = array_filter(
            $sources,
            fn (ApplicationContentSourceDescriptor $descriptor): bool => $this->matchesQuery($descriptor, $normalized_query),
        );

        if (count($matching) === 1) {
            return $this->selected((string) array_key_first($matching));
        }

        if (count($matching) > 1) {
            return $this->clarification();
        }

        if (count($sources) === 1) {
            return $this->selected((string) array_key_first($sources));
        }

        return $this->clarification();
    }

    /**
     * @param  list<ApplicationContentSourceDescriptor>  $descriptors
     * @return array<string, ApplicationContentSourceDescriptor>
     */
    private function sourcesByKey(array $descriptors): array
    {
        $sources = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor instanceof ApplicationContentSourceDescriptor) {
                $sources[$descriptor->source] = $descriptor;
            }
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    private function matchesQuery(ApplicationContentSourceDescriptor $descriptor, string $query): bool
    {
        if ($query === '') {
            return false;
        }

        $terms = [
            $descriptor->module,
            $descriptor->entity,
            ...explode('.', $descriptor->source),
            ...$descriptor->intentCategories,
        ];

        foreach (array_unique($terms) as $term) {
            $normalized = str_replace('_', ' ', mb_strtolower($term));

            if ($normalized !== '' && str_contains($query, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $matches
     */
    private function fromMatches(array $matches, bool $contextual): ApplicationContentRoutingDecision
    {
        if (count($matches) === 1) {
            return $this->selected($matches[0]);
        }

        if ($matches === []) {
            return $this->noMatch();
        }

        return $contextual ? $this->clarification() : $this->noMatch();
    }

    private function selected(string $source): ApplicationContentRoutingDecision
    {
        return new ApplicationContentRoutingDecision(ApplicationContentRoutingStatus::Selected, $source);
    }

    private function noMatch(): ApplicationContentRoutingDecision
    {
        return new ApplicationContentRoutingDecision(ApplicationContentRoutingStatus::NoMatch);
    }

    private function clarification(): ApplicationContentRoutingDecision
    {
        return new ApplicationContentRoutingDecision(ApplicationContentRoutingStatus::ClarificationRequired);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation;

use InvalidArgumentException;

final readonly class AssistantEvaluationCase
{
    private const array SURFACES = ['documentation', 'application_content', 'graph', 'clarify', 'refuse'];

    /**
     * @param  list<string>  $expectedCitations
     * @param  list<string>  $slices
     */
    public function __construct(
        public string $id,
        public string $query,
        public string $locale,
        public ?string $moduleKey,
        public string $expectedSurface,
        public array $expectedCitations,
        public bool $expectClarification,
        public bool $expectRefusal,
        public array $slices,
    ) {
        $no_citations = $this->expectedCitations === [];

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $this->id) !== 1
            || mb_trim($this->query) === ''
            || mb_strlen($this->query) > 2000
            || preg_match('/^[a-z]{2,3}(?:[-_][A-Z]{2})?$/', $this->locale) !== 1
            || ($this->moduleKey !== null && preg_match('/^[a-z][a-z0-9_]*$/', $this->moduleKey) !== 1)
            || ! in_array($this->expectedSurface, self::SURFACES, true)
            || ! $this->validList($this->expectedCitations, 500)
            || ! $this->validSlugList($this->slices, 63)
            || ($this->expectedSurface === 'clarify' && (! $this->expectClarification || ! $no_citations))
            || ($this->expectedSurface === 'refuse' && (! $this->expectRefusal || ! $no_citations))
            || ($this->expectClarification && $this->expectRefusal)
            || (($this->expectClarification && $this->expectedSurface !== 'clarify'))
            || (($this->expectRefusal && $this->expectedSurface !== 'refuse'))) {
            throw new InvalidArgumentException('Assistant evaluation case is invalid.');
        }
    }

    /**
     * @param  array<mixed>  $values
     */
    private function validList(array $values, int $maximumLength): bool
    {
        if (! array_is_list($values) || count(array_unique($values)) !== count($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || mb_trim($value) === '' || $maximumLength < mb_strlen($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $values
     */
    private function validSlugList(array $values, int $maximumLength): bool
    {
        if (! $this->validList($values, $maximumLength)) {
            return false;
        }

        foreach ($values as $value) {
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) !== 1) {
                return false;
            }
        }

        return true;
    }
}

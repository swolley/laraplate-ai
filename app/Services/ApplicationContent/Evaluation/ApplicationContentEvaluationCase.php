<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use InvalidArgumentException;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;

final readonly class ApplicationContentEvaluationCase
{
    /**
     * @param  list<string>  $expectedHitIds
     * @param  list<string>  $expectedCitationReferences
     * @param  list<string>  $slices
     */
    public function __construct(
        public string $id,
        public string $query,
        public string $locale,
        public int $limit,
        public array $expectedHitIds,
        public array $expectedCitationReferences,
        public bool $expectAuthorizedEmpty,
        public bool $expectSupportedAnswer,
        public bool $expectAbstention,
        public array $slices,
        public ApplicationContentAuthorization $authorization,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $this->id) !== 1
            || mb_trim($this->query) === ''
            || mb_strlen($this->query) > 2000
            || preg_match('/^[a-z]{2,3}(?:[-_][A-Z]{2})?$/', $this->locale) !== 1
            || $this->limit < 1
            || $this->limit > 50
            || ! str_starts_with($this->authorization->permissionName, 'evaluation.')
            || ! $this->validStringList($this->expectedHitIds, 200)
            || ! $this->validStringList($this->expectedCitationReferences, 500)
            || ! $this->validStringList($this->slices, 64)
            || $this->containsUnsafeReference()
            || ($this->expectAuthorizedEmpty && ($this->expectedHitIds !== [] || $this->expectedCitationReferences !== []))
            || ($this->expectSupportedAnswer && $this->expectAbstention)) {
            throw new InvalidArgumentException('Application content evaluation case is invalid.');
        }
    }

    /**
     * @param  array<mixed>  $values
     */
    private function validStringList(array $values, int $maximumLength): bool
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

    private function containsUnsafeReference(): bool
    {
        foreach ($this->expectedCitationReferences as $reference) {
            if (preg_match('#^/app(?:/[A-Za-z0-9][A-Za-z0-9_-]*)+$#', $reference) !== 1) {
                return true;
            }
        }

        foreach ($this->slices as $slice) {
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slice) !== 1) {
                return true;
            }
        }

        return false;
    }
}

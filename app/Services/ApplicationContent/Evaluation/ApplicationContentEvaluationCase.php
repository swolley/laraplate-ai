<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use InvalidArgumentException;
use Modules\AI\Services\Evaluation\EvaluationCaseRules;
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
        if (! EvaluationCaseRules::isValidIdentity($this->id, $this->query, $this->locale)
            || $this->limit < 1
            || $this->limit > 50
            || ! str_starts_with($this->authorization->permissionName, 'evaluation.')
            || ! EvaluationCaseRules::isValidStringList($this->expectedHitIds, 200)
            || ! EvaluationCaseRules::isValidStringList($this->expectedCitationReferences, 500)
            || ! EvaluationCaseRules::isValidSlugList($this->slices, 64)
            || $this->containsUnsafeReference()
            || ($this->expectAuthorizedEmpty && ($this->expectedHitIds !== [] || $this->expectedCitationReferences !== []))
            || ($this->expectSupportedAnswer && $this->expectAbstention)) {
            throw new InvalidArgumentException('Application content evaluation case is invalid.');
        }
    }

    private function containsUnsafeReference(): bool
    {
        foreach ($this->expectedCitationReferences as $reference) {
            if (preg_match('#^/app(?:/[A-Za-z0-9][A-Za-z0-9_-]*)+$#', $reference) !== 1) {
                return true;
            }
        }

        return false;
    }
}

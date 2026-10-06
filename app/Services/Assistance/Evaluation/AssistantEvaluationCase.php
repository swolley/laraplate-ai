<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation;

use InvalidArgumentException;
use Modules\AI\Services\Evaluation\EvaluationCaseRules;

final readonly class AssistantEvaluationCase
{
    private const array SURFACES = ['documentation', 'application_content', 'graph', 'clarify', 'refuse'];

    private const int MAX_PAGE_BYTES = 6000;

    private const int MAX_PROPOSALS = 3;

    /**
     * @param  list<string>  $expectedCitations
     * @param  list<string>  $slices
     * @param  array<array-key, mixed>|null  $page  the `context.page` a client sends, when the case needs one
     * @param  int|null  $expectedProposals  how many proposals the message should carry, when the case measures them
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
        public ?array $page = null,
        public ?int $expectedProposals = null,
    ) {
        $no_citations = $this->expectedCitations === [];

        if (! EvaluationCaseRules::isValidIdentity($this->id, $this->query, $this->locale)
            || ($this->moduleKey !== null && preg_match('/^[a-z][a-z0-9_]*$/', $this->moduleKey) !== 1)
            || ! in_array($this->expectedSurface, self::SURFACES, true)
            || ! EvaluationCaseRules::isValidStringList($this->expectedCitations, 500)
            || ! EvaluationCaseRules::isValidSlugList($this->slices, 63)
            || ($this->expectedSurface === 'clarify' && (! $this->expectClarification || ! $no_citations))
            || ($this->expectedSurface === 'refuse' && (! $this->expectRefusal || ! $no_citations))
            || ($this->expectClarification && $this->expectRefusal)
            || (($this->expectClarification && $this->expectedSurface !== 'clarify'))
            || (($this->expectRefusal && $this->expectedSurface !== 'refuse'))
            || ! $this->validPage($this->page)
            || ! $this->validExpectedProposals($this->expectedProposals, $this->page)) {
            throw new InvalidArgumentException('Assistant evaluation case is invalid.');
        }
    }

    /**
     * The `context.page` a client sends with the message: the case plays the client, so what it
     * carries is bounded like the request would be.
     *
     * @param  array<array-key, mixed>|null  $page
     */
    private function validPage(?array $page): bool
    {
        if ($page === null) {
            return true;
        }

        $encoded = json_encode($page);

        return $page !== []
            && ! array_is_list($page)
            && $encoded !== false
            && mb_strlen($encoded) <= self::MAX_PAGE_BYTES
            && is_string($page['resource'] ?? null);
    }

    /**
     * Proposals come from a page that declares what may be proposed, and a message holds three at most.
     *
     * @param  array<array-key, mixed>|null  $page
     */
    private function validExpectedProposals(?int $expected, ?array $page): bool
    {
        return $expected === null || ($page !== null && $expected >= 0 && $expected <= self::MAX_PROPOSALS);
    }
}

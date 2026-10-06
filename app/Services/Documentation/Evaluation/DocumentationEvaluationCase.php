<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use InvalidArgumentException;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Evaluation\EvaluationCaseRules;

final readonly class DocumentationEvaluationCase
{
    /**
     * @param  list<string>  $expectedSourceLabels
     * @param  list<string>  $expectedCitationLabels
     * @param  list<string>  $slices
     * @param  list<string>  $effectivePermissions
     */
    public function __construct(
        public string $id,
        public string $query,
        public string $locale,
        public int $topK,
        public array $expectedSourceLabels,
        public array $expectedCitationLabels,
        public bool $expectAuthorizedEmpty,
        public bool $expectSupportedAnswer,
        public bool $expectRefusal,
        public array $slices,
        public AssistantTenantScope $tenantScope,
        public ?string $tenantId,
        public array $effectivePermissions,
    ) {
        $empty_expected = $this->expectedSourceLabels === [] && $this->expectedCitationLabels === [];

        if (! EvaluationCaseRules::isValidIdentity($this->id, $this->query, $this->locale)
            || $this->topK < 1
            || $this->topK > 10
            || ! EvaluationCaseRules::isValidStringList($this->expectedSourceLabels, 200)
            || ! EvaluationCaseRules::isValidStringList($this->expectedCitationLabels, 200)
            || ! EvaluationCaseRules::isValidSlugList($this->slices, 64)
            || ! EvaluationCaseRules::isValidStringList($this->effectivePermissions, 200)
            || ($this->tenantScope === AssistantTenantScope::Global && $this->tenantId !== null)
            || ($this->tenantScope === AssistantTenantScope::Tenant
                && ($this->tenantId === null || mb_trim($this->tenantId) === ''))
            || ($this->expectAuthorizedEmpty && ! $empty_expected)
            || ($this->expectRefusal && ! $empty_expected)
            || ($this->expectSupportedAnswer && $this->expectRefusal)) {
            throw new InvalidArgumentException('Documentation evaluation case is invalid.');
        }
    }

    public function accessContext(): AssistantAccessContext
    {
        return new AssistantAccessContext(
            profile: AssistantProfile::InAppAssistance,
            userId: 'evaluation-user',
            tenantScope: $this->tenantScope,
            tenantId: $this->tenantId,
            locale: $this->locale,
            effectivePermissions: $this->effectivePermissions,
            conversationId: 'evaluation-conversation',
        );
    }
}

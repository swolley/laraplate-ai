<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use InvalidArgumentException;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;

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

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $this->id) !== 1
            || mb_trim($this->query) === ''
            || mb_strlen($this->query) > 2000
            || preg_match('/^[a-z]{2,3}(?:[-_][A-Z]{2})?$/', $this->locale) !== 1
            || $this->topK < 1
            || $this->topK > 10
            || ! $this->validStringList($this->expectedSourceLabels, 200)
            || ! $this->validStringList($this->expectedCitationLabels, 200)
            || ! $this->validSlugList($this->slices, 64)
            || ! $this->validStringList($this->effectivePermissions, 200)
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

    /**
     * @param  array<mixed>  $values
     */
    private function validSlugList(array $values, int $maximumLength): bool
    {
        if (! $this->validStringList($values, $maximumLength)) {
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

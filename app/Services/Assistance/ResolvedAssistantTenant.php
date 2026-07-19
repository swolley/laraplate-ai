<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use InvalidArgumentException;
use Modules\AI\Enums\AssistantTenantScope;

final readonly class ResolvedAssistantTenant
{
    private function __construct(
        public AssistantTenantScope $scope,
        public ?string $tenantId,
    ) {
        if ($scope === AssistantTenantScope::Global && $tenantId !== null) {
            throw new InvalidArgumentException('Global assistant scope cannot have a tenant ID.');
        }

        if ($scope === AssistantTenantScope::Tenant && ($tenantId === null || trim($tenantId) === '')) {
            throw new InvalidArgumentException('Tenant assistant scope requires a tenant ID.');
        }
    }

    public static function global(): self
    {
        return new self(AssistantTenantScope::Global, null);
    }

    public static function tenant(string $tenant_id): self
    {
        return new self(AssistantTenantScope::Tenant, trim($tenant_id));
    }
}

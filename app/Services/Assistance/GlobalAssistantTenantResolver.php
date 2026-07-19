<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use Modules\AI\Services\Assistance\Contracts\AssistantTenantResolverInterface;
use Modules\Core\Models\User;

final readonly class GlobalAssistantTenantResolver implements AssistantTenantResolverInterface
{
    public function resolveFor(User $user): ResolvedAssistantTenant
    {
        return ResolvedAssistantTenant::global();
    }
}

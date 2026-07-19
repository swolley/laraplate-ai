<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Contracts;

use Modules\AI\Services\Assistance\ResolvedAssistantTenant;
use Modules\Core\Models\User;

interface AssistantTenantResolverInterface
{
    public function resolveFor(User $user): ResolvedAssistantTenant;
}

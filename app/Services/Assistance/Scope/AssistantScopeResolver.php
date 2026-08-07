<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Scope;

use Modules\AI\Enums\AssistantProfile;

final readonly class AssistantScopeResolver
{
    public function resolve(AssistantProfile $profile, ?string $moduleKey): AssistantScope
    {
        if ($profile !== AssistantProfile::InAppAssistance) {
            return AssistantScope::generic();
        }

        if ($moduleKey === null || preg_match('/^[a-z][a-z0-9_]*$/', $moduleKey) !== 1) {
            return new AssistantScope(null, DataAccess::Application, DocScope::Application);
        }

        return new AssistantScope($moduleKey, DataAccess::Module, DocScope::Module);
    }
}

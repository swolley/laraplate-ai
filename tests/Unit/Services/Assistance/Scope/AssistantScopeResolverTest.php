<?php

declare(strict_types=1);

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\Assistance\Scope\AssistantScopeResolver;
use Modules\AI\Services\Assistance\Scope\DataAccess;
use Modules\AI\Services\Assistance\Scope\DocScope;

it('scopes in-app assistance to a verified module', function (): void {
    $scope = (new AssistantScopeResolver)->resolve(AssistantProfile::InAppAssistance, 'erp');
    expect($scope->moduleKey)->toBe('erp')
        ->and($scope->dataAccess)->toBe(DataAccess::Module)
        ->and($scope->docScope)->toBe(DocScope::Module);
});

it('falls back to documentation-only when in-app has no recognizable module', function (): void {
    $scope = (new AssistantScopeResolver)->resolve(AssistantProfile::InAppAssistance, null);
    expect($scope->moduleKey)->toBeNull()
        ->and($scope->dataAccess)->toBe(DataAccess::None)
        ->and($scope->docScope)->toBe(DocScope::Application);
});

it('keeps developer help generic and data-free even if a module is passed', function (): void {
    $scope = (new AssistantScopeResolver)->resolve(AssistantProfile::DeveloperHelp, 'erp');
    expect($scope->moduleKey)->toBeNull()
        ->and($scope->dataAccess)->toBe(DataAccess::None)
        ->and($scope->docScope)->toBe(DocScope::Application);
});

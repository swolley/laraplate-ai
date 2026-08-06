<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Services\Assistance\Scope\DataAccess;
use Modules\AI\Services\Assistance\Scope\DocScope;

it('builds a module scope', function (): void {
    $scope = new AssistantScope('erp', DataAccess::Module, DocScope::Module);
    expect($scope->moduleKey)->toBe('erp')
        ->and($scope->dataAccess)->toBe(DataAccess::Module)
        ->and($scope->docScope)->toBe(DocScope::Module);
});

it('builds a generic scope with no module and no data access', function (): void {
    $scope = AssistantScope::generic();
    expect($scope->moduleKey)->toBeNull()
        ->and($scope->dataAccess)->toBe(DataAccess::None)
        ->and($scope->docScope)->toBe(DocScope::Application);
});

it('rejects a module docScope without a moduleKey', function (): void {
    expect(fn () => new AssistantScope(null, DataAccess::None, DocScope::Module))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a module dataAccess without a moduleKey', function (): void {
    expect(fn () => new AssistantScope(null, DataAccess::Module, DocScope::Application))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a malformed module key', function (): void {
    expect(fn () => new AssistantScope('Not A Module', DataAccess::Module, DocScope::Module))
        ->toThrow(InvalidArgumentException::class);
});

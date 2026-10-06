<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Policies\AssistantPolicyRuleSet;
use Modules\AI\Services\Assistance\Policies\CompiledAssistantPolicy;
use Modules\AI\Services\Assistance\Policies\ToolNameMatcher;

function wildcardRuleSet(array $allowed, array $denied = []): AssistantPolicyRuleSet
{
    return new AssistantPolicyRuleSet(
        instruction: 'Test.',
        allowedCorpora: [],
        allowedTools: $allowed,
        allowedFields: [],
        deniedTools: $denied,
    );
}

it('matches a name against an exact entry or a trailing pattern', function (string $name, array $entries, bool $expected): void {
    expect(ToolNameMatcher::matchesAny($name, $entries))->toBe($expected);
})->with([
    'exact' => ['graph_search', ['graph_search'], true],
    'exact other' => ['graph_search', ['graph_expand'], false],
    'pattern' => ['crud_update_cms_content', ['crud_update_*'], true],
    'pattern other operation' => ['crud_delete_cms_content', ['crud_update_*'], false],
    'broader pattern' => ['crud_update_cms_content', ['crud_*'], true],
    'pattern is not a substring match' => ['my_crud_update_x', ['crud_update_*'], false],
]);

it('lets a denial override an allowance, also against a pattern', function (): void {
    expect(ToolNameMatcher::allows('crud_approve_cms_content', ['crud_*'], ['crud_approve_*']))->toBeFalse()
        ->and(ToolNameMatcher::allows('crud_update_cms_content', ['crud_*'], ['crud_approve_*']))->toBeTrue();
});

it('rejects a wildcard anywhere but at the end', function (string $entry): void {
    expect(fn () => wildcardRuleSet([$entry]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => wildcardRuleSet(['graph_search'], [$entry]))->toThrow(InvalidArgumentException::class);
})->with(['middle' => ['crud_*_content'], 'start' => ['*_content'], 'double' => ['crud_**']]);

it('keeps a pattern that both sides carry and drops one that only a side carries', function (): void {
    $both = wildcardRuleSet(['crud_update_*'])->intersect(wildcardRuleSet(['crud_update_*']));
    $one = wildcardRuleSet(['crud_update_*'])->intersect(wildcardRuleSet(['graph_search']));

    expect($both->allowedTools)->toBe(['crud_update_*'])
        ->and($one->allowedTools)->toBe([]);
});

it('narrows a broad pattern to the specific one on intersection, never the opposite', function (): void {
    $narrowed = wildcardRuleSet(['crud_*'])->intersect(wildcardRuleSet(['crud_update_*']));
    $exact = wildcardRuleSet(['crud_*'])->intersect(wildcardRuleSet(['crud_update_cms_content', 'graph_search']));

    expect($narrowed->allowedTools)->toBe(['crud_update_*'])
        ->and($exact->allowedTools)->toBe(['crud_update_cms_content']);
});

it('drops an exact name a denial matches, and carries the denial for the patterns it cannot subtract', function (): void {
    $set = wildcardRuleSet(['crud_*', 'crud_approve_cms_content'], ['crud_approve_*'])
        ->intersect(wildcardRuleSet(['crud_*', 'crud_approve_cms_content']));
    $policy = new CompiledAssistantPolicy('v', 'p', [], $set->allowedTools, [], $set->deniedTools);

    expect($set->allowedTools)->toBe(['crud_*'])
        ->and($policy->allowsTool('crud_approve_cms_content'))->toBeFalse()
        ->and($policy->allowsTool('crud_create_cms_content'))->toBeTrue()
        ->and($policy->allowsTool('graph_search'))->toBeFalse();
});

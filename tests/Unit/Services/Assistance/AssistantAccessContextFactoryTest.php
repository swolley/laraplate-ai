<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Modules\AI\Console\LaraplateHelpCommand;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\AssistantPromptContext;
use Modules\AI\Services\Assistance\Contracts\AssistantTenantResolverInterface;
use Modules\AI\Services\Assistance\ResolvedAssistantTenant;
use Modules\Core\Models\User;

function assistanceUserMock(
    int $id = 7,
    string $locale = 'it',
    ?Collection $permissions = null,
    bool $guest = false,
): User {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn($id);
    $user->shouldReceive('getAttribute')->with('lang')->andReturn($locale);
    $user->shouldReceive('isGuest')->andReturn($guest);
    $user->shouldReceive('getAllPermissions')->andReturn($permissions ?? collect());

    return $user;
}

function assistanceConversation(int $user_id = 7, int $id = 11): Conversation
{
    $conversation = new Conversation;
    $conversation->forceFill(['id' => $id, 'user_id' => $user_id]);
    $conversation->exists = true;

    return $conversation;
}

it('defines only the two server-owned assistant profiles', function (): void {
    expect(AssistantProfile::cases())->toEqual([
        AssistantProfile::DeveloperHelp,
        AssistantProfile::InAppAssistance,
    ]);
});

it('builds in-app context from authenticated server state', function (): void {
    config()->set('app.available_locales', ['en', 'it']);

    $permissions = collect([
        (object) ['name' => 'default.contents.select'],
        (object) ['name' => 'default.dashboard.select'],
        (object) ['name' => 'default.contents.select'],
    ]);
    $user = assistanceUserMock(permissions: $permissions);

    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldReceive('resolveFor')->once()->with($user)
        ->andReturn(ResolvedAssistantTenant::global());

    $context = (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    );

    expect($context->profile)->toBe(AssistantProfile::InAppAssistance)
        ->and($context->userId)->toBe('7')
        ->and($context->conversationId)->toBe('11')
        ->and($context->tenantScope)->toBe(AssistantTenantScope::Global)
        ->and($context->tenantId)->toBeNull()
        ->and($context->locale)->toBe('it')
        ->and($context->effectivePermissions)->toBe([
            'default.contents.select',
            'default.dashboard.select',
        ]);
});

it('rejects an authenticated user who does not own the conversation', function (): void {
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(user_id: 8),
        assistanceUserMock(id: 7),
    ))->toThrow(AuthorizationException::class);
});

it('rejects the configured guest before resolving tenant or permissions', function (): void {
    $user = assistanceUserMock(guest: true);
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class);
});

it('fails closed when guest classification fails', function (): void {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn(7);
    $user->shouldReceive('isGuest')->once()
        ->andThrow(new UnexpectedValueException('invalid guest configuration'));
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class, 'Assistant access context is unavailable.');
});

it('fails closed when tenant resolution fails', function (): void {
    $user = assistanceUserMock();
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldReceive('resolveFor')->once()->with($user)
        ->andThrow(new RuntimeException('resolver unavailable'));

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class);
});

it('fails closed when effective permissions cannot be resolved', function (): void {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn(7);
    $user->shouldReceive('getAttribute')->with('lang')->andReturn('it');
    $user->shouldReceive('isGuest')->andReturnFalse();
    $user->shouldReceive('getAllPermissions')->once()
        ->andThrow(new RuntimeException('permission store unavailable'));

    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldReceive('resolveFor')->once()->with($user)
        ->andReturn(ResolvedAssistantTenant::global());

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class);
});

it('creates developer help context without runtime identity or tenant access', function (): void {
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    $command = Artisan::all()['ai:help'] ?? null;

    expect($command)->toBeInstanceOf(LaraplateHelpCommand::class);

    $context = (new AssistantAccessContextFactory($resolver))->forDeveloperHelp($command);

    expect($context->profile)->toBe(AssistantProfile::DeveloperHelp)
        ->and($context->userId)->toBeNull()
        ->and($context->conversationId)->toBeNull()
        ->and($context->tenantScope)->toBeNull()
        ->and($context->tenantId)->toBeNull()
        ->and($context->effectivePermissions)->toBe([]);
});

it('rejects developer help context outside the dedicated artisan command', function (): void {
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $command = Mockery::mock(Command::class);

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forDeveloperHelp($command))
        ->toThrow(AuthorizationException::class);
});

it('rejects an unregistered instance of the developer help command', function (): void {
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forDeveloperHelp(
        new LaraplateHelpCommand,
    ))->toThrow(AuthorizationException::class);
});

it('keeps prompt context free from control-plane data recursively', function (): void {
    $context = new AssistantPromptContext(
        policyVersion: 'in-app-v1',
        presentationPreferences: ['verbosity' => 'short'],
        safeCitations: [['label' => 'Dashboard help', 'reference' => '/app/dashboard']],
        authorizedResults: [['excerpt' => 'Use the filters button.']],
    );

    expect($context->policyVersion)->toBe('in-app-v1');

    expect(fn () => new AssistantPromptContext(
        policyVersion: 'in-app-v1',
        presentationPreferences: [],
        safeCitations: [],
        authorizedResults: [['metadata' => ['permissions' => ['secret.select']]]],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new AssistantPromptContext(
        policyVersion: 'in-app-v1',
        presentationPreferences: [],
        safeCitations: [],
        authorizedResults: [['identity_claims' => ['user_id' => 9]]],
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => new AssistantPromptContext(
        policyVersion: 'in-app-v1',
        presentationPreferences: [],
        safeCitations: [],
        authorizedResults: [['payload' => new stdClass]],
    ))->toThrow(InvalidArgumentException::class);
});

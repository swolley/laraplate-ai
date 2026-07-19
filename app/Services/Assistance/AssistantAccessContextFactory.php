<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Modules\AI\Console\LaraplateHelpCommand;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\Contracts\AssistantTenantResolverInterface;
use Modules\Core\Models\User;
use Throwable;
use UnexpectedValueException;

final readonly class AssistantAccessContextFactory
{
    public function __construct(
        private AssistantTenantResolverInterface $tenant_resolver,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function forInApp(Conversation $conversation, User $authenticated_user): AssistantAccessContext
    {
        $user_id = $this->normalizedKey($authenticated_user->getKey());
        $conversation_id = $this->normalizedKey($conversation->getKey());
        $owner_id = $this->normalizedKey($conversation->getAttribute('user_id'));

        if ($user_id === null || $conversation_id === null || $owner_id === null || $owner_id !== $user_id) {
            throw new AuthorizationException('Assistant access context is unavailable.');
        }

        try {
            $tenant = $this->tenant_resolver->resolveFor($authenticated_user);
            $permissions = $this->effectivePermissions($authenticated_user);
        } catch (Throwable $exception) {
            throw new AuthorizationException(
                'Assistant access context is unavailable.',
                previous: $exception,
            );
        }

        return new AssistantAccessContext(
            profile: AssistantProfile::InAppAssistance,
            userId: $user_id,
            tenantScope: $tenant->scope,
            tenantId: $tenant->tenantId,
            locale: $this->localeFor($authenticated_user),
            effectivePermissions: $permissions,
            conversationId: $conversation_id,
        );
    }

    /**
     * @throws AuthorizationException
     */
    public function forDeveloperHelp(Command $command): AssistantAccessContext
    {
        if (! app()->runningInConsole() || ! $command instanceof LaraplateHelpCommand) {
            throw new AuthorizationException('Developer help is available only from the console.');
        }

        $console_application = $command->getApplication();
        $is_registered_help_command = $console_application !== null
            && $console_application->has('ai:help')
            && $console_application->find('ai:help') === $command;

        if (! $is_registered_help_command) {
            throw new AuthorizationException('Developer help is available only from the console.');
        }

        return new AssistantAccessContext(
            profile: AssistantProfile::DeveloperHelp,
            userId: null,
            tenantScope: null,
            tenantId: null,
            locale: (string) config('app.locale', 'en'),
            effectivePermissions: [],
            conversationId: null,
        );
    }

    /**
     * @return list<string>
     */
    private function effectivePermissions(User $user): array
    {
        $names = [];

        foreach ($user->getAllPermissions() as $permission) {
            $name = $permission->name ?? null;

            if (! is_string($name) || trim($name) === '') {
                throw new UnexpectedValueException('Effective permission name is unavailable.');
            }

            $names[] = trim($name);
        }

        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);

        return $names;
    }

    private function localeFor(User $user): string
    {
        $fallback = (string) config('app.locale', 'en');
        $locale = $user->getAttribute('lang');

        if (! is_string($locale) || trim($locale) === '') {
            return $fallback;
        }

        $available_locales = config('app.available_locales', []);

        if (is_array($available_locales) && $available_locales !== [] && ! in_array($locale, $available_locales, true)) {
            return $fallback;
        }

        return $locale;
    }

    private function normalizedKey(mixed $key): ?string
    {
        if (! is_int($key) && ! is_string($key)) {
            return null;
        }

        $normalized = trim((string) $key);

        return $normalized === '' ? null : $normalized;
    }
}

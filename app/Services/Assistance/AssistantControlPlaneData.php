<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class AssistantControlPlaneData
{
    private const array FORBIDDEN_KEYS = [
        'acl',
        'acl_expression',
        'identity',
        'identity_claims',
        'permission',
        'permissions',
        'policy',
        'policy_config',
        'profile',
        'raw_policy',
        'role',
        'roles',
        'system_message',
        'system_prompt',
        'tenant',
        'tenant_id',
        'tenant_scope',
        'tools',
        'user',
        'user_id',
    ];

    /**
     * @param array<array-key, mixed> $values
     */
    public static function containsForbiddenKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isForbiddenKey($key)) {
                return true;
            }

            if (is_array($value) && self::containsForbiddenKey($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $values
     */
    public static function assertPromptSafe(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && self::isForbiddenKey($key)) {
                throw new InvalidArgumentException('Control-plane data is not allowed in assistant prompt context.');
            }

            if (is_object($value) || is_resource($value)) {
                throw new InvalidArgumentException('Assistant prompt context must contain only scalar, null, or array values.');
            }

            if (is_array($value)) {
                self::assertPromptSafe($value);
            }
        }
    }

    private static function isForbiddenKey(string $key): bool
    {
        $normalized_key = Str::snake(str_replace(['.', '-'], '_', trim($key)));

        return in_array($normalized_key, self::FORBIDDEN_KEYS, true);
    }
}

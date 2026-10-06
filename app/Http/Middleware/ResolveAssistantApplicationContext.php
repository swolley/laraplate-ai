<?php

declare(strict_types=1);

namespace Modules\AI\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Services\Authorization\AuthorizationService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Turns the page a client says its user is on into the request attribute
 * `assistant_application_context`, which InAppAssistanceService reads to narrow the assistant to
 * a module. It is the only writer of that attribute.
 *
 * `context.page.resource` is `{module}/{entity}`, the pair of a CRUD route. It is resolved through
 * the lookup Core uses for those routes, and counts only when it names a model of that module that
 * the user may read. Anything else leaves the assistant generic: a client can narrow the
 * assistant to a place it already reaches and cannot widen it, whatever the page claims.
 */
final readonly class ResolveAssistantApplicationContext
{
    public const string ATTRIBUTE = 'assistant_application_context';

    private const string RESOURCE_PATTERN = '/^([a-z][a-z0-9_]{0,63})\/([a-z][a-z0-9_]{0,63})$/';

    private const int MAX_RECORD_KEY_LENGTH = 255;

    public function __construct(private AuthorizationService $authorization) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->remove(self::ATTRIBUTE);

        $context = $this->resolve($request);

        if ($context !== null) {
            $request->attributes->set(self::ATTRIBUTE, $context);
        }

        return $next($request);
    }

    /**
     * @return array{module: string, entity: string, record_key: int|string|null}|null
     */
    private function resolve(Request $request): ?array
    {
        $page = $request->input('context.page');

        if (! is_array($page) || ! is_string($page['resource'] ?? null)) {
            return null;
        }

        if (preg_match(self::RESOURCE_PATTERN, $page['resource'], $parts) !== 1) {
            return null;
        }

        [, $module, $entity] = $parts;

        if (! $this->isReadableModel($request, $module, $entity)) {
            return null;
        }

        return ['module' => $module, 'entity' => $entity, 'record_key' => $this->recordKey($page['recordKey'] ?? null)];
    }

    private function isReadableModel(Request $request, string $module, string $entity): bool
    {
        try {
            $model_class = DynamicEntity::tryResolveModel($entity, null, $module);

            if ($model_class === null) {
                return false;
            }

            $this->authorization->ensurePermissionForClass($request, $model_class, 'select');
        } catch (AuthorizationException) {
            return false;
        } catch (Throwable) {
            // A lookup that cannot say what the resource is must not name a place to the assistant.
            return false;
        }

        return true;
    }

    private function recordKey(mixed $value): int|string|null
    {
        return match (true) {
            is_int($value) => $value,
            is_string($value) && mb_trim($value) !== '' && mb_strlen($value) <= self::MAX_RECORD_KEY_LENGTH => $value,
            default => null,
        };
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Restricts AI features (embeddings, translation, ...) to an optional per-module
 * allowlist configured at `ai.features.{feature}.modules`.
 *
 * An empty or missing allowlist allows every module — the default, backward-
 * compatible behavior. When the allowlist is non-empty, only models whose owning
 * module (derived from the `Modules\{Name}\` namespace) appears in the list are
 * processed; models outside any module are excluded.
 */
final class FeatureModuleGate
{
    public static function allows(string $feature, Model $model): bool
    {
        $allowed = config("ai.features.{$feature}.modules", []);

        if (! is_array($allowed) || $allowed === []) {
            return true;
        }

        $module = self::moduleOf($model);

        if ($module === null) {
            return false;
        }

        $normalized = array_map(static fn (mixed $name): string => mb_strtolower((string) $name), $allowed);

        return in_array(mb_strtolower($module), $normalized, true);
    }

    private static function moduleOf(Model $model): ?string
    {
        if (preg_match('/^Modules\\\\([^\\\\]+)\\\\/', $model::class, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}

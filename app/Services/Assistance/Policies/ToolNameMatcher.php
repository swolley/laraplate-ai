<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use InvalidArgumentException;

/**
 * Matches tool names against policy entries that are an exact name or a prefix pattern ending in `*`.
 *
 * Tools built per entity (`crud_update_cms_content`) cannot be listed by name, so a policy names the
 * class (`crud_update_*`). The star is allowed at the end only: anywhere else it would turn a
 * policy into a regular expression nobody reviews.
 */
final class ToolNameMatcher
{
    public static function assertValid(string $entry): void
    {
        $position = mb_strpos($entry, '*');

        if ($position !== false && $position !== mb_strlen($entry) - 1) {
            throw new InvalidArgumentException('A tool name wildcard is allowed only as the last character.');
        }
    }

    /**
     * Whether a concrete tool name is allowed: some allowed entry matches it and no denied entry does.
     * Deny overrides allow.
     *
     * @param  list<string>  $allowed
     * @param  list<string>  $denied
     */
    public static function allows(string $name, array $allowed, array $denied = []): bool
    {
        return self::matchesAny($name, $allowed) && ! self::matchesAny($name, $denied);
    }

    /**
     * @param  list<string>  $entries
     */
    public static function matchesAny(string $name, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::covers($entry, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$pattern` covers `$entry`, where the entry may itself be a pattern: `crud_*` covers
     * `crud_update_*` and `crud_update_cms_content`, and never the other way round.
     */
    public static function covers(string $pattern, string $entry): bool
    {
        if (! str_ends_with($pattern, '*')) {
            return $pattern === $entry;
        }

        return str_starts_with(mb_rtrim($entry, '*'), mb_substr($pattern, 0, -1));
    }

    /**
     * The entries both sides admit: each entry of one side that the other side covers.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    public static function intersect(array $left, array $right): array
    {
        $values = [];

        foreach ($left as $entry) {
            if (self::matchesAny($entry, $right)) {
                $values[] = $entry;
            }
        }

        foreach ($right as $entry) {
            if (self::matchesAny($entry, $left)) {
                $values[] = $entry;
            }
        }

        return array_values(array_unique($values));
    }
}

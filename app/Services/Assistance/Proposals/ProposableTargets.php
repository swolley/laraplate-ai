<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Proposals;

use Modules\AI\Data\UiProposal;
use Modules\Core\Rules\PreferencesBag;

/**
 * The targets a client declares it is willing to have proposed, read from `context.page.proposable`.
 *
 * Everything in the list is untrusted client input, and a target that does not pass the bounds
 * below is dropped, never repaired. The list narrows what the assistant may suggest and grants
 * nothing: it holds no field, record, tool or permission.
 */
final readonly class ProposableTargets
{
    public const int MAX_TARGETS = 30;

    public const int MAX_CURRENT_BYTES = 500;

    public const int MAX_DESCRIPTION_LENGTH = 120;

    private const string KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/';

    private const string RESOURCE_PATTERN = '/^[a-z][a-z0-9_]{0,63}\/[a-z][a-z0-9_]{0,63}$/';

    /**
     * @param  list<ProposableTarget>  $targets
     */
    public function __construct(private array $targets = []) {}

    public static function fromPageContext(mixed $proposable): self
    {
        if (! is_array($proposable) || ! array_is_list($proposable)) {
            return new self;
        }

        $targets = [];

        foreach (array_slice($proposable, 0, self::MAX_TARGETS) as $entry) {
            $target = is_array($entry) ? self::parse($entry) : null;

            if ($target instanceof ProposableTarget && self::findIn($targets, $target->kind, $target->target) === null) {
                $targets[] = $target;
            }
        }

        return new self($targets);
    }

    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    /**
     * @param  array<string, string>  $target
     */
    public function find(string $kind, array $target): ?ProposableTarget
    {
        return self::findIn($this->targets, $kind, $target);
    }

    /**
     * What a tool says about the targets it may propose, as bounded data for the model: the name of
     * each target and a short description the client wrote. It is untrusted text, never an instruction.
     *
     * @return list<string>
     */
    public function describe(string $kind): array
    {
        $lines = [];

        foreach ($this->targets as $target) {
            if ($target->kind === $kind) {
                $lines[] = implode('.', $target->target) . ($target->description === '' ? '' : ': ' . $target->description);
            }
        }

        return $lines;
    }

    public function has(string $kind): bool
    {
        return array_any($this->targets, static fn (ProposableTarget $target): bool => $target->kind === $kind);
    }

    /**
     * @param  array<array-key, mixed>  $entry
     */
    private static function parse(array $entry): ?ProposableTarget
    {
        $kind = $entry['kind'] ?? null;
        $target = $entry['target'] ?? null;
        $schema = $entry['schema'] ?? null;

        if (! in_array($kind, [UiProposal::KIND_PREFERENCE, UiProposal::KIND_VIEW_STATE], true)
            || ! is_array($target)
            || ! is_array($schema)
            || ! ProposalSchema::isSupported($schema)) {
            return null;
        }

        $target = $kind === UiProposal::KIND_PREFERENCE
            ? self::preferenceTarget($target)
            : self::viewStateTarget($target);

        if ($target === null) {
            return null;
        }

        return new ProposableTarget(
            kind: $kind,
            target: $target,
            schema: $schema,
            current: self::boundedCurrent($entry['current'] ?? null),
            description: self::description($entry['description'] ?? null),
        );
    }

    /**
     * @param  array<array-key, mixed>  $target
     * @return array{namespace: string, key: string}|null
     */
    private static function preferenceTarget(array $target): ?array
    {
        $namespace = $target['namespace'] ?? null;
        $key = $target['key'] ?? null;

        if (! is_string($namespace) || ! PreferencesBag::isNamespace($namespace) || ! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return null;
        }

        return ['namespace' => $namespace, 'key' => $key];
    }

    /**
     * @param  array<array-key, mixed>  $target
     * @return array{resource: string, view: string}|null
     */
    private static function viewStateTarget(array $target): ?array
    {
        $resource = $target['resource'] ?? null;
        $view = $target['view'] ?? null;

        if (! is_string($resource) || preg_match(self::RESOURCE_PATTERN, $resource) !== 1 || ! is_string($view) || preg_match(self::KEY_PATTERN, $view) !== 1) {
            return null;
        }

        return ['resource' => $resource, 'view' => $view];
    }

    private static function boundedCurrent(mixed $current): mixed
    {
        $encoded = json_encode($current);

        return $encoded !== false && mb_strlen($encoded) <= self::MAX_CURRENT_BYTES ? $current : null;
    }

    private static function description(mixed $description): string
    {
        if (! is_string($description)) {
            return '';
        }

        $single_line = preg_replace('/[\p{C}\s<>]+/u', ' ', $description);

        return mb_substr(mb_trim(is_string($single_line) ? $single_line : ''), 0, self::MAX_DESCRIPTION_LENGTH);
    }

    /**
     * @param  list<ProposableTarget>  $targets
     * @param  array<string, string>  $target
     */
    private static function findIn(array $targets, string $kind, array $target): ?ProposableTarget
    {
        foreach ($targets as $candidate) {
            if ($candidate->kind === $kind && $candidate->target === $target) {
                return $candidate;
            }
        }

        return null;
    }
}

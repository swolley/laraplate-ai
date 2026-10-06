<?php

declare(strict_types=1);

namespace Modules\AI\Data;

/**
 * A change the assistant suggests to the user's own client. It names a target and a value and
 * carries no code, markup, URL or tool call; nothing applies it on the server.
 *
 * @phpstan-type Target array<string, string>
 */
final readonly class UiProposal
{
    public const int SCHEMA_VERSION = 1;

    public const string KIND_PREFERENCE = 'preference';

    public const string KIND_VIEW_STATE = 'view_state';

    /**
     * @param  array<string, string>  $target  `namespace` and `key` for a preference, `resource` and `view` for a view state
     */
    public function __construct(
        public string $id,
        public string $kind,
        public array $target,
        public mixed $current,
        public mixed $proposed,
        public string $reason,
    ) {}

    /**
     * @return array{id: string, schemaVersion: int, kind: string, target: array<string, string>, current: mixed, proposed: mixed, reason: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'schemaVersion' => self::SCHEMA_VERSION,
            'kind' => $this->kind,
            'target' => $this->target,
            'current' => $this->current,
            'proposed' => $this->proposed,
            'reason' => $this->reason,
        ];
    }
}

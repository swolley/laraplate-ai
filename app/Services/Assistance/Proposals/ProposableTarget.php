<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Proposals;

/**
 * One target a client declared in `context.page.proposable`, after the server bounded it.
 */
final readonly class ProposableTarget
{
    /**
     * @param  array<string, string>  $target
     * @param  array<array-key, mixed>  $schema
     */
    public function __construct(
        public string $kind,
        public array $target,
        public array $schema,
        public mixed $current,
        public string $description,
    ) {}
}

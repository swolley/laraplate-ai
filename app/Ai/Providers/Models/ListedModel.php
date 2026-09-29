<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Modules\AI\Enums\ModelCapability;

/**
 * A model a provider lists. `null` capabilities means the provider does not say: such a
 * model satisfies every requirement, since including an unknown model is the last resort,
 * never excluding it.
 */
final readonly class ListedModel
{
    /**
     * @param  list<ModelCapability>|null  $capabilities
     */
    public function __construct(
        public string $id,
        public ?array $capabilities,
    ) {}

    /**
     * @param  list<ModelCapability>  $required
     */
    public function satisfies(array $required): bool
    {
        if ($this->capabilities === null) {
            return true;
        }

        return array_all($required, fn (ModelCapability $capability): bool => in_array($capability, $this->capabilities, true));
    }
}

<?php

declare(strict_types=1);

namespace Modules\Core\Search\Traits;

if (! trait_exists(Searchable::class, false)) {
    trait Searchable
    {
        public function vectorSearchEnabled(): bool
        {
            return true;
        }

        public function searchableAs(): string
        {
            return $this->getTable();
        }
    }
}

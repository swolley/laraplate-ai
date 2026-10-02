<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Model;

/**
 * A translatable model that knows which translation it was first written in, as
 * {@see \Modules\Core\Contracts\ITranslatableModel::getOriginalTranslation()} describes. Holds
 * its translations in memory, so a test needs no tables.
 */
final class OriginalTranslationTestModel extends Model
{
    public function __construct(
        private readonly ?Model $originalTranslation = null,
        private readonly ?Model $defaultTranslation = null,
    ) {
        parent::__construct();
    }

    public function getOriginalTranslation(): ?Model
    {
        return $this->originalTranslation;
    }

    public function getTranslation(string $locale): ?Model
    {
        return $this->defaultTranslation;
    }
}

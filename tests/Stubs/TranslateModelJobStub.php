<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Concerns\HasTranslations;
use Override;

/**
 * Stub model for TranslateModelJob tests.
 * Uses HasTranslations and allows configuring static getTranslatableFields + instance behavior.
 */
final class TranslateModelJobStub extends Model
{
    use HasFactory;
    use HasTranslations;

    public $id = 1;

    /**
     * @var array<string>
     */
    public static array $translatableFields = ['title', 'content'];

    public mixed $defaultTranslation = null;

    public mixed $originalTranslation = null;

    public bool $hasTranslationResult = false;

    /**
     * @var array<array{locale: string, data: array<string, mixed>}>
     */
    public array $setTranslationCalls = [];

    #[Override]
    protected $table = 'test';

    public static function getTranslatableFields(): array
    {
        return self::$translatableFields;
    }

    public function getTranslation(string $locale): mixed
    {
        return $this->defaultTranslation;
    }

    public function getOriginalTranslation(): mixed
    {
        return $this->originalTranslation;
    }

    public function hasTranslation(string $locale): bool
    {
        return $this->hasTranslationResult;
    }

    public function setTranslation(string $locale, array $data): void
    {
        $this->setTranslationCalls[] = ['locale' => $locale, 'data' => $data];
    }

    public function fresh($with = []): ?self
    {
        return $this;
    }

    public function getTable(): string
    {
        return 'test';
    }
}

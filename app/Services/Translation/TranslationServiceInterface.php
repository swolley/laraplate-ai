<?php

declare(strict_types=1);

namespace Modules\AI\Services\Translation;

interface TranslationServiceInterface
{
    /**
     * Translate text from one locale to another.
     */
    public function translate(string $text, string $from_locale, string $to_locale): string;

    /**
     * Translate multiple texts in batch.
     *
     * @param  array<string>  $texts
     * @return array<string>
     */
    public function translateBatch(array $texts, string $from_locale, string $to_locale): array;
}

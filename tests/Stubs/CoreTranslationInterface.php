<?php

declare(strict_types=1);

namespace Modules\Core\Services\Translation;

if (! interface_exists(TranslationServiceInterface::class, false)) {
    interface TranslationServiceInterface
    {
        public function translate(string $text, string $from_locale, string $to_locale): string;

        /**
         * @param  array<string>  $texts  @return array<string>
         */
        public function translateBatch(array $texts, string $from_locale, string $to_locale): array;
    }
}

<?php

declare(strict_types=1);

namespace Modules\Core\Helpers;

use Override;

if (! class_exists(TranslationStubModel::class, false)) {
    class TranslationStubModel extends \Illuminate\Database\Eloquent\Model
    {
        use \Illuminate\Database\Eloquent\Factories\HasFactory;
        use \Illuminate\Database\Eloquent\Factories\HasFactory;

        #[Override]
        protected $table = 'translation_stub';
    }
}

if (! trait_exists(HasTranslations::class, false)) {
    trait HasTranslations
    {
        public array $embed = [];

        public static function getTranslatableFields(): array
        {
            return [];
        }

        public function translations(): \Illuminate\Database\Eloquent\Relations\HasMany
        {
            return $this->hasMany(TranslationStubModel::class);
        }

        public function getTranslation(string $locale): mixed
        {
            return null;
        }

        public function hasTranslation(string $locale): bool
        {
            return false;
        }

        public function setTranslation(string $locale, array $data): void {}
    }
}

if (! class_exists(LocaleContext::class, false)) {
    class LocaleContext
    {
        /**
         * @return string[]
         */
        public static function getAvailable(): array
        {
            return ['en', 'it', 'de'];
        }
    }
}

if (! class_exists(MigrateUtils::class, false)) {
    class MigrateUtils
    {
        public static function timestamps(\Illuminate\Database\Schema\Blueprint $table): void
        {
            $table->timestamps();
        }
    }
}

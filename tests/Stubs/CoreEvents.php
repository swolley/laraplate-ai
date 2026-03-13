<?php

declare(strict_types=1);

namespace Modules\Core\Events;

if (! class_exists(ModelRequiresIndexing::class, false)) {
    class ModelRequiresIndexing
    {
        /**
         * @var string[]
         */
        public array $requiredPreProcessing = [];

        public bool $handled = false;

        public function __construct(
            public readonly \Illuminate\Database\Eloquent\Model $model,
            public readonly bool $sync = false,
        ) {}

        public function addRequiredPreProcessing(string $type): void
        {
            $this->requiredPreProcessing[] = $type;
        }

        public function markAsHandled(): void
        {
            $this->handled = true;
        }
    }
}

if (! class_exists(TranslatedModelSaved::class, false)) {
    class TranslatedModelSaved
    {
        public bool $handled = false;

        /**
         * @param  string[]  $locales
         */
        public function __construct(
            public readonly \Illuminate\Database\Eloquent\Model $model,
            public readonly array $locales = [],
            public readonly bool $force = false,
        ) {}

        public function markAsHandled(): void
        {
            $this->handled = true;
        }
    }
}

if (! class_exists(ModelPreProcessingCompleted::class, false)) {
    class ModelPreProcessingCompleted
    {
        public function __construct(
            public readonly \Illuminate\Database\Eloquent\Model $model,
            public readonly string $type = '',
        ) {}
    }
}

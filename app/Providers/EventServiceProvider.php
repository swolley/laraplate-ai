<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\AI\Listeners\HandleAiTextGenerationListener;
use Modules\AI\Listeners\HandleModelIndexingListener;
use Modules\AI\Listeners\HandleModelTranslationListener;
use Modules\AI\Listeners\HandleModificationApprovedTranslationListener;
use Modules\AI\Listeners\HandleModificationModerationListener;
use Modules\Core\Events\AiTextGenerationRequested;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\ModificationApproved;
use Modules\Core\Events\ModificationRequiresModeration;
use Modules\Core\Events\TranslatedModelSaved;
use Override;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     * AI listeners are registered FIRST to handle events before Core fallback listeners.
     *
     * @var array<string, array<int, string>>
     */
    #[Override]
    protected $listen = [
        ModelRequiresIndexing::class => [
            HandleModelIndexingListener::class,
        ],
        TranslatedModelSaved::class => [
            HandleModelTranslationListener::class,
        ],
        ModificationRequiresModeration::class => [
            HandleModificationModerationListener::class,
        ],
        ModificationApproved::class => [
            HandleModificationApprovedTranslationListener::class,
        ],
        AiTextGenerationRequested::class => [
            HandleAiTextGenerationListener::class,
        ],
    ];

    #[Override]
    protected static $shouldDiscoverEvents = true;
}

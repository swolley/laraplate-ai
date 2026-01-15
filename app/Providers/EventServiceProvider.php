<?php

declare(strict_types=1);

namespace Modules\AI\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\AI\Listeners\HandleModelIndexingListener;
use Modules\AI\Listeners\HandleModelTranslationListener;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\TranslatedModelSaved;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     * AI listeners are registered FIRST to handle events before Core fallback listeners.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        ModelRequiresIndexing::class => [
            HandleModelIndexingListener::class, // Executes first
        ],
        TranslatedModelSaved::class => [
            HandleModelTranslationListener::class,
        ],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}

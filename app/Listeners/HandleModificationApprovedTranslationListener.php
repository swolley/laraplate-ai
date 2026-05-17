<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\Core\Events\ModificationApproved;
use Modules\Core\Helpers\HasTranslations;

final class HandleModificationApprovedTranslationListener
{
    public function handle(ModificationApproved $event): void
    {
        $modifiable = $event->modifiable;

        if (! $modifiable instanceof Model || ! class_uses_trait($modifiable, HasTranslations::class)) {
            return;
        }

        if (! $modifiable->autoTranslateEnabledBySettings()) {
            return;
        }

        if (! config('ai.features.translation.enabled', true)) {
            return;
        }

        dispatch(new TranslateModelJob($modifiable));
    }
}

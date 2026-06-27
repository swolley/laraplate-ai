<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Events\ModificationApproved;
use Modules\Core\Models\Concerns\HasTranslations;

use function ai_config_bool;

final class HandleModificationApprovedTranslationListener
{
    public function handle(ModificationApproved $event): void
    {
        $modifiable = $event->modifiable;

        if (! $this->isTranslatable($modifiable)) {
            return;
        }

        if (! $modifiable->autoTranslateEnabledBySettings()) {
            return;
        }

        if (! ai_config_bool('ai.features.translation.enabled', true)) {
            return;
        }

        dispatch(new TranslateModelJob($modifiable));
    }

    /**
     * @phpstan-assert-if-true ITranslatableModel&Model $model
     */
    private function isTranslatable(Model $model): bool
    {
        return in_array(HasTranslations::class, class_uses_recursive($model), true);
    }
}

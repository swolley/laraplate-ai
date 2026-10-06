<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Services\TranslationGate;
use Modules\Core\Events\ModificationApproved;

final readonly class HandleModificationApprovedTranslationListener
{
    public function __construct(private TranslationGate $gate) {}

    public function handle(ModificationApproved $event): void
    {
        $modifiable = $event->modifiable;

        if (! $this->gate->allows($modifiable)) {
            return;
        }

        dispatch(new TranslateModelJob($modifiable));
    }
}

<?php

declare(strict_types=1);

use Modules\AI\Services\TranslationGate;
use Modules\AI\Tests\Stubs\TranslatableTestModel;
use Modules\Core\Models\Setting;

it('is off while the setting is off, and while it is not there at all', function (): void {
    config()->set('ai.features.translation.enabled', false);
    expect(new TranslationGate()->enabled())->toBeFalse()
        ->and(new TranslationGate()->allows(new TranslatableTestModel))->toBeFalse();

    config()->set('ai.features.translation', []);
    expect(new TranslationGate()->enabled())->toBeFalse();
});

it('keeps a model out of a module that the allowlist leaves out', function (): void {
    config()->set('ai.features.translation.enabled', true);
    config()->set('ai.features.translation.modules', ['cms']);

    expect(new TranslationGate()->allows(new TranslatableTestModel))->toBeFalse();
});

it('does not translate a model that is not translatable', function (): void {
    config()->set('ai.features.translation.enabled', true);

    expect(new TranslationGate()->allows(new Setting))->toBeFalse();
});

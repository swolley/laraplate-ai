<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Listeners\HandleModificationApprovedTranslationListener;
use Modules\AI\Tests\Stubs\TranslatableTestModel;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Events\ModificationApproved;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Setting;
use Modules\Core\Services\PerModelSettingResolver;

beforeEach(function (): void {
    LocaleContext::set('en');
    app(PerModelSettingResolver::class)->flush();
    config(['ai.features.translation.enabled' => true]);
});

it('dispatches translation job when auto translate is enabled for the model', function (): void {
    Bus::fake();

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations.auto.' . (new TranslatableTestModel())->getTable(),
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();

    (app(HandleModificationApprovedTranslationListener::class))->handle(
        new ModificationApproved(new Modification(), new TranslatableTestModel()),
    );

    Bus::assertDispatched(TranslateModelJob::class);
});

it('does not dispatch when auto translate is disabled', function (): void {
    Bus::fake();

    (app(HandleModificationApprovedTranslationListener::class))->handle(
        new ModificationApproved(new Modification(), new TranslatableTestModel()),
    );

    Bus::assertNothingDispatched();
});

it('does not dispatch for non-translatable models', function (): void {
    Bus::fake();

    $user = Modules\Core\Models\User::factory()->create();

    (app(HandleModificationApprovedTranslationListener::class))->handle(
        new ModificationApproved(new Modification(), $user),
    );

    Bus::assertNothingDispatched();
});

it('does not dispatch when the translation feature is disabled', function (): void {
    Bus::fake();
    config(['ai.features.translation.enabled' => false]);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations.auto.' . (new TranslatableTestModel())->getTable(),
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();

    (app(HandleModificationApprovedTranslationListener::class))->handle(
        new ModificationApproved(new Modification(), new TranslatableTestModel()),
    );

    Bus::assertNothingDispatched();
});

it('does not dispatch for a model of a module that the translation allowlist leaves out', function (): void {
    Bus::fake();
    config(['ai.features.translation.modules' => ['cms']]);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations.auto.' . (new TranslatableTestModel())->getTable(),
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();

    (app(HandleModificationApprovedTranslationListener::class))->handle(
        new ModificationApproved(new Modification(), new TranslatableTestModel()),
    );

    Bus::assertNothingDispatched();
});

it('does not dispatch while the translation setting is not there at all, like the seeded default which is off', function (): void {
    Bus::fake();
    config()->set('ai.features.translation', []);

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations.auto.' . (new TranslatableTestModel())->getTable(),
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();

    (app(HandleModificationApprovedTranslationListener::class))->handle(
        new ModificationApproved(new Modification(), new TranslatableTestModel()),
    );

    Bus::assertNothingDispatched();
});

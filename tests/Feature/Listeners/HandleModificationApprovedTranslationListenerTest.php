<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Listeners\HandleModificationApprovedTranslationListener;
use Modules\CMS\Models\Comment;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Events\ModificationApproved;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Modification;
use Modules\Core\Models\Setting;
use Modules\Core\Services\PerModelSettingResolver;

beforeEach(function (): void {
    LocaleContext::set('en');
    app(PerModelSettingResolver::class)->flush();
    $this->content = createMinimalTestContentForComments();
    $this->user = \Modules\Core\Models\User::factory()->create();
    config(['ai.features.translation.enabled' => true]);
});

it('dispatches translation job when auto translate is enabled for comments', function (): void {
    Bus::fake();

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'auto_translate_' . (new Comment())->getTable(),
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();

    $comment = Comment::factory()->approved()->create([
        'content_id' => $this->content->id,
        'user_id' => $this->user->id,
    ]);

    $modification = Modification::query()
        ->where('modifiable_type', Comment::class)
        ->where('modifiable_id', $comment->id)
        ->first();

    (new HandleModificationApprovedTranslationListener())->handle(
        new ModificationApproved($modification ?? new Modification(), $comment),
    );

    Bus::assertDispatched(TranslateModelJob::class);
});

it('does not dispatch when auto translate is disabled', function (): void {
    Bus::fake();

    $comment = Comment::factory()->approved()->create([
        'content_id' => $this->content->id,
        'user_id' => $this->user->id,
    ]);

    (new HandleModificationApprovedTranslationListener())->handle(
        new ModificationApproved(new Modification(), $comment),
    );

    Bus::assertNothingDispatched();
});

<?php

declare(strict_types=1);

use Modules\AI\Jobs\TranslateModelJob;
use Modules\CMS\Models\Comment;
use Modules\CMS\Models\Translations\CommentTranslation;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\User;

beforeEach(function (): void {
    LocaleContext::set('en');
    $this->content = createMinimalTestContentForComments();
    $this->user = User::factory()->create();
});

it('uses chronological original translation as source for comments', function (): void {
    $comment = Comment::factory()->create([
        'content_id' => $this->content->id,
        'user_id' => $this->user->id,
    ]);

    $italian = $comment->translations()->create([
        'locale' => 'it',
        'body' => 'Primo testo',
    ]);
    $italian->created_at = now()->subHour();
    $italian->save();

    $comment->translations()->create([
        'locale' => 'en',
        'body' => 'Later English',
    ]);

    $job = new TranslateModelJob($comment);
    $method = new ReflectionMethod(TranslateModelJob::class, 'resolveSourceTranslation');
    $method->setAccessible(true);

    $source = $method->invoke($job, $comment->fresh(), 'en');

    expect($source)->not->toBeNull()
        ->and($source->locale)->toBe('it')
        ->and($source->body)->toBe('Primo testo');
});

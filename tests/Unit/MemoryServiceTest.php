<?php

declare(strict_types=1);

use Modules\AI\Models\Conversation;
use Modules\AI\Services\MemoryService;

beforeEach(function (): void {
    $this->service = new MemoryService;
});

it('returns false for shouldSummarize when memory is disabled', function (): void {
    $conversation = new Conversation;
    $conversation->memory_enabled = false;

    expect($this->service->shouldSummarize($conversation))->toBeFalse();
});

it('returns false for shouldSummarize when summary feature is disabled', function (): void {
    config()->set('ai.features.chat.enable_summary', false);

    $conversation = new Conversation;
    $conversation->memory_enabled = true;

    expect($this->service->shouldSummarize($conversation))->toBeFalse();
});

it('returns null context when memory is disabled', function (): void {
    $conversation = new Conversation;
    $conversation->memory_enabled = false;
    $conversation->summary = null;

    expect($this->service->getContextForNewMessage($conversation))->toBeNull();
});

it('returns null context when no summary exists', function (): void {
    $conversation = new Conversation;
    $conversation->memory_enabled = true;
    $conversation->summary = null;

    expect($this->service->getContextForNewMessage($conversation))->toBeNull();
});

it('returns context with summary when available', function (): void {
    $conversation = new Conversation;
    $conversation->memory_enabled = true;
    $conversation->summary = 'User discussed project deadlines.';

    $context = $this->service->getContextForNewMessage($conversation);

    expect($context)->toContain('User discussed project deadlines.');
});

<?php

declare(strict_types=1);

use Modules\AI\Http\Requests\ApproveActionRequest;
use Modules\AI\Http\Requests\GenerateSuggestionRequest;
use Modules\AI\Http\Requests\InsertConversationRequest;
use Modules\AI\Http\Requests\ListConversationsRequest;
use Modules\AI\Http\Requests\ListMessagesRequest;
use Modules\AI\Http\Requests\RejectActionRequest;
use Modules\AI\Http\Requests\SendMessageRequest;

it('InsertConversationRequest returns expected rules', function (): void {
    $request = new InsertConversationRequest;
    $request->setContainer(app());
    $request->initialize([]);

    $rules = $request->rules();

    expect($rules)->toHaveKeys(['title', 'system_message', 'metadata'])
        ->and($rules['title'])->toContain('nullable', 'string', 'max:255')
        ->and($rules['system_message'])->toContain('nullable', 'string')
        ->and($rules['metadata'])->toContain('nullable', 'array');
});

it('ListConversationsRequest returns expected rules', function (): void {
    $request = new ListConversationsRequest;
    $request->setContainer(app());
    $request->initialize([]);

    $rules = $request->rules();

    expect($rules)->toHaveKey('per_page')
        ->and($rules['per_page'])->toContain('nullable', 'integer', 'min:1', 'max:100');
});

it('ListMessagesRequest returns expected rules', function (): void {
    $request = new ListMessagesRequest;
    $request->setContainer(app());
    $request->initialize([]);

    $rules = $request->rules();

    expect($rules)->toHaveKey('per_page')
        ->and($rules['per_page'])->toContain('nullable', 'integer', 'min:1', 'max:100');
});

it('SendMessageRequest returns expected rules', function (): void {
    $request = new SendMessageRequest;
    $request->setContainer(app());
    $request->initialize([]);

    $rules = $request->rules();

    expect($rules)->toHaveKeys(['message', 'context'])
        ->and($rules['message'])->toContain('required', 'string')
        ->and($rules['context'])->toContain('nullable', 'array');
});

it('GenerateSuggestionRequest returns expected rules', function (): void {
    $request = new GenerateSuggestionRequest;
    $request->setContainer(app());
    $request->initialize([]);

    $rules = $request->rules();

    expect($rules)->toHaveKeys(['context', 'context.page', 'context.action', 'context.data'])
        ->and($rules['context'])->toContain('required', 'array')
        ->and($rules['context.page'])->toContain('sometimes', 'string', 'max:255')
        ->and($rules['context.action'])->toContain('sometimes', 'string', 'max:255')
        ->and($rules['context.data'])->toContain('sometimes', 'array');
});

it('ApproveActionRequest returns expected rules', function (): void {
    $request = new ApproveActionRequest;
    $request->setContainer(app());
    $request->initialize([]);

    $rules = $request->rules();

    expect($rules)->toHaveKey('reason')
        ->and($rules['reason'])->toContain('sometimes', 'nullable', 'string', 'max:500');
});

it('RejectActionRequest returns expected rules', function (): void {
    $request = new RejectActionRequest;
    $request->setContainer(app());
    $request->initialize([]);

    $rules = $request->rules();

    expect($rules)->toHaveKey('reason')
        ->and($rules['reason'])->toContain('sometimes', 'nullable', 'string', 'max:500');
});

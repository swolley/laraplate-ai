<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\ActionRequestController;
use Modules\AI\Http\Controllers\ChatController;
use Modules\AI\Http\Controllers\SuggestionController;

Route::prefix('crud')->name('crud.')->group(function (): void {
    // Chat routes
    Route::controller(ChatController::class)->group(function (): void {
        Route::get('select/ai/conversations', 'listConversations')->name('conversations.list');
        Route::post('insert/ai/conversations', 'insertConversation')->name('conversations.insert');
        Route::get('detail/ai/conversations/{conversation}', 'detailConversation')->name('conversations.detail');
        Route::delete('delete/ai/conversations/{conversation}', 'deleteConversation')->name('conversations.delete');
        Route::get('select/ai/conversations/{conversation}/messages', 'listMessages')->name('messages.list');
        Route::post('stream/ai/conversations/{conversation}/messages', 'streamMessage')->name('messages.stream');
        Route::post('insert/ai/conversations/{conversation}/messages', 'insertMessage')->name('messages.insert');
        Route::post('insert/ai/conversations/{conversation}/messages-with-tools', 'sendMessageWithTools')->name('messages.with-tools');
    });

    // Action request routes (AI tool execution management)
    Route::controller(ActionRequestController::class)->group(function (): void {
        Route::get('select/ai/action-requests', 'list')->name('action-requests.list');
        Route::get('detail/ai/action-requests/{actionRequest}', 'detail')->name('action-requests.detail');
        Route::post('update/ai/action-requests/{actionRequest}/confirm', 'confirm')->name('action-requests.confirm');
        Route::post('update/ai/action-requests/{actionRequest}/approve', 'approve')->name('action-requests.approve');
        Route::post('update/ai/action-requests/{actionRequest}/reject', 'reject')->name('action-requests.reject');
    });

    // Contextual suggestions routes
    Route::controller(SuggestionController::class)->group(function (): void {
        Route::get('select/ai/suggestions', 'listSuggestions')->name('suggestions.list');
        Route::post('insert/ai/suggestions', 'generateSuggestion')->name('suggestions.generate');
        Route::post('update/ai/suggestions/{suggestion}/dismiss', 'dismissSuggestion')->name('suggestions.dismiss');
    });
});

<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use JsonException;
use Modules\AI\Http\Requests\InsertConversationRequest;
use Modules\AI\Http\Requests\ListConversationsRequest;
use Modules\AI\Http\Requests\ListMessagesRequest;
use Modules\AI\Http\Requests\SendMessageRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\ChatService;
use Modules\Core\Helpers\ResponseBuilder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    /**
     * Create a new conversation.
     */
    public function insertConversation(InsertConversationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $conversation = $this->chatService->createConversation(
            user: Auth::user(),
            title: $validated['title'] ?? null,
            systemMessage: $validated['system_message'] ?? null,
            metadata: $validated['metadata'] ?? null,
        );

        return new ResponseBuilder($request)
            ->setData($conversation->load('messages'))
            ->setStatus(Response::HTTP_CREATED)
            ->json();
    }

    /**
     * List user's conversations.
     */
    public function listConversations(ListConversationsRequest $request): JsonResponse
    {
        $conversations = Conversation::query()->where('user_id', Auth::id())
            ->withCount('messages')
            ->latest()
            ->paginate($request->validated('per_page', 15));

        return new ResponseBuilder($request)
            ->setData($conversations->items())
            ->setTotalRecords($conversations->total())
            ->setCurrentRecords($conversations->count())
            ->setCurrentPage($conversations->currentPage())
            ->setTotalPages($conversations->lastPage())
            ->setPagination($conversations->perPage())
            ->json();
    }

    /**
     * Get conversation details.
     */
    public function detailConversation(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $conversation->load('messages');

        return new ResponseBuilder(request())
            ->setData($conversation)
            ->json();
    }

    /**
     * Get conversation messages.
     */
    public function listMessages(ListMessagesRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $messages = $conversation->messages()
            ->latest()
            ->paginate($request->validated('per_page', 50));

        return new ResponseBuilder($request)
            ->setData($messages->items())
            ->setTotalRecords($messages->total())
            ->setCurrentRecords($messages->count())
            ->setCurrentPage($messages->currentPage())
            ->setTotalPages($messages->lastPage())
            ->setPagination($messages->perPage())
            ->json();
    }

    /**
     * Delete a conversation.
     */
    public function deleteConversation(Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $conversation->delete();

        return new ResponseBuilder(request())
            ->setData(['message' => 'Conversation deleted'])
            ->json();
    }

    /**
     * Send a message in a conversation with streaming response.
     *
     * Note: This method cannot use ResponseBuilder as it requires SSE streaming.
     */
    public function streamMessage(SendMessageRequest $request, Conversation $conversation): StreamedResponse
    {
        $this->authorizeConversationAccess($conversation);

        $validated = $request->validated();

        return new StreamedResponse(function () use ($conversation, $validated): void {
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');

            $on_chunk = static function (string $delta): void {
                try {
                    echo 'data: ' . json_encode([
                        'type' => 'chunk',
                        'content' => $delta,
                    ], JSON_THROW_ON_ERROR) . "\n\n";
                } catch (JsonException) { // @codeCoverageIgnoreStart
                    // in caso di errore di encoding, saltiamo il chunk
                } // @codeCoverageIgnoreEnd

                @ob_flush();
                @flush();
            };

            $this->chatService->sendMessageStream(
                conversation: $conversation,
                user_message: $validated['message'],
                context: $validated['context'] ?? null,
                on_chunk: $on_chunk,
            );

            try {
                echo 'data: ' . json_encode(['type' => 'end'], JSON_THROW_ON_ERROR) . "\n\n";
            } catch (JsonException) { // @codeCoverageIgnoreStart
                // ignore
            } // @codeCoverageIgnoreEnd

            @ob_flush();
            @flush();
        });
    }

    /**
     * Send a message in a conversation (non-streaming).
     */
    public function insertMessage(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $validated = $request->validated();

        $message = $this->chatService->sendMessage(
            conversation: $conversation,
            userMessage: $validated['message'],
            context: $validated['context'] ?? null,
        );

        return new ResponseBuilder($request)
            ->setData([
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->setStatus(Response::HTTP_CREATED)
            ->json();
    }

    /**
     * Send a message with tools support (non-streaming).
     * If the LLM proposes tool calls, ActionRequests are created based on risk level.
     */
    public function sendMessageWithTools(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversationAccess($conversation);

        $validated = $request->validated();

        $result = $this->chatService->sendMessageWithTools(
            conversation: $conversation,
            user_message: $validated['message'],
            context: $validated['context'] ?? null,
        );

        $message = $result['message'];
        $action_requests = $result['action_requests'];

        return new ResponseBuilder($request)
            ->setData([
                'message' => [
                    'id' => $message->id,
                    'role' => $message->role,
                    'content' => $message->content,
                    'metadata' => $message->metadata,
                    'created_at' => $message->created_at?->toIso8601String(),
                ],
                'action_requests' => array_map(static fn (\Modules\AI\Models\ActionRequest $ar): array => [
                    'id' => $ar->id,
                    'tool_name' => $ar->tool_name,
                    'tool_args' => $ar->tool_args,
                    'risk_level' => $ar->risk_level,
                    'status' => $ar->status,
                    'result' => $ar->result,
                    'error' => $ar->error,
                    'created_at' => $ar->created_at?->toIso8601String(),
                ], $action_requests),
            ])
            ->setStatus(Response::HTTP_CREATED)
            ->json();
    }

    /**
     * Verify that the authenticated user owns the conversation.
     */
    private function authorizeConversationAccess(Conversation $conversation): void
    {
        abort_if($conversation->user_id !== Auth::id(), Response::HTTP_FORBIDDEN, 'You do not have access to this conversation.');
    }
}

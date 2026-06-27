<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Contracts\IChatService;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Tools\ToolRegistry;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\UserMessage;
use Override;

class ChatService implements IChatService
{
    /**
     * Create a new conversation for a user.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    #[Override]
    public function createConversation(
        \Modules\Core\Models\User $user,
        ?string $title = null,
        ?string $systemMessage = null,
        ?array $metadata = null,
    ): Conversation {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'title' => $title,
            'system_message' => $systemMessage,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Send a message in a conversation and get AI response.
     *
     * @param  array<string, mixed>|null  $context
     */
    #[Override]
    public function sendMessage(
        Conversation $conversation,
        string $userMessage,
        ?array $context = null,
    ): Message {
        $userMessage = $this->applyInputGuardrails($userMessage);
        $conversation->addMessage('user', $userMessage, $context);

        try {
            $use_rag = $context['use_rag'] ?? false;
            $should_use_rag = $use_rag || $this->looksLikeQuestion($userMessage);
            $documentation_service = $this->getDocumentationService();

            if ($should_use_rag && $documentation_service->isAvailable()) {
                $result = $documentation_service->answerQuestion($userMessage);
                $message = $conversation->addMessage('assistant', $result['answer'], [
                    'citations' => $result['citations'],
                ]);
                $this->checkAndCreateSummaryIfNeeded($conversation);

                return $message;
            }

            $agent = $this->buildAgent($conversation);
            $response = $agent->chat(new UserMessage($userMessage))->getMessage();
            $message = $conversation->addMessage('assistant', $this->assistantContent($response->getContent()));
            $this->checkAndCreateSummaryIfNeeded($conversation);

            return $message;
        } catch (Exception $e) {
            Log::error('AI chat error', [
                'error' => $e->getMessage(),
                'conversation_id' => $conversation->id,
                'user_id' => $conversation->user_id,
            ]);

            throw $e;
        }
    }

    /**
     * Send a message with tools support.
     *
     * @param  array<string, mixed>|null  $context
     *
     * @return array{message: Message, action_requests: ActionRequest[]}
     */
    public function sendMessageWithTools(
        Conversation $conversation,
        string $user_message,
        ?array $context = null,
    ): array {
        $user_message = $this->applyInputGuardrails($user_message);
        $conversation->addMessage('user', $user_message, $context);

        try {
            $tool_registry = $this->getToolRegistry();

            if (! config('ai.features.tools.enabled', true) || ! $tool_registry->hasTools()) {
                $agent = $this->buildAgent($conversation);
                $response = $agent->chat(new UserMessage($user_message))->getMessage();

                return [
                    'message' => $conversation->addMessage('assistant', $this->assistantContent($response->getContent())),
                    'action_requests' => [],
                ];
            }

            $action_request_service = $this->getActionRequestService();
            $risk_classifier = resolve(Tools\RiskClassifier::class);
            $pending_requests = [];

            $agent = $this->buildAgent($conversation);
            $neuron_tools = $tool_registry->getAllNeuronToolsWithApproval(
                $conversation,
                $action_request_service,
                $risk_classifier,
                $pending_requests,
            );

            foreach ($neuron_tools as $tool) {
                $agent->addTool($tool);
            }

            $response = $agent->chat(new UserMessage($user_message))->getMessage();
            $response_text = $this->assistantContent($response->getContent());

            if ($pending_requests === []) {
                return [
                    'message' => $conversation->addMessage('assistant', $response_text),
                    'action_requests' => [],
                ];
            }

            // @codeCoverageIgnoreStart
            return [
                'message' => $conversation->addMessage('assistant', $response_text, [
                    'tool_calls' => array_map(fn (ActionRequest $ar): array => [
                        'id' => $ar->id,
                        'tool' => $ar->tool_name,
                        'status' => $ar->status,
                        'risk_level' => $ar->risk_level,
                    ], $pending_requests),
                ]),
                'action_requests' => $pending_requests,
            ];
            // @codeCoverageIgnoreEnd
        } catch (Exception $e) {
            Log::error('AI chat with tools error', [
                'error' => $e->getMessage(),
                'conversation_id' => $conversation->id,
                'user_id' => $conversation->user_id,
            ]);

            throw $e;
        }
    }

    /**
     * Send a message with streaming response.
     *
     * @param  array<string, mixed>|null  $context
     * @param  callable(string): void  $on_chunk
     */
    #[Override]
    public function sendMessageStream(
        Conversation $conversation,
        string $user_message,
        ?array $context,
        callable $on_chunk,
    ): Message {
        $user_message = $this->applyInputGuardrails($user_message);
        $conversation->addMessage('user', $user_message, $context);

        try {
            $agent = $this->buildAgent($conversation);
            $handler = $agent->stream(new UserMessage($user_message));

            $full_response = '';

            foreach ($handler->events() as $chunk) {
                if (! $chunk instanceof TextChunk) {
                    continue;
                }

                $delta = $chunk->content;

                if ($delta === '') {
                    continue;
                }

                $full_response .= $delta;
                $on_chunk($delta);
            }

            return $conversation->addMessage('assistant', $full_response);
        } catch (Exception $e) {
            Log::error('AI chat streaming error', [
                'error' => $e->getMessage(),
                'conversation_id' => $conversation->id,
                'user_id' => $conversation->user_id,
            ]);

            throw $e;
        }
    }

    /**
     * Build a ChatAgent for the given conversation.
     */
    public function buildAgent(Conversation $conversation, ?string $provider = null): ChatAgent
    {
        $system_prompt = $conversation->system_message;

        $memory_context = $this->getMemoryService()->getContextForNewMessage($conversation);

        if ($memory_context !== null) {
            $system_prompt = ($system_prompt ? $system_prompt . "\n\n" : '') . $memory_context;
        }

        $agent = ChatAgent::make($provider ?? config('ai.features.chat.default_provider'), $system_prompt);

        $previous_messages = $conversation->getMessagesForNeuron();

        if ($previous_messages !== []) {
            $agent->chat($previous_messages); // @codeCoverageIgnore
        }

        return $agent;
    }

    /**
     * Resolved from the container; return type is mixed so tests may bind Mockery doubles.
     *
     * @return DocumentationService
     */
    private function getDocumentationService(): mixed
    {
        return resolve(DocumentationService::class);
    }

    private function getToolRegistry(): ToolRegistry
    {
        return resolve(ToolRegistry::class);
    }

    private function getActionRequestService(): ActionRequestService
    {
        return resolve(ActionRequestService::class);
    }

    /**
     * Resolved from the container; return type is mixed so tests may bind Mockery doubles.
     *
     * @return MemoryService
     */
    private function getMemoryService(): mixed
    {
        return resolve(MemoryService::class);
    }

    /**
     * Resolved from the container; return type is mixed so tests may bind Mockery doubles.
     *
     * @return GuardrailsService
     */
    private function getGuardrailsService(): mixed
    {
        return resolve(GuardrailsService::class);
    }

    private function applyInputGuardrails(string $message): string
    {
        if (! config('ai.features.guardrails.enabled', false)) {
            return $message;
        }

        return $this->getGuardrailsService()->checkPromptInjection($message);
    }

    /**
     * Check and create summary if needed after a message is sent.
     */
    private function checkAndCreateSummaryIfNeeded(Conversation $conversation): void
    {
        $memory_service = $this->getMemoryService();

        if (! $memory_service->shouldSummarize($conversation)) {
            return;
        }

        $memory_service->createSummarySnapshot($conversation);
    }

    private function looksLikeQuestion(string $message): bool
    {
        if (! config('ai.features.faq.question_detection.enabled', true)) {
            return false;
        }

        $trimmed = mb_trim($message);

        if (str_ends_with($trimmed, '?')) {
            return true;
        }

        $locale = app()->getLocale();
        $question_words = $this->getQuestionWordsForLocale($locale);
        $lower = mb_strtolower($trimmed);

        return array_any($question_words, fn (string $word): bool => str_starts_with($lower, $word . ' ') || str_contains($lower, ' ' . $word . ' '));
    }

    /**
     * @return list<string>
     */
    private function getQuestionWordsForLocale(string $locale): array
    {
        $configured = config('ai.features.faq.question_detection.words', []);

        if (is_array($configured) && isset($configured[$locale]) && is_array($configured[$locale])) {
            return array_values(array_filter(
                $configured[$locale],
                static fn (mixed $word): bool => is_string($word) && $word !== '',
            ));
        }

        $base_locale = explode('_', $locale)[0];

        return match ($base_locale) {
            'it' => ['cosa', 'come', 'perché', 'quando', 'dove', 'chi', 'quale', 'quali', 'puoi', 'potresti', 'esiste', 'esistono'],
            'de' => ['was', 'wie', 'warum', 'wann', 'wo', 'wer', 'welche', 'welcher', 'kannst', 'könntest', 'gibt es'],
            'es' => ['qué', 'cómo', 'por qué', 'cuándo', 'dónde', 'quién', 'cuál', 'puedes', 'podrías', 'hay'],
            'fr' => ['quoi', 'comment', 'pourquoi', 'quand', 'où', 'qui', 'quel', 'quelle', 'peux-tu', 'pourrais-tu', 'y a-t-il'],
            default => ['what', 'how', 'why', 'when', 'where', 'who', 'which', 'can you', 'could you', 'is there', 'are there'],
        };
    }

    private function assistantContent(?string $content): string
    {
        return $content ?? '';
    }
}

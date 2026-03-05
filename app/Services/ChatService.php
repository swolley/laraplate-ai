<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use LLPhant\Chat\AnthropicChat;
use LLPhant\Chat\FunctionInfo\FunctionInfo;
use LLPhant\Chat\MistralAIChat;
use LLPhant\Chat\OllamaChat;
use LLPhant\Chat\OpenAIChat;
use LLPhant\MistralAIConfig;
use LLPhant\OllamaConfig;
use LLPhant\OpenAIConfig;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\Models\User;

final class ChatService
{
    /**
     * Create a new conversation for a user.
     */
    public function createConversation(
        User $user,
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
     * If FAQ/RAG is enabled and message is a question (heuristic or context flag), uses QuestionAnswering and adds citations to metadata.
     */
    public function sendMessage(
        Conversation $conversation,
        string $userMessage,
        ?array $context = null,
    ): Message {
        // Apply input guardrails (prompt injection detection)
        $userMessage = $this->applyInputGuardrails($userMessage);

        $conversation->addMessage('user', $userMessage, $context);

        try {
            $chat = $this->getChatInstance();

            if ($conversation->system_message) {
                $chat->setSystemMessage($conversation->system_message);
            }

            $use_rag = $context['use_rag'] ?? false;
            $should_use_rag = $use_rag || $this->looksLikeQuestion($userMessage);
            $documentation_service = $this->getDocumentationService();

            if ($should_use_rag && $documentation_service->isAvailable()) {
                $result = $documentation_service->answerQuestion($userMessage, $chat);
                $message = $conversation->addMessage('assistant', $result['answer'], [
                    'citations' => $result['citations'],
                ]);
                $this->checkAndCreateSummaryIfNeeded($conversation, $chat);

                return $message;
            }

            $response = $chat->generateText($userMessage);
            $message = $conversation->addMessage('assistant', $response);
            $this->checkAndCreateSummaryIfNeeded($conversation, $chat);

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
     * If LLM proposes tool calls, ActionRequests are created based on risk level.
     *
     * @return array{message: Message, action_requests: ActionRequest[]}
     */
    public function sendMessageWithTools(
        Conversation $conversation,
        string $user_message,
        ?array $context = null,
    ): array {
        // Apply input guardrails (prompt injection detection)
        $user_message = $this->applyInputGuardrails($user_message);

        $conversation->addMessage('user', $user_message, $context);

        try {
            $chat = $this->getChatInstance();

            if ($conversation->system_message) {
                $chat->setSystemMessage($conversation->system_message);
            }

            $tool_registry = $this->getToolRegistry();
            $action_request_service = $this->getActionRequestService();

            if (! config('ai.features.tools.enabled', true) || ! $tool_registry->hasTools()) {
                $response = $chat->generateText($user_message);

                return [
                    'message' => $conversation->addMessage('assistant', $response),
                    'action_requests' => [],
                ];
            }

            // Set tools on chat instance (only OpenAI supports this well)
            if ($chat instanceof OpenAIChat) {
                $chat->setTools($tool_registry->getAllToolsAsFunctionInfo());
            }

            // Use generateTextOrReturnFunctionCalled which returns FunctionInfo[] without executing
            $result = $chat->generateTextOrReturnFunctionCalled($user_message);

            // If string response, no tool was called
            if (is_string($result)) {
                return [
                    'message' => $conversation->addMessage('assistant', $result),
                    'action_requests' => [],
                ];
            }

            // LLM proposed tool calls - create ActionRequests
            $action_requests = [];
            $tool_summaries = [];

            /** @var FunctionInfo $function_info */
            foreach ($result as $function_info) {
                $tool_name = $function_info->name;
                $tool_args = $tool_registry->parseToolArguments($function_info);
                $definition = $tool_registry->getTool($tool_name);

                $action_request = $action_request_service->createRequest(
                    $conversation->user,
                    $tool_name,
                    $tool_args,
                    $conversation,
                );

                $action_requests[] = $action_request;
                $tool_summaries[] = $this->formatToolSummary($action_request, $definition);
            }

            // Create assistant message describing the tool calls
            $message_content = $this->buildToolCallsMessage($tool_summaries);

            return [
                'message' => $conversation->addMessage('assistant', $message_content, [
                    'tool_calls' => array_map(fn (ActionRequest $ar): array => [
                        'id' => $ar->id,
                        'tool' => $ar->tool_name,
                        'status' => $ar->status,
                        'risk_level' => $ar->risk_level,
                    ], $action_requests),
                ]),
                'action_requests' => $action_requests,
            ];
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
     * Get chat instance based on configured provider.
     */
    public function getChatInstance(?string $provider = null): OpenAIChat|OllamaChat|MistralAIChat|AnthropicChat
    {
        $provider = $provider ?: config('ai.features.chat.default_provider');

        return match ($provider) {
            'openai' => $this->createOpenAIChat(),
            'ollama' => $this->createOllamaChat(),
            'mistral' => $this->createMistralAIChat(),
            'anthropic' => $this->createAnthropicChat(),
            default => throw new Exception("Unsupported chat provider: {$provider}"),
        };
    }

    /**
     * Format conversation messages for LLPhant.
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function formatMessagesForLLPhant(Conversation $conversation): array
    {
        return $conversation->getMessagesForLLPhant();
    }

    /**
     * Send a message with streaming response.
     *
     * @param  callable(string): void  $on_chunk
     */
    public function sendMessageStream(
        Conversation $conversation,
        string $user_message,
        ?array $context,
        callable $on_chunk,
    ): Message {
        $conversation->addMessage('user', $user_message, $context);

        try {
            $chat = $this->getChatInstance();

            if ($conversation->system_message) {
                $chat->setSystemMessage($conversation->system_message);
            }

            $full_response = '';

            // LLPhant: genera testo a stream (controlla il nome esatto del metodo nella tua versione, es. generateStreamOfText)
            foreach ($chat->generateStreamOfText($user_message) as $chunk) {
                $delta = (string) $chunk;

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
     * Format a summary for a tool call based on its status.
     */
    private function formatToolSummary(ActionRequest $request, ?Tools\ToolDefinition $definition): string
    {
        $tool_desc = $definition?->description ?? $request->tool_name;

        return match ($request->status) {
            'completed' => "✅ Executed: {$tool_desc}",
            'approved', 'executing' => "⏳ Executing: {$tool_desc}",
            'pending_user_confirmation' => "⚠️ Requires your confirmation: {$tool_desc}",
            'pending_admin_approval' => "🔒 Requires admin approval: {$tool_desc}",
            default => "📋 Requested: {$tool_desc}",
        };
    }

    /**
     * Build the assistant message content for tool calls.
     */
    private function buildToolCallsMessage(array $tool_summaries): string
    {
        if (count($tool_summaries) === 1) {
            return $tool_summaries[0];
        }

        return "I'm processing the following actions:\n\n" . implode("\n", $tool_summaries);
    }

    /**
     * Resolve DocumentationService lazily.
     */
    private function getDocumentationService(): DocumentationService
    {
        return resolve(DocumentationService::class);
    }

    /**
     * Resolve ToolRegistry lazily.
     */
    private function getToolRegistry(): ToolRegistry
    {
        return resolve(ToolRegistry::class);
    }

    /**
     * Resolve ActionRequestService lazily.
     */
    private function getActionRequestService(): ActionRequestService
    {
        return resolve(ActionRequestService::class);
    }

    /**
     * Resolve MemoryService lazily.
     */
    private function getMemoryService(): MemoryService
    {
        return resolve(MemoryService::class);
    }

    /**
     * Resolve GuardrailsService lazily.
     */
    private function getGuardrailsService(): GuardrailsService
    {
        return resolve(GuardrailsService::class);
    }

    /**
     * Apply input guardrails (prompt injection detection).
     *
     * @throws \LLPhant\Exception\SecurityException
     */
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
    private function checkAndCreateSummaryIfNeeded(Conversation $conversation, OpenAIChat|OllamaChat|MistralAIChat|AnthropicChat $chat): void
    {
        $memory_service = $this->getMemoryService();

        if ($memory_service->shouldSummarize($conversation)) {
            $memory_service->createSummarySnapshot($conversation, $chat);
        }
    }

    /**
     * Check if message looks like a question based on locale-aware heuristics.
     * Configurable via ai.features.faq.question_detection.
     */
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

        return array_any($question_words, fn ($word): bool => str_starts_with($lower, $word . ' ') || str_contains($lower, ' ' . $word . ' '));
    }

    /**
     * Get question words for a given locale.
     *
     * @return string[]
     */
    private function getQuestionWordsForLocale(string $locale): array
    {
        $configured = config('ai.features.faq.question_detection.words', []);

        if (isset($configured[$locale])) {
            return $configured[$locale];
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

    /**
     * Create OpenAI chat instance.
     */
    private function createOpenAIChat(): OpenAIChat
    {
        $api_key = config('ai.providers.openai.api_key');
        $model = config('ai.providers.openai.model', 'gpt-3.5-turbo');

        throw_if(empty($api_key), Exception::class, 'OpenAI API key is not configured');

        $config = new OpenAIConfig($api_key);

        if (config('ai.providers.openai.api_url')) {
            $config->url = config('ai.providers.openai.api_url');
        }

        $chat = new OpenAIChat($config);

        if ($model) {
            // Note: LLPhant OpenAIChat might need model set differently
            // Check LLPhant docs for exact API
        }

        return $chat;
    }

    /**
     * Create Ollama chat instance.
     */
    private function createOllamaChat(): OllamaChat
    {
        $config = new OllamaConfig();
        $config->model = config('ai.providers.ollama.model', 'llama3.2:3b');

        if (config('ai.providers.ollama.api_url')) {
            $config->url = config('ai.providers.ollama.api_url');
        }

        return new OllamaChat($config);
    }

    /**
     * Create Mistral AI chat instance.
     */
    private function createMistralAIChat(): MistralAIChat
    {
        $api_key = config('ai.providers.mistral.api_key');
        $model = config('ai.providers.mistral.model', 'mistral-large-latest');

        throw_if(empty($api_key), Exception::class, 'Mistral API key is not configured');

        $config = new MistralAIConfig($api_key);

        if ($model) {
            $config->model = $model;
        }

        return new MistralAIChat($config);
    }

    /**
     * Create Anthropic chat instance.
     */
    private function createAnthropicChat(): AnthropicChat
    {
        // Anthropic uses ANTHROPIC_API_KEY env var by default
        // LLPhant AnthropicChat can be instantiated without config if env var is set
        return new AnthropicChat();
    }
}

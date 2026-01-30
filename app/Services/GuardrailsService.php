<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use JsonException;
use LLPhant\Chat\ChatInterface;
use LLPhant\Evaluation\Guardrails\Guardrails;
use LLPhant\Evaluation\Guardrails\GuardrailStrategy;
use LLPhant\Evaluation\Output\JSONFormatEvaluator;
use LLPhant\Evaluation\Output\NoFallbackAnswerEvaluator;
use LLPhant\Exception\SecurityException;
use LLPhant\Query\SemanticSearch\LakeraPromptInjectionQueryTransformer;

/**
 * Service for applying guardrails to AI chat interactions.
 *
 * Supports:
 * - Prompt injection detection (via Lakera API)
 * - JSON format validation
 * - No fallback answer detection
 * - Retry strategy for failed validations
 */
final class GuardrailsService
{
    /**
     * Check input for prompt injection using Lakera API.
     *
     * @throws SecurityException If prompt injection is detected
     */
    public function checkPromptInjection(string $input): string
    {
        if (! config('ai.features.guardrails.prompt_injection_detection', false)) {
            return $input;
        }

        $api_key = config('ai.features.guardrails.lakera_api_key');

        if ($api_key === null || $api_key === '') {
            return $input;
        }

        $transformer = new LakeraPromptInjectionQueryTransformer(
            endpoint: config('ai.features.guardrails.lakera_endpoint', 'https://api.lakera.ai/'),
            apiKey: $api_key,
        );

        // This throws SecurityException if injection detected
        $transformer->transformQuery($input);

        return $input;
    }

    /**
     * Generate text with guardrails applied.
     */
    public function generateWithGuardrails(
        ChatInterface $chat,
        string $message,
        bool $require_json = false,
        bool $block_fallback = false,
    ): string {
        if (! config('ai.features.guardrails.enabled', false)) {
            return $chat->generateText($message);
        }

        $guardrails = new Guardrails($chat);

        if ($require_json) {
            $guardrails->addStrategy(
                new JSONFormatEvaluator,
                GuardrailStrategy::STRATEGY_RETRY,
                null,
                '{"error": "Unable to generate valid JSON response"}',
            );
        }

        if ($block_fallback) {
            $guardrails->addStrategy(
                new NoFallbackAnswerEvaluator,
                GuardrailStrategy::STRATEGY_BLOCK,
                null,
                "I don't have enough information to answer this question.",
            );
        }

        return $guardrails->generateText($message);
    }

    /**
     * Wrap a chat instance with guardrails for prompt injection detection.
     */
    public function wrapWithInputValidation(ChatInterface $chat, string $input): string
    {
        // First check for prompt injection
        $this->checkPromptInjection($input);

        // Then generate response
        return $chat->generateText($input);
    }

    /**
     * Validate that output is valid JSON.
     */
    public function validateJsonOutput(string $output): bool
    {
        try {
            json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            return true;
        } catch (JsonException) {
            return false;
        }
    }
}

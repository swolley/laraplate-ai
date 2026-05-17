<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Data\ModerationResult;
use Modules\AI\Enums\ModerationVerdict;
use Modules\Core\Data\ModerationRequest;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;

final readonly class ModerationService
{
    /**
     * @param  Closure(): ChatAgent|null  $chatAgentFactory
     */
    public function __construct(
        private GuardrailsService $guardrails,
        private ?Closure $chatAgentFactory = null,
    ) {}

    public function analyze(ModerationRequest $request): ModerationResult
    {
        if (mb_trim($request->input->subjectText) === '') {
            return new ModerationResult(
                verdict: ModerationVerdict::Reject,
                confidence: 1.0,
                categories: ['incoherent'],
                reason: 'Subject text is empty.',
                safeToAutoApprove: false,
            );
        }

        try {
            $agent = $this->createAgent($request);
            $response = $agent->chat(new UserMessage($request->userPrompt))->getMessage();
            $content = (string) $response->getContent();

            if (! $this->guardrails->validateJsonOutput($content)) {
                $content = $this->retryJson($agent, $request) ?? $content;
            }

            return $this->mapResponse($content);
        } catch (Throwable) {
            return new ModerationResult(
                verdict: ModerationVerdict::Uncertain,
                confidence: 0.0,
                categories: [],
                reason: 'Moderation service unavailable; human review required.',
                safeToAutoApprove: false,
            );
        }
    }

    private function createAgent(ModerationRequest $request): ChatAgent
    {
        $factory = $this->chatAgentFactory;

        if ($factory !== null) {
            $agent = $factory();

            if ($agent instanceof ChatAgent) {
                return $agent;
            }
        }

        $provider = (string) config(
            'ai.features.moderation.provider',
            config('ai.features.chat.default_provider', 'ollama'),
        );

        return ChatAgent::make($provider, $request->systemPrompt);
    }

    private function retryJson(ChatAgent $agent, ModerationRequest $request): ?string
    {
        if (! config('ai.features.guardrails.retry_on_failure', true)) {
            return null;
        }

        try {
            $response = $agent->chat(new UserMessage(
                $request->userPrompt . "\n\nRespond with valid JSON only.",
            ))->getMessage();

            $content = (string) $response->getContent();

            return $this->guardrails->validateJsonOutput($content) ? $content : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function mapResponse(string $content): ModerationResult
    {
        $parsed = $this->parseJson($content);

        $verdict = ModerationVerdict::tryFromString($parsed['verdict'] ?? null);
        $confidence = (float) ($parsed['confidence'] ?? 0.0);
        $categories = is_array($parsed['categories'] ?? null) ? array_values(array_map(strval(...), $parsed['categories'])) : [];
        $reason = (string) ($parsed['reason'] ?? 'No reason provided.');
        $safe = (bool) ($parsed['safe_to_auto_approve'] ?? false);

        return new ModerationResult(
            verdict: $verdict,
            confidence: max(0.0, min(1.0, $confidence)),
            categories: $categories,
            reason: $reason,
            safeToAutoApprove: $safe,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJson(string $content): array
    {
        $cleaned = $content;

        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $cleaned, $matches)) {
            $cleaned = $matches[1];
        }

        $decoded = json_decode(mb_trim($cleaned), true);

        return is_array($decoded) ? $decoded : [];
    }
}

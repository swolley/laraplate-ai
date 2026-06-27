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

use function ai_config_bool;
use function ai_config_string;

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
            $content = $response->getContent() ?? '';

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

            return $agent;
        }

        $provider = ai_config_string(
            'ai.features.moderation.provider',
            ai_config_string('ai.features.chat.default_provider', 'ollama'),
        );

        return ChatAgent::make($provider, $request->systemPrompt);
    }

    private function retryJson(ChatAgent $agent, ModerationRequest $request): ?string
    {
        if (! ai_config_bool('ai.features.guardrails.retry_on_failure', true)) {
            return null;
        }

        try {
            $response = $agent->chat(new UserMessage(
                $request->userPrompt . "\n\nRespond with valid JSON only.",
            ))->getMessage();

            $content = $response->getContent() ?? '';

            return $this->guardrails->validateJsonOutput($content) ? $content : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function mapResponse(string $content): ModerationResult
    {
        $parsed = $this->parseJson($content);

        $verdict = ModerationVerdict::tryFromString($this->stringValue($parsed, 'verdict'));
        $confidence = $this->floatValue($parsed, 'confidence');
        $categories = $this->stringListValue($parsed, 'categories');
        $reason = $this->stringValue($parsed, 'reason', 'No reason provided.');
        $safe = $this->boolValue($parsed, 'safe_to_auto_approve');

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

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function stringValue(array $parsed, string $key, ?string $default = null): string
    {
        $value = $parsed[$key] ?? $default;

        if (is_string($value)) {
            return $value;
        }

        if ($value === null) {
            return $default ?? '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return $default ?? '';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function floatValue(array $parsed, string $key): float
    {
        $value = $parsed[$key] ?? 0.0;

        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function boolValue(array $parsed, string $key): bool
    {
        $value = $parsed[$key] ?? false;

        return is_bool($value) ? $value : false;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<string>
     */
    private function stringListValue(array $parsed, string $key): array
    {
        $value = $parsed[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $items[] = $item;

                continue;
            }

            if (is_scalar($item)) {
                $items[] = (string) $item;
            }
        }

        return $items;
    }
}

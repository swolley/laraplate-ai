<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Data\ModerationResult;
use Modules\AI\Data\ModerationVerdictData;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Enums\ModerationVerdict;
use Modules\Core\Data\ModerationRequest;
use NeuronAI\Chat\Messages\UserMessage;
use Throwable;
use UnexpectedValueException;

/**
 * Moderates a text with the model chosen in Settings for the feature. The model answers through
 * Neuron's structured output ({@see ModerationVerdictData}); the prompt, owned by the module that asks,
 * describes the JSON, and Neuron adds the schema and asks again when the answer does not fit it.
 */
final readonly class ModerationService
{
    /**
     * Times Neuron asks the model again, with what was wrong, when the verdict is not valid.
     */
    public const int MAX_RETRIES = 1;

    /**
     * @param  Closure(): ChatAgent|null  $chatAgentFactory
     */
    public function __construct(
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
            $output = $this->createAgent($request)->structured(
                new UserMessage($request->userPrompt),
                ModerationVerdictData::class,
                self::MAX_RETRIES,
            );

            if (! $output instanceof ModerationVerdictData) {
                throw new UnexpectedValueException('The model returned no verdict.');
            }

            return $output->toResult();
        } catch (Throwable) {
            // A provider that fails and a verdict that never validates end the same way: a person decides.
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
            return $factory();
        }

        return ChatAgent::forFeature(AiModelFeature::Moderation, $request->systemPrompt);
    }
}

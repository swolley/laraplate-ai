<?php

declare(strict_types=1);

namespace Modules\AI\Data;

use Modules\AI\Enums\ModerationVerdict;
use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Rules\OutOfRange;

/**
 * The structured output the model is asked for when it moderates a text. Neuron sends the schema to
 * the model, deserializes the answer (a verdict outside the enum fails there) and validates it against
 * the rules below; when either step fails it asks the model again with what was wrong.
 *
 * The property names are the keys of the JSON that the moderation prompts describe.
 */
final class ModerationVerdictData
{
    #[SchemaProperty(description: 'approve only if clearly acceptable, reject only if clearly violating, otherwise uncertain.', required: true)]
    public ModerationVerdict $verdict;

    #[SchemaProperty(description: 'How certain the verdict is, from 0.0 to 1.0.', required: true, min: 0, max: 1)]
    #[OutOfRange(min: 0.0, max: 1.0)]
    public float $confidence;

    /**
     * @var list<string>
     */
    #[SchemaProperty(description: 'Zero or more categories of the violation, as the prompt lists them.', required: true)]
    public array $categories = [];

    #[SchemaProperty(description: 'One or two sentences for the moderators.', required: true)]
    #[NotBlank]
    public string $reason;

    #[SchemaProperty(description: 'True only when the text would be approved with high certainty and no human review.', required: true)]
    public bool $safe_to_auto_approve = false;

    public function toResult(): ModerationResult
    {
        return new ModerationResult(
            verdict: $this->verdict,
            confidence: $this->confidence,
            categories: array_values(array_filter($this->categories, is_string(...))),
            reason: $this->reason,
            safeToAutoApprove: $this->safe_to_auto_approve,
        );
    }
}

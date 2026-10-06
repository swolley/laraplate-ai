<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag\Retrieval;

use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\FixedThresholdPostProcessor;

/**
 * The `ai.features.faq.min_similarity` threshold as Neuron's own post-processor, so that every
 * documentation path (the agent behind `ai:help`, the developer index, the in-app index) drops the
 * same documents.
 */
final class MinimumSimilarity
{
    /**
     * Null when no threshold is set: a non-numeric value means the setting is malformed, and no
     * filtering is the safe reading.
     */
    public static function postProcessor(): ?FixedThresholdPostProcessor
    {
        $configured = config('ai.features.faq.min_similarity', 0.0);
        $threshold = is_numeric($configured) ? (float) $configured : 0.0;

        return $threshold > 0.0 ? new FixedThresholdPostProcessor($threshold) : null;
    }

    /**
     * @param  list<Document>  $documents
     * @return list<Document>
     */
    public static function filter(array $documents): array
    {
        $processor = self::postProcessor();

        return $processor === null ? $documents : array_values($processor->process(new UserMessage(''), $documents));
    }
}

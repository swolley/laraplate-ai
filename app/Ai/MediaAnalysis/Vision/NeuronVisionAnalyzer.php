<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis\Vision;

use Closure;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use Modules\AI\Data\ImageAnalysisData;
use Modules\AI\Exceptions\MediaAnalysisException;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Vision analysis via neuron-ai (M21): sends the image to the profile's provider/model (neuron-ai's
 * Anthropic MessageMapper maps {@see ImageContent} to Claude image blocks) and reads the result
 * through Neuron's structured output ({@see ImageAnalysisData}). Requires a configured provider/key.
 *
 * Only an unreadable file yields an empty result. A provider error or an answer that never fits the
 * schema throws, so {@see \Modules\AI\Jobs\AnalyzeMediaJob} retries; once its retries are spent the
 * job marks the analysis failed and still lets the media finalize on the deterministic layer (M12).
 */
final readonly class NeuronVisionAnalyzer implements MediaVisionAnalyzer
{
    /**
     * Times Neuron asks the model again, with what was wrong, when the answer does not fit the schema.
     */
    public const int MAX_RETRIES = 1;

    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You analyze a single image for a media library: describe what is in it, what it conveys and
        what text it shows.
        PROMPT;

    /**
     * @param  (Closure(MediaAnalysisModelProfile): ChatAgent)|null  $chatAgentFactory  builds the agent for a profile
     */
    public function __construct(
        private ?Closure $chatAgentFactory = null,
    ) {}

    /**
     * @throws MediaAnalysisException when the model answers with no analysis
     */
    public function analyze(string $path, string $mimeType, MediaAnalysisModelProfile $profile): MediaVisionResult
    {
        $data = @file_get_contents($path);

        if ($data === false) {
            return MediaVisionResult::empty();
        }

        $message = new UserMessage([
            new TextContent('Analyze this image.'),
            new ImageContent(base64_encode($data), SourceType::BASE64, $mimeType),
        ]);

        $agent = $this->chatAgentFactory instanceof Closure
            ? ($this->chatAgentFactory)($profile)
            : ChatAgent::make($profile->provider, self::SYSTEM_PROMPT, $profile->serviceModel);
        $output = $agent->structured($message, ImageAnalysisData::class, self::MAX_RETRIES);

        if (! $output instanceof ImageAnalysisData) {
            throw new MediaAnalysisException('The vision model did not answer with an analysis.');
        }

        return $output->toResult();
    }
}

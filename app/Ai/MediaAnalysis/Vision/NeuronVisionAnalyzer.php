<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis\Vision;

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use Modules\AI\Exceptions\MediaAnalysisException;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\ContentBlocks\TextContent;
use NeuronAI\Chat\Messages\UserMessage;

/**
 * Vision analysis via neuron-ai (M21): sends the image plus a JSON-constrained
 * prompt to the profile's provider/model (neuron-ai's Anthropic MessageMapper
 * maps {@see ImageContent} to Claude image blocks) and parses the structured
 * result. Requires a configured provider/key.
 *
 * Only an unreadable file yields an empty result. A provider error or an answer
 * that is not the expected JSON throws, so {@see \Modules\AI\Jobs\AnalyzeMediaJob}
 * retries; once its retries are spent the job marks the analysis failed and still
 * lets the media finalize on the deterministic layer (M12).
 */
final class NeuronVisionAnalyzer implements MediaVisionAnalyzer
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        You analyze a single image for a media library. Reply with STRICT JSON only,
        no prose, matching exactly:
        {"caption": string, "entities": string[], "idea": string, "intent": string, "ocr_text": string}
        - caption: one sentence describing subjects AND actions (e.g. "a man smiling outdoors").
        - entities: concrete subjects/objects visible (e.g. ["man","tree"]).
        - idea: the central concept the image conveys.
        - intent: the communicative purpose.
        - ocr_text: text visible in the image, or "" if none.
        PROMPT;

    /**
     * @codeCoverageIgnore Exercised only against a live provider; unit tests use a fake.
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

        $agent = ChatAgent::make($profile->provider, self::SYSTEM_PROMPT, $profile->serviceModel);
        $content = (string) ($agent->chat($message)->getMessage()->getContent() ?? '');

        return self::parse($content);
    }

    /**
     * @throws MediaAnalysisException when the answer is not a JSON object, even once a Markdown code fence is removed
     */
    private static function parse(string $content): MediaVisionResult
    {
        $json = json_decode(self::withoutCodeFence($content), true);

        if (! is_array($json)) {
            throw new MediaAnalysisException('The vision model did not answer with the expected JSON.');
        }

        $entities = [];

        if (isset($json['entities']) && is_array($json['entities'])) {
            foreach ($json['entities'] as $entity) {
                if (is_string($entity) && $entity !== '') {
                    $entities[] = $entity;
                }
            }
        }

        return new MediaVisionResult(
            caption: self::nullableString($json['caption'] ?? null),
            entities: $entities,
            idea: self::nullableString($json['idea'] ?? null),
            intent: self::nullableString($json['intent'] ?? null),
            ocrText: self::nullableString($json['ocr_text'] ?? null),
        );
    }

    /**
     * Models often wrap JSON in a Markdown fence (```json … ```) despite being told not to.
     */
    private static function withoutCodeFence(string $content): string
    {
        $trimmed = mb_trim($content);

        if (preg_match('/^```[a-zA-Z]*\s*(.*?)\s*```$/s', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = mb_trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

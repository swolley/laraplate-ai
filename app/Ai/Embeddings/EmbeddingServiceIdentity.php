<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use function ai_config_int;
use function ai_config_nullable_string;
use function ai_config_string;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Which model the sentence-transformers service really runs. `/health` echoes the model the service
 * was started with; the answer to a probe on `/embed` names the model that embedded it, which is
 * authoritative because a multi-model service answers for the model the request asked for. Shared by
 * `ai:embeddings:repair` and `ai:embeddings:switch`, which word their own messages.
 */
final readonly class EmbeddingServiceIdentity
{
    /**
     * The model to check and where it comes from: the probe's answer first, `/health` as fallback;
     * null when neither names one.
     *
     * @return array{model: string, source: string}|null
     */
    public static function reported(?string $probeModel, ?string $healthModel): ?array
    {
        if ($probeModel !== null) {
            return ['model' => $probeModel, 'source' => '/embed'];
        }

        if ($healthModel !== null) {
            return ['model' => $healthModel, 'source' => '/health'];
        }

        return null;
    }

    public function url(): string
    {
        return mb_rtrim(ai_config_string('ai.providers.sentence_transformers.url', 'http://localhost:8000'), '/');
    }

    /**
     * The model `/health` reports, or null when it names none.
     *
     * @throws Throwable when the service cannot be reached or answers an error
     */
    public function healthModel(): ?string
    {
        $response = Http::timeout(5)->get($this->url() . '/health');
        $response->throw();

        return self::modelName($response->json('model'));
    }

    /**
     * Embeds the probe text with the payload the jobs send and checks the vector against the
     * profile's dimensions.
     *
     *
     * @throws EmbeddingDimensionMismatch when the answer carries no vector or one of another length
     * @throws Throwable when the service cannot be reached or answers an error
     *
     * @return string|null the model the answer names, or null when it names none
     */
    public function probeModel(EmbeddingModelProfile $profile): ?string
    {
        $api_key = ai_config_nullable_string('ai.providers.sentence_transformers.api_key');
        $request = Http::timeout(ai_config_int('ai.providers.sentence_transformers.timeout', 30))->acceptJson();

        if ($api_key !== null && $api_key !== '') {
            $request = $request->withToken($api_key);
        }

        $response = $request->post($this->url() . '/embed', [
            'texts' => [EmbeddingDimensionProbe::TEXT],
            'truncation' => true,
            'normalize_embeddings' => true,
            'max_length' => 512,
            'model' => $profile->serviceModel,
        ]);
        $response->throw();

        EmbeddingDimensionProbe::assertDimensions($profile, $response->json('embeddings.0'));

        return self::modelName($response->json('model'));
    }

    private static function modelName(mixed $model): ?string
    {
        return is_string($model) && $model !== '' ? $model : null;
    }
}

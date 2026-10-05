<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Closure;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Measures the vector length a profile's model really produces by embedding a fixed text with the
 * profile's own provider and service model, so a declared `dimensions` can be checked before any
 * vector is stored under it.
 */
final readonly class EmbeddingDimensionProbe
{
    public const string TEXT = 'embedding service probe';

    /**
     * @param  (Closure(): EmbeddingsProviderInterface)|null  $providerFactory  builds the provider for the profile that is active while it runs; defaults to the application factory
     */
    public function __construct(
        private EmbeddingModelRegistry $registry,
        private ?Closure $providerFactory = null,
    ) {}

    /**
     * Checks a vector obtained elsewhere (e.g. by a caller that also needs the service's answer)
     * against the dimensions the profile declares, and returns its length.
     *
     * @throws EmbeddingDimensionMismatch when the vector is missing, empty or of another length
     */
    public static function assertDimensions(EmbeddingModelProfile $profile, mixed $vector): int
    {
        $measured = self::lengthOf($profile, $vector);

        if ($measured !== $profile->dimensions) {
            throw EmbeddingDimensionMismatch::differs($profile, $measured);
        }

        return $measured;
    }

    /**
     * The length of the vector the profile's model returns for the probe text.
     *
     * @throws EmbeddingDimensionMismatch when the model returns no vector
     */
    public function measure(EmbeddingModelProfile $profile): int
    {
        return self::lengthOf($profile, $this->embed($profile));
    }

    /**
     * The measured length, once it is known to equal the profile's declared dimensions.
     *
     * @throws EmbeddingDimensionMismatch when the model returns no vector or one of another length
     */
    public function verify(EmbeddingModelProfile $profile): int
    {
        return self::assertDimensions($profile, $this->embed($profile));
    }

    private static function lengthOf(EmbeddingModelProfile $profile, mixed $vector): int
    {
        if (! is_array($vector) || $vector === []) {
            throw EmbeddingDimensionMismatch::noEmbedding($profile);
        }

        return count($vector);
    }

    /**
     * @return list<float>
     */
    private function embed(EmbeddingModelProfile $profile): array
    {
        return $this->registry->withActive($profile->key, function (): array {
            $provider = $this->providerFactory instanceof Closure
                ? ($this->providerFactory)()
                : EmbeddingsProviderFactory::make();

            return $provider->embedText(self::TEXT);
        });
    }
}

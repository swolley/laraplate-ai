<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag;

use function ai_config_bool;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Contracts\IRagIndexRebuilder;
use Modules\AI\Services\DocumentationService;
use Override;
use RuntimeException;

/**
 * Rebuilds the Elasticsearch documentation indexes for a target embedding profile during a model
 * switch. {@see self::prepare()} runs `ai:create-rag-index --profile=all --force`, which recreates
 * them sized for the target's vectors; the documents are then written in chunks: per documentation
 * profile, ranges of source names in string order, the first and last range open so that a file
 * added after the plan still falls in one. Each chunk is indexed through
 * {@see DocumentationService::indexSources()} with the target as the active profile, replacing
 * each of its sources, so a chunk written twice leaves the same documents.
 *
 * Nothing happens, and nothing is planned, when FAQ/RAG is off or its documents are not kept in
 * Elasticsearch: a `filesystem` or `memory` store is not rebuilt by a switch.
 */
final readonly class RagIndexRebuilder implements IRagIndexRebuilder
{
    public function __construct(
        private EmbeddingModelRegistry $registry,
        private DocumentationService $documentation,
    ) {}

    /**
     * Whether a switch rebuilds the documentation indexes: FAQ on, with the `elasticsearch` store.
     */
    public static function rebuildsDocumentation(): bool
    {
        return ai_config_bool('ai.features.faq.enabled', true)
            && FaqVectorStoreConfig::driver() === 'elasticsearch';
    }

    #[Override]
    public function prepare(EmbeddingModelProfile $target): void
    {
        if (! self::rebuildsDocumentation()) {
            return;
        }

        $this->registry->withActive($target->key, function (): void {
            $this->call('ai:create-rag-index', ['--profile' => 'all', '--force' => true]);
        });
    }

    #[Override]
    public function plan(int $sourcesPerChunk): array
    {
        if (! self::rebuildsDocumentation()) {
            return [];
        }

        $size = max(1, $sourcesPerChunk);
        $chunks = [];

        foreach (DocumentationIndexProfile::cases() as $profile) {
            $model = EmbeddingSwitchState::RAG_CHUNK_PREFIX . $profile->value;
            $starts = array_values(array_filter(
                $this->documentation->sourceNames($profile),
                static fn (int $position): bool => $position % $size === 0,
                ARRAY_FILTER_USE_KEY,
            ));
            $count = max(1, count($starts));

            for ($index = 0; $index < $count; $index++) {
                $chunks["{$model}#{$index}"] = [
                    'model' => $model,
                    'from' => $index === 0 ? null : $starts[$index],
                    'to' => $starts[$index + 1] ?? null,
                ];
            }
        }

        return $chunks;
    }

    #[Override]
    public function writeChunk(EmbeddingModelProfile $target, array $chunk): void
    {
        $profile = DocumentationIndexProfile::tryFrom(mb_substr($chunk['model'], mb_strlen(EmbeddingSwitchState::RAG_CHUNK_PREFIX)));

        if (! EmbeddingSwitchState::isRagChunk($chunk) || ! $profile instanceof DocumentationIndexProfile) {
            throw new InvalidArgumentException("Not a documentation chunk: {$chunk['model']}");
        }

        $from = $chunk['from'] === null ? null : (string) $chunk['from'];
        $to = $chunk['to'] === null ? null : (string) $chunk['to'];

        $this->registry->withActive($target->key, function () use ($profile, $from, $to): void {
            $this->documentation->indexSources($profile, $from, $to);
        });
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function call(string $command, array $parameters): void
    {
        if (Artisan::call($command, $parameters) !== 0) {
            throw new RuntimeException("{$command} failed: " . mb_trim(Artisan::output()));
        }
    }
}

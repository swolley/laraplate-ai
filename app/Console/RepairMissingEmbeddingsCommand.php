<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use function ai_config_string;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Laravel\Scout\Searchable;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\Core\Models\ModelEmbedding;
use Override;
use Throwable;

/**
 * Repair sweep for documents whose embedding is missing or stale.
 *
 * By default, targets records with no ModelEmbedding row at all: a permanent
 * embed failure degrades the document to keyword-only indexing (see
 * GenerateEmbeddingsJob::failed()), leaving no embedding row. With --stale,
 * targets records whose embeddings were produced by a different model_key
 * than the active profile (e.g. after switching AI_EMBEDDINGS_MODEL).
 *
 * Either way, regeneration dispatches GenerateEmbeddingsJob with locale=null,
 * which performs a full per-locale regenerate (all locales, stamping the
 * active model_key) — this command does not stamp model_key itself.
 *
 * A preflight GET {sentence_transformers.url}/health cross-checks the
 * service's loaded model against the active profile's service_model and
 * warns on mismatch, but never aborts the repair run.
 */
final class RepairMissingEmbeddingsCommand extends Command
{
    #[Override]
    protected $signature = 'ai:embeddings:repair
                            {model : Fully qualified class name of the searchable model to repair}
                            {--chunk=100 : Number of records to scan per batch}
                            {--sync : Generate embeddings synchronously instead of queuing}
                            {--stale : Also target records whose embeddings were produced by a different model_key than the active profile (full per-locale regenerate)}';

    #[Override]
    protected $description = 'Regenerate embeddings for searchable records that are missing them or stale (produced by a non-active model_key); cross-checks the embedding service /health against the active model <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(EmbeddingModelRegistry $registry): int
    {
        $model_class = $this->resolveModelClass((string) $this->argument('model'));

        if ($model_class === null) {
            $this->error('Model class not found: ' . $this->argument('model'));

            return self::FAILURE;
        }

        $instance = new $model_class();

        if (! $this->isRepairable($instance)) {
            $this->error("Model {$model_class} is not a searchable embeddable model with vector search enabled");

            return self::FAILURE;
        }

        $active = $registry->active();

        $this->checkServiceHealth($active);

        $chunk = max(1, (int) $this->option('chunk'));
        $sync = (bool) $this->option('sync');
        $stale = (bool) $this->option('stale');

        $query = $model_class::query();

        if ($stale) {
            $this->info("Scanning for records with embeddings stale against model_key \"{$active->key}\"...");

            $query->whereHas('embeddings', function (Builder $embeddings) use ($active): void {
                /** @var Builder<ModelEmbedding> $embeddings */
                $embeddings->whereNot(fn (Builder $q): Builder => $q->producedBy($active->key));
            });
        } else {
            $this->info('Scanning for records with missing embeddings...');

            $query->whereDoesntHave('embeddings');
        }

        $dispatched = 0;

        $query
            ->lazyById($chunk, $instance->getKeyName())
            ->each(function (Model $model) use (&$dispatched, $sync): void {
                // Skip records that carry no embeddable text.
                $data = $model->prepareDataToEmbed();

                if ($data === null || $data === '') {
                    return;
                }

                // locale=null triggers a full per-locale regenerate stamping
                // the active model_key (GenerateEmbeddingsJob::handle()).
                if ($sync) {
                    dispatch_sync(new GenerateEmbeddingsJob($model));
                } else {
                    dispatch(new GenerateEmbeddingsJob($model));
                }

                $dispatched++;
            });

        $this->info("Embedding regeneration dispatched for {$dispatched} record(s) of {$model_class}");

        return self::SUCCESS;
    }

    /**
     * Preflight cross-check: warn (never abort) when the embedding service's
     * loaded model differs from the active profile, or when it can't be
     * reached at all.
     */
    private function checkServiceHealth(EmbeddingModelProfile $active): void
    {
        $url = mb_rtrim(ai_config_string('ai.providers.sentence_transformers.url', 'http://localhost:8000'), '/');

        try {
            $response = Http::timeout(5)->get($url . '/health');
            $response->throw();

            $reported_model = $response->json('model');

            if (is_string($reported_model) && $reported_model !== '' && $reported_model !== $active->serviceModel) {
                $this->warn("Embedding service /health reports model \"{$reported_model}\" but the active profile \"{$active->key}\" expects \"{$active->serviceModel}\".");
            }
        } catch (Throwable $exception) {
            $this->warn("Could not verify embedding service health at {$url}/health: " . $exception->getMessage());
        }
    }

    private function resolveModelClass(string $model): ?string
    {
        $model = mb_ltrim($model, '\\');

        return class_exists($model) ? $model : null;
    }

    private function isRepairable(object $instance): bool
    {
        return $instance instanceof Model
            && in_array(Searchable::class, class_uses_recursive($instance::class), true)
            && method_exists($instance, 'isEmbeddable')
            && method_exists($instance, 'prepareDataToEmbed')
            && $instance->isEmbeddable();
    }
}

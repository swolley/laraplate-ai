<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use function ai_config_int;
use function ai_config_nullable_string;
use function ai_config_string;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Traits\SearchableCommandUtils;
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
 * Before it scans, two preflight checks on the embedding service. A GET
 * {sentence_transformers.url}/health cross-checks the service's reported model
 * against the active profile's service_model and warns on mismatch or when it
 * cannot be reached: /health only echoes what the service was configured with.
 * The gate is a POST to /embed with a probe text, the payload the jobs send: if
 * it does not answer with a vector of the profile's dimensions, the run aborts
 * before dispatching anything. Records the search index would not hold
 * (shouldBeSearchable() false, e.g. media drafts) are skipped.
 */
final class RepairMissingEmbeddingsCommand extends Command
{
    use SearchableCommandUtils;

    #[Override]
    protected $signature = 'ai:embeddings:repair
                            {model? : Fully qualified class name of the searchable model to repair}
                            {--chunk=100 : Number of records to scan per batch}
                            {--all : Repair every embeddable searchable model instead of one (for scheduled runs)}
                            {--if-idle : Skip the run while embeddings jobs are still queued, so a backlog is not dispatched twice}
                            {--sync : Generate embeddings synchronously instead of queuing}
                            {--stale : Also target records whose embeddings were produced by a different model_key than the active profile (full per-locale regenerate)}';

    #[Override]
    protected $description = 'Regenerate embeddings for searchable records that are missing them or stale (produced by a non-active model_key); cross-checks the embedding service /health against the active model <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(EmbeddingModelRegistry $registry, IEmbeddableModels $embeddable_models): int
    {
        $all = (bool) $this->option('all');

        if ($all && $this->argument('model') !== null) {
            $this->error('Pass either a model or --all, not both.');

            return Command::INVALID;
        }

        // A scheduled run must not dispatch again what a previous run left in the queue: a record
        // keeps lacking its embedding until its job has run, so a backlog would be queued twice.
        if ((bool) $this->option('if-idle') && Queue::size('embeddings') > 0) {
            $this->info('Embeddings jobs are still queued: skipping this run so the backlog is not dispatched twice.');

            return self::SUCCESS;
        }

        if ($all) {
            if (! config('ai.features.embeddings.enabled', true)) {
                $this->info('The embeddings feature is disabled: nothing to repair.');

                return self::SUCCESS;
            }

            $models = $embeddable_models->all();

            if ($models === []) {
                $this->info('No embeddable model to repair.');

                return self::SUCCESS;
            }
        } else {
            $model_class = $this->getModelClass();

            if (in_array($model_class, ['', '0', false], true)) {
                return Command::INVALID;
            }

            if (! $this->isRepairable(new $model_class())) {
                $this->error("Model {$model_class} is not a searchable embeddable model with vector search enabled");

                return self::FAILURE;
            }

            $models = [$model_class];
        }

        $active = $registry->active();

        if (! $this->verifyService($active)) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($models as $model_class) {
            if (! $this->repair($model_class, $active)) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Scans one model and regenerates what is missing or stale; false when a sync run failed on a record.
     *
     * @param  class-string<Model>  $model_class
     */
    private function repair(string $model_class, EmbeddingModelProfile $active): bool
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $sync = (bool) $this->option('sync');
        $stale = (bool) $this->option('stale');

        // The population `scout:import` indexes, not `query()`: a global scope such as
        // `LocaleScope` hides content with no translation in the current locale, which
        // would then never get an embedding.
        $query = $model_class::makeAllSearchableQuery();

        if ($stale) {
            $this->info("Scanning {$model_class} for records with embeddings stale against model_key \"{$active->key}\"...");

            $query->whereHas('embeddings', function (Builder $embeddings) use ($active): void {
                /** @var Builder<ModelEmbedding> $embeddings */
                $embeddings->whereNot(fn (Builder $q): Builder => $q->producedBy($active->key));
            });
        } else {
            $this->info("Scanning {$model_class} for records with missing embeddings...");

            $query->whereDoesntHave('embeddings');
        }

        $dispatched = 0;
        $failures = [];

        $query
            ->lazyById($chunk, $model_class::query()->getModel()->getKeyName())
            ->each(function (Model $model) use (&$dispatched, &$failures, $sync): void {
                // Skip records the search index would not hold: an embedding nobody indexes is wasted.
                if (method_exists($model, 'shouldBeSearchable') && ! $model->shouldBeSearchable()) {
                    return;
                }

                // Skip records that carry no embeddable text.
                $data = $model->prepareDataToEmbed();

                if ($data === null || $data === '') {
                    return;
                }

                // locale=null triggers a full per-locale regenerate stamping
                // the active model_key (GenerateEmbeddingsJob::handle()).
                if ($sync) {
                    try {
                        dispatch_sync(new GenerateEmbeddingsJob($model)->unthrottled());
                    } catch (Throwable $exception) {
                        $failures[$model->getKey()] = $exception->getMessage();
                        $this->warn('Embedding failed for ' . $model::class . " #{$model->getKey()}: {$exception->getMessage()}");

                        return;
                    }
                } else {
                    dispatch(new GenerateEmbeddingsJob($model));
                }

                $dispatched++;
            });

        $this->info("Embedding regeneration dispatched for {$dispatched} record(s) of {$model_class}");

        if ($failures !== []) {
            $this->error(count($failures) . " record(s) of {$model_class} failed, no embedding was stored for them.");

            return false;
        }

        return true;
    }

    /**
     * The two preflight checks, before anything is dispatched. The service must embed (see
     * {@see self::probeEmbedding()}) and must run the model the active profile stamps on the
     * vectors: embeddings of another model stored under the profile's key look right, are invisible
     * to --stale, and quietly degrade the semantic search. The model the answer to the probe names
     * is authoritative, because a multi-model service answers for the model the request asked for;
     * /health is the fallback, and it only echoes what the service was started with. A service that
     * names no model cannot be checked: a warning, not an abort.
     */
    private function verifyService(EmbeddingModelProfile $active): bool
    {
        $health_model = $this->reportedByHealth();
        $probe = $this->probeEmbedding($active);

        if ($probe === null) {
            return false;
        }

        $reported = $probe['model'] ?? $health_model;
        $source = $probe['model'] !== null ? '/embed' : '/health';

        if ($reported === null) {
            $this->warn("The embedding service does not report which model it runs, so its vectors cannot be checked against the active profile \"{$active->key}\" ({$active->serviceModel}).");

            return true;
        }

        if (! EmbeddingModelProfile::sameServiceModel($reported, $active->serviceModel)) {
            $this->error("Embedding service {$source} reports model \"{$reported}\" but the active profile \"{$active->key}\" expects \"{$active->serviceModel}\": its vectors would be stored under the wrong model. Nothing was dispatched.");

            return false;
        }

        return true;
    }

    /**
     * The model /health reports, or null when it names none or cannot be reached (a warning).
     */
    private function reportedByHealth(): ?string
    {
        $url = mb_rtrim(ai_config_string('ai.providers.sentence_transformers.url', 'http://localhost:8000'), '/');

        try {
            $response = Http::timeout(5)->get($url . '/health');
            $response->throw();

            $reported_model = $response->json('model');

            return is_string($reported_model) && $reported_model !== '' ? $reported_model : null;
        } catch (Throwable $exception) {
            $this->warn("Could not verify embedding service health at {$url}/health: " . $exception->getMessage());

            return null;
        }
    }

    /**
     * Embeds a probe text the way the jobs do, so a service that answers /health but cannot embed
     * (a model that failed to load, a request it rejects) stops the run instead of silently losing
     * every job. The answer must carry a vector of the active profile's dimensions.
     *
     * @return array{model: string|null}|null the model the answer names, or null when the probe failed (the reason is printed)
     */
    private function probeEmbedding(EmbeddingModelProfile $active): ?array
    {
        $url = mb_rtrim(ai_config_string('ai.providers.sentence_transformers.url', 'http://localhost:8000'), '/');
        $api_key = ai_config_nullable_string('ai.providers.sentence_transformers.api_key');

        try {
            $request = Http::timeout(ai_config_int('ai.providers.sentence_transformers.timeout', 30))->acceptJson();

            if ($api_key !== null && $api_key !== '') {
                $request = $request->withToken($api_key);
            }

            $response = $request->post($url . '/embed', [
                'texts' => [EmbeddingDimensionProbe::TEXT],
                'truncation' => true,
                'normalize_embeddings' => true,
                'max_length' => 512,
                'model' => $active->serviceModel,
            ]);
            $response->throw();

            try {
                EmbeddingDimensionProbe::assertDimensions($active, $response->json('embeddings.0'));
            } catch (EmbeddingDimensionMismatch $mismatch) {
                return $this->probeFailed($url, $mismatch->measured === null
                    ? 'the answer carries no embedding'
                    : "it returned {$mismatch->measured} dimensions, the active profile \"{$active->key}\" expects {$active->dimensions}");
            }

            $model = $response->json('model');
        } catch (Throwable $exception) {
            return $this->probeFailed($url, Str::limit($exception->getMessage(), 200));
        }

        return ['model' => is_string($model) && $model !== '' ? $model : null];
    }

    private function probeFailed(string $url, string $reason): null
    {
        $this->error("Embedding service probe failed at {$url}/embed: {$reason}. Nothing was dispatched.");

        return null;
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

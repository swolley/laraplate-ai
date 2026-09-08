<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Override;

/**
 * Repair sweep for documents whose embedding is missing: a permanent embed
 * failure degrades the document to keyword-only indexing (see
 * GenerateEmbeddingsJob::failed()), leaving no embedding row. This command
 * re-generates the embedding for those records; the regenerated embedding then
 * patches the search document through the normal finalize flow.
 */
final class RepairMissingEmbeddingsCommand extends Command
{
    #[Override]
    protected $signature = 'ai:embeddings:repair
                            {model : Fully qualified class name of the searchable model to repair}
                            {--chunk=100 : Number of records to scan per batch}
                            {--sync : Generate embeddings synchronously instead of queuing}';

    #[Override]
    protected $description = 'Regenerate embeddings for searchable records that are missing them <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(): int
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

        $chunk = max(1, (int) $this->option('chunk'));
        $sync = (bool) $this->option('sync');

        $this->info('Scanning for records with missing embeddings...');

        $dispatched = 0;

        $model_class::query()
            ->whereDoesntHave('embeddings')
            ->lazyById($chunk, $instance->getKeyName())
            ->each(function (Model $model) use (&$dispatched, $sync): void {
                // Skip records that carry no embeddable text.
                $data = $model->prepareDataToEmbed();

                if ($data === null || $data === '') {
                    return;
                }

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

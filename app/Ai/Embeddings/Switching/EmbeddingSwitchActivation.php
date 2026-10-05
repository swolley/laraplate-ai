<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Database\Eloquent\Builder;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Models\Setting;
use Modules\Core\Search\Services\VectorSearchAvailability;
use Throwable;

/**
 * The `activate` phase of an embedding model switch, in one step: the target becomes the model
 * that serves search, vector search is resumed, the switch is idle again, and the rows of every
 * other model are deleted. Repeating it after a failure halfway is harmless: the settings are
 * written in one transaction with the same values, and the later steps are idempotent. The state
 * stays `running` until the settings are written, so jobs that run meanwhile still embed with the
 * target.
 */
final readonly class EmbeddingSwitchActivation
{
    /**
     * The settings written together, managed by the switch.
     */
    private const array SETTINGS = [
        'features.embeddings.active',
        'search.vector.dimensions',
        'search.vector.similarity',
        'search.vector.model',
        'features.embeddings.model',
    ];

    public function __construct(private EmbeddingSwitchStore $store) {}

    public function activate(EmbeddingModelProfile $target): EmbeddingSwitchState
    {
        $this->writeSettings($target);

        $this->store->clearSuspension();

        $idle = EmbeddingSwitchState::idle()->with(updatedAt: now()->toIso8601String());
        $this->store->put($idle);

        app(VectorSearchAvailability::class)->forget();

        ModelEmbedding::query()
            ->where(static function (Builder $query) use ($target): void {
                $query->where('model_key', '!=', $target->key)->orWhereNull('model_key');
            })
            ->delete();

        return $idle;
    }

    /**
     * Writes the target into the settings in one transaction; the cache and runtime config are
     * flushed after the commit, and resynchronised from the database when the transaction rolls
     * back (the observer has already pushed the values that were not kept).
     */
    private function writeSettings(EmbeddingModelProfile $target): void
    {
        $connection = new Setting()->getConnection();

        try {
            $connection->transaction(function () use ($target): void {
                Setting::writeManaged('features.embeddings.active', $target->key);
                Setting::writeManaged('search.vector.dimensions', $target->dimensions);
                Setting::writeManaged('search.vector.similarity', $target->similarity);
                Setting::writeManaged('search.vector.model', $target->key);
                $this->store->recordTarget($target->key);
                $this->store->flushAfterCommit(self::SETTINGS);
            });
        } catch (Throwable $exception) {
            $this->store->resync(self::SETTINGS);

            throw $exception;
        }
    }
}

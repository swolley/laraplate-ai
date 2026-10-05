<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingServiceIdentity;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Ai\Providers\Models\ProviderConfiguration;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\Core\Models\Setting;
use Override;
use Throwable;

/**
 * Starts a switch of the embedding model. The preflight runs here, synchronously, before anything
 * changes: the profile exists, is not the active one, its provider is configured, the probe measures
 * its declared dimensions and the service runs its model. Only then is the state stored as
 * `running`/`preflight`, vector search suspended and `SwitchEmbeddingModelJob` dispatched.
 *
 * The atomic lock covers only the start (state still idle, then persisted as running); for the rest
 * of the switch the persisted `running` or `failed` state is what refuses another start.
 */
final class EmbeddingsSwitchCommand extends Command
{
    public const string SUSPENDED_REASON = 'switching';

    #[Override]
    protected $signature = 'ai:embeddings:switch
                            {profile : Target embedding model profile key, e.g. sentence_transformers:all-MiniLM-L6-v2}';

    #[Override]
    protected $description = 'Start switching the embedding model to another profile: preflight, then re-embed and reindex in the background while vector search is off <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(
        EmbeddingModelRegistry $registry,
        ProviderConfiguration $providers,
        EmbeddingSwitchStore $store,
        EmbeddingDimensionProbe $probe,
        EmbeddingServiceIdentity $identity,
    ): int {
        try {
            $profile = $registry->get((string) $this->argument('profile'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $active = $registry->activeKey();

        if ($profile->key === $active) {
            $this->error("Profile \"{$profile->key}\" is already the active embedding model: there is nothing to switch.");

            return self::FAILURE;
        }

        if (! $providers->isConfigured($profile->provider)) {
            $this->error("The provider \"{$profile->provider}\" of profile \"{$profile->key}\" is not configured. Nothing was changed.");

            return self::FAILURE;
        }

        $release = $store->lock();

        if ($release === null) {
            $this->error('Another embedding model switch is starting: try again in a moment. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $current = $store->get();

            if ($current->status !== 'idle') {
                $this->error("An embedding model switch is already {$current->status} (phase {$current->phase}, target {$current->target}). Nothing was changed.");

                return self::FAILURE;
            }

            $failure = $this->preflight($profile, $probe, $identity);

            if ($failure !== null) {
                $this->error("The switch to \"{$profile->key}\" did not start: {$failure}. Nothing was changed.");

                return self::FAILURE;
            }

            $this->start($store, $profile, $active);
        } catch (Throwable $exception) {
            $this->error("The switch to \"{$profile->key}\" did not start: {$exception->getMessage()}");

            return self::FAILURE;
        } finally {
            $release();
        }

        $this->info("Switch to \"{$profile->key}\" started: vector search is off until it ends. Follow it with ai:embeddings:status.");

        return self::SUCCESS;
    }

    /**
     * The reason the target cannot be switched to, or null when it can.
     */
    private function preflight(EmbeddingModelProfile $profile, EmbeddingDimensionProbe $probe, EmbeddingServiceIdentity $identity): ?string
    {
        try {
            $probe->verify($profile);
        } catch (EmbeddingDimensionMismatch $mismatch) {
            return $mismatch->getMessage();
        } catch (Throwable $exception) {
            return "the embedding probe failed: {$exception->getMessage()}";
        }

        if ($profile->provider !== 'sentence_transformers') {
            return null;
        }

        return $this->serviceRunsModel($profile, $identity);
    }

    /**
     * The sentence-transformers service must answer `/health` and must run the profile's model, as
     * `ai:embeddings:repair` checks it: the probe answer names the model, `/health` is the fallback.
     * Unlike the repair, a service that cannot be identified stops the switch.
     */
    private function serviceRunsModel(EmbeddingModelProfile $profile, EmbeddingServiceIdentity $identity): ?string
    {
        $url = $identity->url();

        try {
            $healthModel = $identity->healthModel();
        } catch (Throwable $exception) {
            return "the embedding service does not answer {$url}/health: {$exception->getMessage()}";
        }

        try {
            $probeModel = $identity->probeModel($profile);
        } catch (EmbeddingDimensionMismatch $mismatch) {
            return $mismatch->getMessage();
        } catch (Throwable $exception) {
            return "the embedding service probe failed at {$url}/embed: {$exception->getMessage()}";
        }

        $reported = EmbeddingServiceIdentity::reported($probeModel, $healthModel);

        if ($reported === null) {
            return "the embedding service at {$url} does not report which model it runs, so it cannot be checked against \"{$profile->serviceModel}\"";
        }

        if (! EmbeddingModelProfile::sameServiceModel($reported['model'], $profile->serviceModel)) {
            return "the embedding service {$reported['source']} reports model \"{$reported['model']}\" but the profile expects \"{$profile->serviceModel}\"";
        }

        return null;
    }

    private function start(EmbeddingSwitchStore $store, EmbeddingModelProfile $profile, string $active): void
    {
        new Setting()->getConnection()->transaction(static function () use ($store, $profile, $active): void {
            $store->put(new EmbeddingSwitchState(
                status: 'running',
                phase: 'preflight',
                target: $profile->key,
                previous: $active,
                startedAt: now()->toIso8601String(),
            ));
            Setting::writeManaged('search.vector.suspended_reason', self::SUSPENDED_REASON);
        });

        SwitchEmbeddingModelJob::dispatch();
    }
}

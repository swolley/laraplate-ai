<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
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
 *
 * A refusal changes nothing. With `--report-failure`, the option the settings confirmation queues
 * the command with, a refusal decided under the start lock (provider not configured, preflight
 * failed) is also stored as a `failed` switch in phase `preflight`, so the settings page shows it;
 * vector search is not suspended. A held lock, an already active profile or a switch already
 * running or failed records nothing. A start whose job cannot be queued is rolled back.
 */
final class EmbeddingsSwitchCommand extends Command
{
    #[Override]
    protected $signature = 'ai:embeddings:switch
                            {profile : Target embedding model profile key, e.g. sentence_transformers:all-MiniLM-L6-v2}
                            {--report-failure : Internal (the settings confirmation): record a refused start as a failed switch, so the settings page shows why}';

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
            return $this->refuse("Profile \"{$profile->key}\" is already the active embedding model: there is nothing to switch.");
        }

        $release = $store->lock();

        if ($release === null) {
            return $this->refuse('Another embedding model switch is starting: try again in a moment. Nothing was changed.');
        }

        try {
            $current = $store->get();

            if ($current->status !== 'idle') {
                return $this->refuse("An embedding model switch is already {$current->status} (phase {$current->phase}, target {$current->target}). Nothing was changed.");
            }

            $failure = $providers->isConfigured($profile->provider)
                ? $this->preflight($profile, $probe, $identity)
                : "the provider \"{$profile->provider}\" is not configured";

            if ($failure !== null) {
                return $this->refuseUnderLock($store, $profile, $active, "The switch to \"{$profile->key}\" did not start: {$failure}. Nothing was changed.");
            }

            $this->persistStart($store, $profile, $active);
        } catch (Throwable $exception) {
            return $this->refuseUnderLock($store, $profile, $active, "The switch to \"{$profile->key}\" did not start: {$exception->getMessage()}");
        } finally {
            $release();
        }

        try {
            app(Dispatcher::class)->dispatch(new SwitchEmbeddingModelJob());
        } catch (Throwable $exception) {
            $this->rollBackStart($store);
            $this->error("The switch to \"{$profile->key}\" did not start: could not queue the switch; nothing was changed ({$exception->getMessage()}).");

            return self::FAILURE;
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

    private function persistStart(EmbeddingSwitchStore $store, EmbeddingModelProfile $profile, string $active): void
    {
        new Setting()->getConnection()->transaction(static function () use ($store, $profile, $active): void {
            $store->put(new EmbeddingSwitchState(
                status: 'running',
                phase: 'preflight',
                target: $profile->key,
                previous: $active,
                startedAt: now()->toIso8601String(),
            ));
            $store->suspend();
        });
    }

    /**
     * Undoes a stored start whose job could not be queued: the state is idle again and vector search
     * is no longer suspended.
     */
    private function rollBackStart(EmbeddingSwitchStore $store): void
    {
        new Setting()->getConnection()->transaction(static function () use ($store): void {
            $store->put(EmbeddingSwitchState::idle());
            $store->clearSuspension();
        });
    }

    /**
     * Prints why the start was refused, recording nothing: the profile is already active, another
     * start holds the lock, or a switch is already running or failed.
     */
    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /**
     * Prints why the start was refused by its own checks, while this command still holds the start
     * lock and the state is idle. With `--report-failure` (the settings confirmation, whose queued
     * output nobody reads) the refusal is also stored as a failed switch in its preflight, so the
     * settings page locks the field and shows the reason. Vector search stays available.
     */
    private function refuseUnderLock(EmbeddingSwitchStore $store, EmbeddingModelProfile $profile, string $active, string $message): int
    {
        $this->error($message);

        if ((bool) $this->option('report-failure') && $store->get()->status === 'idle') {
            $store->put(new EmbeddingSwitchState(
                status: 'failed',
                phase: 'preflight',
                target: $profile->key,
                previous: $active,
                error: $message,
                startedAt: now()->toIso8601String(),
            ));
        }

        return self::FAILURE;
    }
}

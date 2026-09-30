<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationCase;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\AI\Services\ApplicationContent\Evaluation\RetrievalTuningService;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\ApplicationContent\Contracts\ProvidesPermissionModel;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Nwidart\Modules\Facades\Module;
use Override;
use Throwable;

/**
 * Grid-searches the fusion parameters of the retrieval tuning profile against a curated dataset
 * and writes a JSON report plus a printed profile block. It never edits `config/search_tuning.php`:
 * a human reads the numbers and commits the profile.
 */
final class TuneRetrievalCommand extends Command
{
    #[Override]
    protected $signature = 'ai:tune-retrieval
                            {--source= : Registered application content source}
                            {--dataset= : Path to a generated evaluation dataset}
                            {--grid=default : Built-in grid name, or a JSON file with a list of parameter sets}
                            {--metric=ndcg_at_5 : Metric that ranks the candidates}
                            {--output= : New JSON report path}
                            {--force : Replace an existing report}';

    #[Override]
    protected $description = 'Rank retrieval fusion parameter sets per query class against a curated dataset, re-fusing recorded per-strategy rankings, and print a profile block for config/search_tuning.php.';

    public function handle(
        ApplicationContentRetrievalProviderRegistryInterface $providers,
        RetrievalTuningService $tuning,
        PerStrategyEngineRetrieverInterface $retriever,
        ITextEmbedder $embedder,
        Filesystem $files,
    ): int {
        $dataset_path = $this->optionString('dataset');
        $source_option = $this->optionString('source');
        $output_path = $this->optionString('output');
        $metric = $this->optionString('metric') ?? 'ndcg_at_5';

        if ($dataset_path === null || $source_option === null || $output_path === null) {
            $this->error('The --dataset, --source, and --output options are required.');

            return self::FAILURE;
        }

        if (! in_array($metric, RetrievalTuningService::metricNames(), true)) {
            $this->error('The requested metric is unknown.');

            return self::FAILURE;
        }

        try {
            $source = ApplicationContentSourceDescriptor::normalizeSource($source_option);
            $provider = $providers->providerFor($source);
            $descriptor = $providers->descriptorFor($source);

            if ($provider === null
                || $descriptor === null
                || ! Module::isEnabled(Str::studly($descriptor->module))) {
                $this->error('The requested tuning source is unavailable.');

                return self::FAILURE;
            }

            if (! $provider instanceof ProvidesPermissionModel) {
                $this->error('The requested tuning source does not declare a permission model.');

                return self::FAILURE;
            }

            $grid = $this->grid($this->optionString('grid') ?? 'default', $files);

            if ($grid === null) {
                $this->error('The requested grid is unavailable.');

                return self::FAILURE;
            }

            /** @var class-string<Model> $model_class */
            $model_class = $provider->permissionModel();
            $model = new $model_class();

            if ($files->exists($output_path) && ! (bool) $this->option('force')) {
                $this->error('The output report already exists. Use --force to replace it.');

                return self::FAILURE;
            }

            $output_directory = dirname($output_path);

            if (! $files->isDirectory($output_directory) || ! $files->isWritable($output_directory)) {
                $this->error('The output directory is unavailable.');

                return self::FAILURE;
            }

            $dataset = ApplicationContentEvaluationDataset::fromFile($dataset_path);

            foreach ($dataset->cases as $case) {
                if (! in_array($case->locale, $descriptor->supportedLocales, true)) {
                    $this->error('The evaluation dataset requests an unsupported locale.');

                    return self::FAILURE;
                }
            }

            /** @var array<string, list<float>> $vectors */
            $vectors = [];
            $report = $tuning->tune(
                $dataset,
                $source,
                $grid,
                $metric,
                static function (ApplicationContentEvaluationCase $case, bool $useReranker) use (&$vectors, $embedder, $retriever, $model): AdvancedSearchResult {
                    $vectors[$case->id] ??= $embedder->embed($case->query);

                    return $retriever->retrieve($model, $case->query, $useReranker, $case->limit, $vectors[$case->id]);
                },
            );
            $encoded = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
            $temporary_path = $output_path . '.tmp-' . bin2hex(random_bytes(6));

            try {
                $files->put($temporary_path, $encoded . PHP_EOL, true);
                $files->move($temporary_path, $output_path);
            } finally {
                if ($files->exists($temporary_path)) {
                    $files->delete($temporary_path);
                }
            }

            $this->info(sprintf('Ranked %d candidates by %s.', count($grid), $metric));
            $this->line('// ai:tune-retrieval report: ' . $output_path);

            foreach (explode(PHP_EOL, $tuning->profileBlock($report)) as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Retrieval tuning failed.');

            return self::FAILURE;
        }
    }

    /**
     * @return list<array<string, int|float>>|null
     */
    private function grid(string $option, Filesystem $files): ?array
    {
        if (! str_ends_with(mb_strtolower($option), '.json')) {
            try {
                return RetrievalTuningService::namedGrid($option);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        if (! $files->isFile($option) || $files->size($option) > 1_000_000) {
            return null;
        }

        $decoded = json_decode($files->get($option), true);

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        foreach ($decoded as $candidate) {
            if (! is_array($candidate)) {
                return null;
            }
        }

        /** @var list<array<string, int|float>> $decoded */
        return $decoded;
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }
}

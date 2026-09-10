<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationCase;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentRetrievalStrategyEvaluationService;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\ApplicationContent\Contracts\ProvidesPermissionModel;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Nwidart\Modules\Facades\Module;
use Override;
use Throwable;

final class EvaluateApplicationContentRetrievalStrategiesCommand extends Command
{
    #[Override]
    protected $signature = 'ai:evaluate-retrieval-strategies
                            {--dataset= : Path to a generated evaluation dataset}
                            {--source= : Registered application content source}
                            {--output= : New JSON report path}
                            {--force : Replace an existing report}';

    #[Override]
    protected $description = 'Evaluate per-strategy retrieval ranking quality (keyword, vector, hybrid, fused, reranked) for a registered application content source against the real search engine.';

    public function handle(
        ApplicationContentRetrievalProviderRegistryInterface $providers,
        ApplicationContentRetrievalStrategyEvaluationService $evaluation,
        PerStrategyEngineRetrieverInterface $retriever,
        ITextEmbedder $embedder,
        Filesystem $files,
    ): int {
        $dataset_path = $this->optionString('dataset');
        $source_option = $this->optionString('source');
        $output_path = $this->optionString('output');

        if ($dataset_path === null || $source_option === null || $output_path === null) {
            $this->error('The --dataset, --source, and --output options are required.');

            return self::FAILURE;
        }

        try {
            $source = ApplicationContentSourceDescriptor::normalizeSource($source_option);
            $provider = $providers->providerFor($source);
            $descriptor = $providers->descriptorFor($source);

            if ($provider === null
                || $descriptor === null
                || ! Module::isEnabled(Str::studly($descriptor->module))) {
                $this->error('The requested evaluation source is unavailable.');

                return self::FAILURE;
            }

            if (! $provider instanceof ProvidesPermissionModel) {
                $this->error('The requested evaluation source does not declare a permission model.');

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

            $driver = config('scout.driver', 'unknown');

            /** @var array<string, list<float>> $vectors */
            $vectors = [];
            $report = $evaluation->evaluate(
                $dataset,
                $source,
                is_string($driver) ? $driver : 'unknown',
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

            $this->info(sprintf('Evaluated %d generated cases.', $report['case_count']));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Application content retrieval strategy evaluation failed.');

            return self::FAILURE;
        }
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }
}

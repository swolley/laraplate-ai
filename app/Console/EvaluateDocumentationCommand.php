<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Override;
use Throwable;

final class EvaluateDocumentationCommand extends Command
{
    #[Override]
    protected $signature = 'ai:evaluate-documentation
                            {--module= : Module that owns the dataset}
                            {--index=user : Documentation index profile (user|developer)}
                            {--dataset= : Path to an evaluation dataset}
                            {--output= : New JSON report path}
                            {--force : Replace an existing report}';

    #[Override]
    protected $description = 'Evaluate documentation RAG retrieval for a module without calling the chat model.';

    public function handle(
        DocumentationEvaluationService $evaluation,
        InAppDocumentationRetrieval $retrieval,
        Filesystem $files,
    ): int {
        $module = $this->optionString('module');
        $index = $this->optionString('index') ?? 'user';
        $dataset_path = $this->optionString('dataset');
        $output_path = $this->optionString('output');

        if ($module === null || $dataset_path === null || $output_path === null) {
            $this->error('The --module, --dataset, and --output options are required.');

            return self::FAILURE;
        }

        try {
            if ($files->exists($output_path) && ! (bool) $this->option('force')) {
                $this->error('The output report already exists. Use --force to replace it.');

                return self::FAILURE;
            }

            $output_directory = dirname($output_path);

            if (! $files->isDirectory($output_directory) || ! $files->isWritable($output_directory)) {
                $this->error('The output directory is unavailable.');

                return self::FAILURE;
            }

            $dataset = DocumentationEvaluationDataset::fromFile($dataset_path);

            if ($dataset->module !== $module || $dataset->indexProfile !== $index) {
                $this->error('The dataset module or index does not match the requested options.');

                return self::FAILURE;
            }

            if (DocumentationIndexProfile::tryFrom($index) !== DocumentationIndexProfile::User) {
                $this->error('Only the user documentation index is supported.');

                return self::FAILURE;
            }

            $report = $evaluation->evaluate(
                $dataset,
                'in-app-documentation',
                static fn (string $question, AssistantAccessContext $access): array => $retrieval->retrieve($question, $access),
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

            $this->info(sprintf('Evaluated %d documentation cases.', $report['case_count']));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Documentation evaluation failed.');

            return self::FAILURE;
        }
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Exception;
use Illuminate\Console\Command;
use Modules\AI\Services\DocumentationService;
use Override;

final class IndexDocumentationCommand extends Command
{
    #[Override]
    protected $signature = 'ai:index-docs
                            {--path= : Custom path to documentation (default: config or resource_path(\'docs\'))}';

    #[Override]
    protected $description = 'Index documentation for FAQ/RAG (embeddings and vector store)';

    public function handle(DocumentationService $documentationService): int
    {
        if (! config('ai.features.faq.enabled', true)) {
            $this->warn('FAQ/RAG is disabled in config (ai.features.faq.enabled).');

            return self::FAILURE;
        }

        $path = $this->option('path');

        if ($path !== null && (! is_dir($path) && ! is_file($path))) {
            $this->error("Path does not exist or is not readable: {$path}");

            return self::FAILURE;
        }

        try {
            $count = $documentationService->indexDocuments($path);
            $this->info("Indexed {$count} document chunks.");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Indexing failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}

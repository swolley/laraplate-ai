<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Exception;
use Illuminate\Console\Command;
use Modules\AI\Services\DocumentationService;
use Override;

use function ai_config_bool;
use function ai_config_string;

final class IndexDocumentationCommand extends Command
{
    #[Override]
    protected $signature = 'ai:index-docs
                            {--path= : Scan only this file or directory (omit to index roots returned by rag_paths())}
                            {--full : Delete the vector store first, then rebuild from the selected documentation}';

    #[Override]
    protected $description = 'Index documentation for FAQ/RAG: when --path is omitted, roots come from rag_paths(). Incremental runs use reindex-by-source to avoid duplicate chunks unless --full is passed. <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(DocumentationService $documentationService): int
    {
        if (! ai_config_bool('ai.features.faq.enabled', true)) {
            $this->warn('FAQ/RAG is disabled in config (ai.features.faq.enabled).');

            return self::FAILURE;
        }

        $path_option = $this->option('path');
        $path = is_string($path_option) && $path_option !== '' ? $path_option : null;
        $full = (bool) $this->option('full');

        if ($path !== null && (! is_dir($path) && ! is_file($path))) {
            $this->error("Path does not exist or is not readable: {$path}");

            return self::FAILURE;
        }

        if ($full && ai_config_string('ai.features.faq.vector_store', 'filesystem') === 'memory') {
            $this->comment('Vector store driver is "memory": --full resets the in-process shared store only.');
        }

        try {
            $count = $documentationService->indexDocuments($path, $full);
            $this->info("Indexed {$count} document chunks.");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error('Indexing failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}

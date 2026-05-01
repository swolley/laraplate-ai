<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Illuminate\Support\Str;
use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Services\Documentation\FileDocumentReader;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;

final readonly class DocumentationService
{
    /**
     * @param  Closure(): DocumentationAgent|null  $agentFactory  Optional factory for testing
     */
    public function __construct(
        private readonly ?Closure $agentFactory = null,
    ) {}

    /**
     * Index documentation: read markdown/html, split, embed, persist to the vector store.
     *
     * When {@code $path} is null, roots come from {@see rag_paths()}.
     * When {@code $path} is provided (CLI --path), only that root is indexed.
     *
     * When the vector store already has data (filesystem file non-empty, or memory driver), uses
     * {@see DocumentationAgent::reindexBySource()} so repeated runs update each logical file instead of duplicating chunks.
     * Pass {@code $fullRebuild} true to wipe the store and rebuild from scratch (filesystem: deletes store file; memory: resets shared store).
     */
    public function indexDocuments(?string $path = null, bool $fullRebuild = false): int
    {
        $roots = $path !== null
            ? [['path' => $path, 'prefix' => $this->singlePathPrefix($path)]]
            : $this->helperRoots();

        return $this->indexFromRoots($roots, $fullRebuild);
    }

    /**
     * Answer a question using RAG (vector search + LLM).
     *
     * @return array{answer: string, citations: array<int, array{source: string, excerpt: string, score: float|null}>}
     */
    public function answerQuestion(string $question): array
    {
        $factory = $this->agentFactory ?? fn (): DocumentationAgent => DocumentationAgent::make(
            topK: (int) config('ai.features.faq.max_documents', 5),
        );

        /** @var DocumentationAgent $agent */
        $agent = $factory();

        $response = $agent->chat(new UserMessage($question));
        $answer = $response->getMessage()->getContent();

        $citations = [];

        if (method_exists($response->getMessage(), 'getCitations')) {
            foreach ($response->getMessage()->getCitations() as $citation) {
                $citations[] = [
                    'source' => $citation->getSourceName() ?? 'Unknown',
                    'excerpt' => Str::limit($citation->getContent() ?? '', 300),
                    'score' => $citation->getScore(),
                ];
            }
        }

        $formatted_answer = $answer;

        if (config('ai.features.faq.format_citations', true) && $citations !== []) {
            $formatted_answer = $this->appendCitationsToAnswer($answer, $citations);
        }

        return [
            'answer' => $formatted_answer,
            'citations' => $citations,
        ];
    }

    /**
     * Check if FAQ/RAG is enabled and documentation is indexed.
     */
    public function isAvailable(): bool
    {
        if (! config('ai.features.faq.enabled', true)) {
            return false;
        }

        $store_driver = (string) config('ai.features.faq.vector_store', 'filesystem');

        if ($store_driver === 'filesystem') {
            $path = $this->getFilesystemVectorStoreFilePath();

            return file_exists($path);
        }

        return true;
    }

    private function appendCitationsToAnswer(string $answer, array $citations): string
    {
        if ($citations === []) {
            return $answer;
        }

        $citation_lines = [];

        foreach ($citations as $index => $citation) {
            $number = $index + 1;
            $source = $citation['source'] ?? 'Unknown';
            $citation_lines[] = "[{$number}] {$source}";
        }

        return $answer . "\n\n---\n**Sources:**\n" . implode("\n", $citation_lines);
    }

    private function singlePathPrefix(string $path): string
    {
        $resolved = realpath($path);

        return 'faq-cli-' . substr(hash('sha256', $resolved !== false ? $resolved : $path), 0, 16);
    }

    /**
     * @return list<array{path: string, prefix: string}>
     */
    private function helperRoots(): array
    {
        if (! function_exists('rag_paths')) {
            return [];
        }

        $roots = [];

        foreach ((array) rag_paths(onlyActive: true, prioritySort: true) as $path) {
            $path = (string) $path;

            $roots[] = [
                'path' => $path,
                'prefix' => $this->prefixFromHelperPath($path),
            ];
        }

        return $roots;
    }

    private function prefixFromHelperPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path());

        if (preg_match('#/Modules/([^/]+)/docs/rag/?$#', $normalized, $matches)) {
            return 'faq-module-' . ($matches[1] ?? 'unknown');
        }

        if ($normalized === $base . '/docs/rag' || $normalized === $base . '/docs/rag/') {
            return 'faq-app-rag';
        }

        return 'faq-config';
    }

    /**
     * @param  list<array{path: string, prefix: string}>  $roots
     */
    private function indexFromRoots(array $roots, bool $fullRebuild): int
    {
        $documents = $this->gatherDocumentsFromRoots($roots);

        if ($documents === []) {
            return 0;
        }

        $splitter = new SentenceTextSplitter;
        $split_documents = [];

        foreach ($documents as $document) {
            foreach ($splitter->splitDocument($document) as $chunk) {
                $split_documents[] = $chunk;
            }
        }

        if ($split_documents === []) {
            return 0;
        }

        $driver = (string) config('ai.features.faq.vector_store', 'filesystem');

        if ($fullRebuild) {
            $this->resetVectorStoreForFullRebuild($driver);
        }

        $use_incremental_reindex = ! $fullRebuild && $this->shouldUseIncrementalReindex($driver);

        $factory = $this->agentFactory ?? DocumentationAgent::make(...);

        /** @var DocumentationAgent $agent */
        $agent = $factory();

        if ($use_incremental_reindex) {
            $agent->reindexBySource($split_documents);
        } else {
            $agent->addDocuments($split_documents);
        }

        return count($split_documents);
    }

    private function shouldUseIncrementalReindex(string $driver): bool
    {
        if ($driver === 'memory') {
            return true;
        }

        return $this->filesystemVectorStoreHasData();
    }

    private function filesystemVectorStoreHasData(): bool
    {
        $path = $this->getFilesystemVectorStoreFilePath();

        return is_file($path) && filesize($path) > 0;
    }

    private function getFilesystemVectorStoreFilePath(): string
    {
        return (string) (config('ai.features.faq.vector_store_path') ?: storage_path('app/ai/faq-vectorstore.store'));
    }

    private function resetVectorStoreForFullRebuild(string $driver): void
    {
        if ($driver === 'memory') {
            DocumentationAgent::resetSharedMemoryVectorStore();

            return;
        }

        $store_path = $this->getFilesystemVectorStoreFilePath();

        if (is_file($store_path)) {
            unlink($store_path);
        }
    }

    /**
     * @param  list<array{path: string, prefix: string}>  $roots
     * @return list<Document>
     */
    private function gatherDocumentsFromRoots(array $roots): array
    {
        $documents = [];

        foreach ($roots as $root) {
            $physical = $root['path'];
            $prefix = $root['prefix'];

            if (! is_dir($physical) && ! is_file($physical)) {
                continue;
            }

            $reader = new FileDocumentReader($physical, FileDocumentReader::DOCUMENT_EXTENSIONS, $prefix);
            $documents = array_merge($documents, $reader->getDocuments());
        }

        return $documents;
    }
}

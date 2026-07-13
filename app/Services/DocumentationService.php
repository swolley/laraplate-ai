<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use function ai_config_bool;
use function ai_config_int;
use function ai_config_string;

use Closure;
use Illuminate\Support\Str;
use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Services\Documentation\Chunking\SplitterFactory;
use Modules\AI\Services\Documentation\FileDocumentReader;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SplitterInterface;

final readonly class DocumentationService
{
    /**
     * @param  Closure(): DocumentationAgent|null  $agentFactory  Optional factory for testing
     */
    public function __construct(
        private ?Closure $agentFactory = null,
        private ?SplitterInterface $splitter = null,
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
     * @return array{answer: string, citations: list<array{source: string, excerpt: string, score: float|null}>}
     */
    public function answerQuestion(string $question): array
    {
        $factory = $this->agentFactory ?? fn (): DocumentationAgent => DocumentationAgent::make(
            topK: ai_config_int('ai.features.faq.max_documents', 5),
        );

        /** @var DocumentationAgent $agent */
        $agent = $factory();

        $response = $agent->chat(new UserMessage($question));
        $answer = $response->getMessage()->getContent() ?? '';
        $citations = $this->buildCitations($response->getMessage());

        $formatted_answer = $answer;

        if (ai_config_bool('ai.features.faq.format_citations', true) && $citations !== []) {
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
        if (! ai_config_bool('ai.features.faq.enabled', true)) {
            return false;
        }

        $store_driver = ai_config_string('ai.features.faq.vector_store', 'filesystem');

        if ($store_driver === 'filesystem') {
            $path = $this->getFilesystemVectorStoreFilePath();

            return file_exists($path);
        }

        if ($store_driver === 'elasticsearch') {
            return ElasticsearchRagVectorStore::fromConfig(1)->hasDocuments();
        }

        return true;
    }

    /**
     * @param  list<array{source: string, excerpt: string, score: float|null}>  $citations
     */
    private function appendCitationsToAnswer(string $answer, array $citations): string
    {
        if ($citations === []) {
            return $answer;
        }

        $citation_lines = [];

        foreach ($citations as $index => $citation) {
            $number = $index + 1;
            $source = $citation['source'];
            $citation_lines[] = "[{$number}] {$source}";
        }

        return $answer . "\n\n---\n**Sources:**\n" . implode("\n", $citation_lines);
    }

    /**
     * @return list<array{source: string, excerpt: string, score: float|null}>
     */
    private function buildCitations(object $message): array
    {
        if (! method_exists($message, 'getCitations')) {
            return [];
        }

        $citations = [];

        foreach ($message->getCitations() as $citation) {
            $source = $citation->getSourceName();
            $score = $citation->getScore();
            $content = $citation->getContent();

            $citations[] = [
                'source' => is_string($source) && $source !== '' ? $source : 'Unknown',
                'excerpt' => Str::limit(is_string($content) ? $content : '', 300),
                'score' => is_float($score) || is_int($score) ? (float) $score : null,
            ];
        }

        return $citations;
    }

    private function singlePathPrefix(string $path): string
    {
        $resolved = realpath($path);

        return 'faq-cli-' . mb_substr(hash('sha256', $resolved !== false ? $resolved : $path), 0, 16);
    }

    /**
     * @return list<array{path: string, prefix: string}>
     */
    private function helperRoots(): array
    {
        if (! $this->ragPathsFunctionExists()) {
            return [];
        }

        $roots = [];

        foreach (rag_paths(onlyActive: true, prioritySort: true) as $path) {
            $path = (string) $path;

            $roots[] = [
                'path' => $path,
                'prefix' => $this->prefixFromHelperPath($path),
            ];
        }

        return $roots;
    }

    protected function ragPathsFunctionExists(): bool
    {
        return function_exists('rag_paths');
    }

    private function prefixFromHelperPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $base = str_replace('\\', '/', base_path());

        if (preg_match('#/Modules/([^/]+)/docs/rag/?$#', $normalized, $matches)) {
            return 'faq-module-' . $matches[1];
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

        $splitter = $this->splitter ?? SplitterFactory::make();
        $split_documents = [];

        foreach ($documents as $document) {
            foreach ($splitter->splitDocument($document) as $chunk) {
                $split_documents[] = $chunk;
            }
        }

        if ($split_documents === []) {
            return 0;
        }

        $driver = ai_config_string('ai.features.faq.vector_store', 'filesystem');

        if ($fullRebuild) {
            $this->resetVectorStoreForFullRebuild($driver);
        }

        $use_incremental_reindex = ! $fullRebuild && $this->shouldUseIncrementalReindex($driver);

        $factory = $this->agentFactory ?? DocumentationAgent::make(...);

        /** @var DocumentationAgent $agent */
        $agent = $factory();

        foreach (array_chunk($split_documents, 100) as $batch) {
            if ($use_incremental_reindex) {
                $agent->reindexBySource($batch);

                continue;
            }

            $agent->addDocuments($batch);
        }

        return count($split_documents);
    }

    private function shouldUseIncrementalReindex(string $driver): bool
    {
        if ($driver === 'memory') {
            return true;
        }

        if ($driver === 'elasticsearch') {
            return ElasticsearchRagVectorStore::fromConfig(1)->hasDocuments();
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
        $configured = config('ai.features.faq.vector_store_path');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return storage_path('app/ai/faq-vectorstore.store');
    }

    private function resetVectorStoreForFullRebuild(string $driver): void
    {
        if ($driver === 'memory') {
            DocumentationAgent::resetSharedMemoryVectorStore();

            return;
        }

        if ($driver === 'elasticsearch') {
            ElasticsearchRagVectorStore::fromConfig(1)->clearIndex();

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
            $documents = [...$documents, ...$reader->getDocuments()];
        }

        return array_values($documents);
    }
}

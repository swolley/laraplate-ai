<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use Illuminate\Support\Str;
use LLPhant\Chat\ChatInterface;
use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;
use LLPhant\Embeddings\EmbeddingFormatter\EmbeddingFormatter;
use LLPhant\Embeddings\VectorStores\FileSystem\FileSystemVectorStore;
use LLPhant\Embeddings\VectorStores\Memory\MemoryVectorStore;
use LLPhant\Embeddings\VectorStores\VectorStoreBase;
use LLPhant\Query\SemanticSearch\IdentityTransformer;
use LLPhant\Query\SemanticSearch\QuestionAnswering;
use Modules\AI\Services\Documentation\FileDocumentReader;

final class DocumentationService
{
    private ?VectorStoreBase $memoryStore = null;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
    ) {}

    /**
     * Get the vector store for FAQ/RAG based on config.
     */
    public function getVectorStore(): VectorStoreBase
    {
        $driver = config('ai.features.faq.vector_store', 'filesystem');

        return match ($driver) {
            'memory' => $this->memoryStore ??= new MemoryVectorStore,
            'filesystem' => new FileSystemVectorStore($this->getVectorStorePath()),
            default => throw new Exception("Unsupported FAQ vector store: {$driver}"),
        };
    }

    /**
     * Index documentation from path: read files, split, generate embeddings, save to vector store.
     */
    public function indexDocuments(?string $path = null): int
    {
        $path = $path ?? $this->getDocumentationPath();

        if (! is_dir($path) && ! is_file($path)) {
            return 0;
        }

        $reader = new FileDocumentReader($path);
        $documents = $reader->getDocuments();

        if ($documents === []) {
            return 0;
        }

        $split_documents = [];

        foreach ($documents as $document) {
            $chunks = DocumentSplitter::splitDocument($document);

            foreach ($chunks as $chunk) {
                $split_documents[] = $chunk;
            }
        }

        $formatted = EmbeddingFormatter::formatEmbeddings($split_documents);
        $generator = $this->embeddingService->getEmbeddingGenerator();

        if ($generator === null) {
            throw new Exception('Embedding generator is not configured. Cannot index documentation.');
        }

        $embedded = $generator->embedDocuments($formatted);
        $store = $this->getVectorStore();

        if ($store instanceof FileSystemVectorStore) {
            $path = $this->getVectorStorePath();
            $dir = dirname($path);

            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            if (file_exists($path)) {
                $store->deleteStore();
            }
        }

        $store->addDocuments($embedded);

        return count($embedded);
    }

    /**
     * Answer a question using RAG (vector search + LLM). Returns answer and citations for message metadata.
     *
     * @return array{answer: string, citations: array<int, array{source: string, excerpt: string, score: float|null}>}
     */
    public function answerQuestion(string $question, ChatInterface $chat): array
    {
        $generator = $this->embeddingService->getEmbeddingGenerator();

        if ($generator === null) {
            throw new Exception('Embedding generator is not configured. Cannot answer question.');
        }

        $store = $this->getVectorStore();
        $k = config('ai.features.faq.max_documents', 5);

        $qa = new QuestionAnswering(
            $store,
            $generator,
            $chat,
            new IdentityTransformer,
        );

        $answer = $qa->answerQuestion($question, $k);
        $retrieved = $qa->getRetrievedDocuments();

        $citations = [];

        foreach ($retrieved as $doc) {
            $citations[] = [
                'source' => $doc->sourceName,
                'excerpt' => Str::limit($doc->content, 300),
                'score' => null,
            ];
        }

        // Format citations as markdown if enabled
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
     * Check if FAQ/RAG is enabled and documentation is indexed (for filesystem: file exists).
     */
    public function isAvailable(): bool
    {
        if (! config('ai.features.faq.enabled', true)) {
            return false;
        }

        $store = $this->getVectorStore();

        if ($store instanceof FileSystemVectorStore) {
            $path = $this->getVectorStorePath();

            return file_exists($path) && ! $store->isEmpty();
        }

        return true;
    }

    /**
     * Append formatted citations to the answer.
     */
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

    private function getDocumentationPath(): string
    {
        return config('ai.features.faq.documentation_path')
            ?? resource_path('docs');
    }

    private function getVectorStorePath(): string
    {
        return config('ai.features.faq.vector_store_path')
            ?? storage_path('app/ai/faq-vectorstore.json');
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Closure;
use Illuminate\Support\Str;
use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Services\Documentation\FileDocumentReader;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Splitter\SentenceTextSplitter;

final readonly class DocumentationService
{
    /**
     * @param  Closure(): DocumentationAgent|null  $agentFactory  Optional factory for testing
     */
    public function __construct(
        private ?Closure $agentFactory = null,
    ) {}

    /**
     * Index documentation from path: read files, split, generate embeddings, save to vector store.
     */
    public function indexDocuments(?string $path = null): int
    {
        $path ??= $this->getDocumentationPath();

        if (! is_dir($path) && ! is_file($path)) {
            return 0;
        }

        $reader = new FileDocumentReader($path);
        $documents = $reader->getDocuments();

        if ($documents === []) {
            return 0;
        }

        $splitter = new SentenceTextSplitter;
        $split_documents = [];

        foreach ($documents as $document) {
            $chunks = $splitter->splitDocument($document);

            foreach ($chunks as $chunk) {
                $split_documents[] = $chunk;
            }
        }

        $factory = $this->agentFactory ?? DocumentationAgent::make(...);

        /** @var DocumentationAgent $agent */
        $agent = $factory();
        $agent->addDocuments($split_documents);

        return count($split_documents);
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
            $path = (string) config('ai.features.faq.vector_store_path') ?: storage_path('app/ai/faq-vectorstore.store');

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

    private function getDocumentationPath(): string
    {
        return (string) config('ai.features.faq.documentation_path') ?: resource_path('docs');
    }
}

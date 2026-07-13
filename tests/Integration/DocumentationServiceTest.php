<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Services\DocumentationService;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Splitter\SplitterInterface;

it('indexes documentation chunks in bounded batches', function (): void {
    $source = file_get_contents(base_path('Modules/AI/app/Services/DocumentationService.php'));

    expect($source)->toContain('array_chunk($split_documents, 100)')
        ->and($source)->not->toContain('$agent->addDocuments($split_documents);');
});

it('indexDocuments returns 0 for invalid path', function (): void {
    $service = new DocumentationService;

    expect($service->indexDocuments('/nonexistent/path/xyz'))->toBe(0);
});

it('indexDocuments returns 0 for empty directory', function (): void {
    $emptyDir = sys_get_temp_dir() . '/ai-docs-empty-' . uniqid();
    mkdir($emptyDir, 0755, true);

    $service = new DocumentationService;

    try {
        expect($service->indexDocuments($emptyDir))->toBe(0);
    } finally {
        rmdir($emptyDir);
    }
});

it('isAvailable returns false when feature disabled', function (): void {
    config()->set('ai.features.faq.enabled', false);

    $service = new DocumentationService;

    expect($service->isAvailable())->toBeFalse();
});

it('isAvailable checks filesystem vector store path', function (): void {
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', '/nonexistent/store.path');

    $service = new DocumentationService;

    expect($service->isAvailable())->toBeFalse();
});

it('isAvailable returns true for non-filesystem drivers', function (): void {
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'memory');

    $service = new DocumentationService;

    expect($service->isAvailable())->toBeTrue();
});

it('appendCitationsToAnswer formats citations correctly', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'appendCitationsToAnswer');

    $citations = [
        ['source' => 'doc1.md', 'excerpt' => 'Excerpt 1', 'score' => 0.9],
        ['source' => 'doc2.md', 'excerpt' => 'Excerpt 2', 'score' => 0.8],
    ];

    $result = $method->invoke($service, 'The answer is here.', $citations);

    expect($result)->toContain('The answer is here.')
        ->toContain('---')
        ->toContain('**Sources:**')
        ->toContain('[1] doc1.md')
        ->toContain('[2] doc2.md');
});

it('appendCitationsToAnswer returns answer unchanged for empty citations', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'appendCitationsToAnswer');

    $result = $method->invoke($service, 'Plain answer', []);

    expect($result)->toBe('Plain answer');
});

it('preserves a mermaid block intact when indexing markdown documentation', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-mermaid-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $mermaid = "```mermaid\nflowchart LR\n  A --> B\n  B --> C\n  C --> D\n```";
    $body = "# Module diagram\n\nDescription paragraph one.\n\n" . $mermaid . "\n\nFurther paragraph after the diagram.";

    file_put_contents($tmpDir . '/diagram.md', $body);

    config()->set('ai.features.faq.vector_store', 'memory');
    config()->set('ai.features.faq.splitter.driver', 'markdown_aware');
    config()->set('ai.features.faq.splitter.max_words', 250);
    config()->set('ai.features.faq.splitter.prepend_heading_breadcrumb', false);

    /** @var array<int, Document> $captured */
    $captured = [];
    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('reindexBySource')
        ->with(Mockery::on(function (array $docs) use (&$captured): bool {
            $captured = $docs;

            return true;
        }))
        ->once();

    $service = new DocumentationService(fn (): DocumentationAgent => $agentMock);

    try {
        expect($service->indexDocuments($tmpDir))->toBeGreaterThan(0);

        $mermaid_chunks = array_filter(
            $captured,
            static fn (Document $document): bool => str_contains((string) $document->getContent(), '```mermaid'),
        );

        expect($mermaid_chunks)->toHaveCount(1);

        $first = array_values($mermaid_chunks)[0];
        $content = (string) $first->getContent();

        expect(mb_substr_count($content, '```'))->toBe(2);
        expect($content)->toContain('A --> B');
        expect($content)->toContain('C --> D');
    } finally {
        unlink($tmpDir . '/diagram.md');
        rmdir($tmpDir);
    }
});

it('honors a custom SplitterInterface passed to the constructor', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-splitter-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Title\n\nContent.");

    config()->set('ai.features.faq.vector_store', 'memory');

    $splitterCalls = 0;
    $customSplitter = new class($splitterCalls) implements SplitterInterface
    {
        public function __construct(public int &$calls) {}

        public function splitDocument(Document $document): array
        {
            $this->calls++;

            return [$document];
        }

        public function splitDocuments(array $documents): array
        {
            $out = [];

            foreach ($documents as $document) {
                $out = array_merge($out, $this->splitDocument($document));
            }

            return $out;
        }
    };

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('reindexBySource')->once();

    $service = new DocumentationService(fn (): DocumentationAgent => $agentMock, $customSplitter);

    try {
        $service->indexDocuments($tmpDir);

        expect($splitterCalls)->toBeGreaterThanOrEqual(1);
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
    }
});

it('indexDocuments indexes documents and returns count', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Test Doc\n\nSome content for indexing.");

    $tmpStore = sys_get_temp_dir() . '/ai-vs-' . uniqid() . '.store';
    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', $tmpStore);
    @unlink($tmpStore);

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('addDocuments')
        ->with(Mockery::on(fn ($docs): bool => is_array($docs) && count($docs) >= 1))
        ->once();

    $service = new DocumentationService(fn () => $agentMock);

    try {
        $count = $service->indexDocuments($tmpDir);
        expect($count)->toBeGreaterThan(0);
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
        @unlink($tmpStore);
    }
});

it('indexDocuments uses reindexBySource when vector store driver is memory', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-mem-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Doc\n\nParagraph.");

    config()->set('ai.features.faq.vector_store', 'memory');

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('reindexBySource')
        ->with(Mockery::on(fn ($docs): bool => is_array($docs) && count($docs) >= 1))
        ->once();
    $agentMock->shouldNotReceive('addDocuments');

    $service = new DocumentationService(fn () => $agentMock);

    try {
        expect($service->indexDocuments($tmpDir))->toBeGreaterThan(0);
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
    }
});

it('indexDocuments with full rebuild uses addDocuments on memory driver', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-full-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Doc\n\nBody.");

    config()->set('ai.features.faq.vector_store', 'memory');

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('addDocuments')
        ->with(Mockery::on(fn ($docs): bool => is_array($docs) && count($docs) >= 1))
        ->once();
    $agentMock->shouldNotReceive('reindexBySource');

    $service = new DocumentationService(fn () => $agentMock);

    try {
        expect($service->indexDocuments($tmpDir, true))->toBeGreaterThan(0);
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
    }
});

it('indexDocuments with full rebuild removes filesystem vector store file before indexing', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-fsfull-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Doc\n\nMore.");

    $tmpStore = sys_get_temp_dir() . '/ai-vs-full-' . uniqid() . '.store';
    file_put_contents($tmpStore, "stale\n");

    config()->set('ai.features.faq.vector_store', 'filesystem');
    config()->set('ai.features.faq.vector_store_path', $tmpStore);

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('addDocuments')->once();

    $service = new DocumentationService(fn () => $agentMock);

    try {
        $service->indexDocuments($tmpDir, true);
        expect(file_exists($tmpStore))->toBeFalse();
    } finally {
        unlink($tmpDir . '/readme.md');
        rmdir($tmpDir);
        @unlink($tmpStore);
    }
});

it('answerQuestion returns answer and citations from mocked agent', function (): void {
    config()->set('ai.features.faq.format_citations', true);

    $messageMock = Mockery::mock(NeuronAI\Chat\Messages\Message::class);
    $messageMock->shouldReceive('getContent')->andReturn('The answer is X.');
    $messageMock->shouldReceive('getCitations')->andReturn([]);

    $responseMock = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $responseMock->shouldReceive('getMessage')->andReturn($messageMock);

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('chat')->with(Mockery::type(UserMessage::class))->andReturn($responseMock);

    $service = new DocumentationService(fn () => $agentMock);

    $result = $service->answerQuestion('What is X?');

    expect($result)->toHaveKeys(['answer', 'citations'])
        ->and($result['answer'])->toBe('The answer is X.')
        ->and($result['citations'])->toBe([]);
});

it('answerQuestion appends citations when format_citations enabled', function (): void {
    config()->set('ai.features.faq.format_citations', true);

    $citation = new class
    {
        public function getSourceName(): string
        {
            return 'doc.md';
        }

        public function getContent(): string
        {
            return 'Excerpt from doc.';
        }

        public function getScore(): float
        {
            return 0.95;
        }
    };

    $message = new class($citation) extends NeuronAI\Chat\Messages\AssistantMessage
    {
        public function __construct(
            private readonly object $citation,
        ) {
            parent::__construct('The answer.');
        }

        public function getCitations(): array
        {
            return [$this->citation];
        }
    };

    $responseMock = Mockery::mock(NeuronAI\Agent\AgentHandler::class);
    $responseMock->shouldReceive('getMessage')->andReturn($message);

    $agentMock = Mockery::mock(DocumentationAgent::class);
    $agentMock->shouldReceive('chat')->with(Mockery::type(UserMessage::class))->andReturn($responseMock);

    $service = new DocumentationService(fn () => $agentMock);

    $result = $service->answerQuestion('Question?');

    expect($result['answer'])->toContain('The answer.')
        ->toContain('---')
        ->toContain('**Sources:**')
        ->toContain('[1] doc.md')
        ->and($result['citations'])->toHaveCount(1)
        ->and($result['citations'][0]['source'])->toBe('doc.md');
});

it('includes configured absolute paths when resolving helper roots', function (): void {
    $rag_dir = sys_get_temp_dir() . '/ai-rag-roots-' . uniqid();
    mkdir($rag_dir, 0755, true);

    config()->set('ai.features.faq.documentation_path', $rag_dir);

    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'helperRoots');
    $roots = $method->invoke($service);

    try {
        expect(collect($roots)->firstWhere('path', $rag_dir))->toMatchArray([
            'path' => $rag_dir,
            'prefix' => 'faq-config',
        ]);
    } finally {
        rmdir($rag_dir);
    }
});

it('maps helper rag paths to stable source prefixes', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'prefixFromHelperPath');

    expect($method->invoke($service, base_path('Modules/CMS/docs/rag')))->toBe('faq-module-CMS')
        ->and($method->invoke($service, base_path('docs/rag')))->toBe('faq-app-rag')
        ->and($method->invoke($service, '/tmp/custom-rag'))->toBe('faq-config');
});

it('returns zero when the splitter yields no chunks', function (): void {
    $tmp_dir = sys_get_temp_dir() . '/ai-docs-empty-split-' . uniqid();
    mkdir($tmp_dir, 0755, true);
    file_put_contents($tmp_dir . '/readme.md', '# Title');

    config()->set('ai.features.faq.vector_store', 'memory');

    $empty_splitter = new class implements SplitterInterface
    {
        public function splitDocument(Document $document): array
        {
            return [];
        }

        public function splitDocuments(array $documents): array
        {
            return [];
        }
    };

    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldNotReceive('addDocuments');
    $agent_mock->shouldNotReceive('reindexBySource');

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock, $empty_splitter);

    try {
        expect($service->indexDocuments($tmp_dir))->toBe(0);
    } finally {
        unlink($tmp_dir . '/readme.md');
        rmdir($tmp_dir);
    }
});

it('falls back to the default filesystem vector store path', function (): void {
    config()->set('ai.features.faq.vector_store_path', null);

    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'getFilesystemVectorStoreFilePath');

    expect($method->invoke($service))->toBe(storage_path('app/ai/faq-vectorstore.store'));
});

it('uses helper roots when indexing without an explicit path', function (): void {
    $rag_dir = sys_get_temp_dir() . '/ai-rag-null-path-' . uniqid();
    mkdir($rag_dir, 0755, true);
    file_put_contents($rag_dir . '/guide.md', "# Guide\n\nBody.");

    config()->set('ai.features.faq.documentation_path', $rag_dir);
    config()->set('ai.features.faq.vector_store', 'memory');

    $agent_mock = Mockery::mock(DocumentationAgent::class);
    $agent_mock->shouldReceive('reindexBySource')
        ->with(Mockery::on(static fn (array $docs): bool => count($docs) > 0 && count($docs) <= 100))
        ->atLeast()
        ->once();

    $service = new DocumentationService(fn (): DocumentationAgent => $agent_mock);

    try {
        expect($service->indexDocuments())->toBeGreaterThan(0);
    } finally {
        unlink($rag_dir . '/guide.md');
        rmdir($rag_dir);
    }
});

it('clears elasticsearch index during full rebuild', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'resetVectorStoreForFullRebuild');

    expect(fn () => $method->invoke($service, 'elasticsearch'))->not->toThrow(Throwable::class);
});

it('checks elasticsearch store population for incremental reindex', function (): void {
    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'shouldUseIncrementalReindex');

    expect($method->invoke($service, 'elasticsearch'))->toBeBool();
});

it('returns no helper roots when rag_paths is unavailable', function (): void {
    $service = new class extends DocumentationService
    {
        protected function ragPathsFunctionExists(): bool
        {
            return false;
        }
    };

    $method = new ReflectionMethod(DocumentationService::class, 'helperRoots');

    expect($method->invoke($service))->toBe([]);
});

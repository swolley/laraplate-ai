<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Services\DocumentationService;
use NeuronAI\Chat\Messages\UserMessage;

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

it('getDocumentationPath uses config', function (): void {
    config()->set('ai.features.faq.documentation_path', '/custom/docs/path');

    $service = new DocumentationService;
    $method = new ReflectionMethod($service, 'getDocumentationPath');

    $result = $method->invoke($service);

    expect($result)->toBe('/custom/docs/path');
});

it('indexDocuments indexes documents and returns count', function (): void {
    $tmpDir = sys_get_temp_dir() . '/ai-docs-' . uniqid();
    mkdir($tmpDir, 0755, true);
    file_put_contents($tmpDir . '/readme.md', "# Test Doc\n\nSome content for indexing.");

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

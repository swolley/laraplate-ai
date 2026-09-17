<?php

declare(strict_types=1);

it('AI RAG MODULE.md includes mermaid diagrams for core flows', function (): void {
    $path = base_path('Modules/AI/docs/rag/MODULE.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    // Five, not six: the chat-message-flow diagram went with the code it described,
    // when the HTTP boundary moved from ChatService to InAppAssistanceService.
    expect(mb_substr_count($content, '```mermaid'))->toBeGreaterThanOrEqual(5)
        ->and($content)->toContain('DocumentationAgent')
        ->and($content)->toContain('SplitterFactory')
        ->and($content)->toContain('ToolRegistry')
        ->and($content)->toContain('### Perimeters')
        ->and($content)->toContain('#### RAG indexing pipeline')
        ->and($content)->toContain('#### RAG question answering flow')
        ->and($content)->toContain('### Message orchestration');
});

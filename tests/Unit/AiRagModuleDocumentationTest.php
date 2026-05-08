<?php

declare(strict_types=1);

it('AI RAG MODULE.md includes mermaid diagrams for core flows', function (): void {
    $path = base_path('Modules/AI/docs/rag/MODULE.md');

    expect(file_exists($path))->toBeTrue();

    $content = (string) file_get_contents($path);

    expect(substr_count($content, '```mermaid'))->toBeGreaterThanOrEqual(6)
        ->and($content)->toContain('DocumentationAgent')
        ->and($content)->toContain('SplitterFactory')
        ->and($content)->toContain('ToolRegistry')
        ->and($content)->toContain('### Module boundaries')
        ->and($content)->toContain('#### RAG indexing pipeline')
        ->and($content)->toContain('#### Tools and approval wiring');
});

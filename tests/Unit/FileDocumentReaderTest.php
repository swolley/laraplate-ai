<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\FileDocumentReader;
use NeuronAI\RAG\Document;

it('returns empty array for non-existent path', function (): void {
    $reader = new FileDocumentReader('/nonexistent/path/xyz');

    expect($reader->getDocuments())->toBe([]);
});

it('reads a single markdown file', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'doc');
    unlink($tempFile);
    $tempFile .= '.md';
    file_put_contents($tempFile, '# Title\n\nContent here');

    $reader = new FileDocumentReader($tempFile);
    $documents = $reader->getDocuments();

    expect($documents)->toHaveCount(1)
        ->and($documents[0])->toBeInstanceOf(Document::class)
        ->and($documents[0]->content)->toContain('Title')
        ->and($documents[0]->sourceType)->toBe('files')
        ->and($documents[0]->sourceName)->toContain('.md');

    unlink($tempFile);
});

it('reads a directory of files recursively', function (): void {
    $tempDir = sys_get_temp_dir() . '/doc_test_' . uniqid();
    mkdir($tempDir);
    mkdir($tempDir . '/sub');
    file_put_contents($tempDir . '/root.md', '# Root');
    file_put_contents($tempDir . '/page.html', '<h1>HTML</h1><p>Content</p>');
    file_put_contents($tempDir . '/sub/nested.md', '# Nested');

    $reader = new FileDocumentReader($tempDir);
    $documents = $reader->getDocuments();

    expect($documents)->toHaveCount(3);

    $names = array_map(fn (Document $d): string => $d->sourceName, $documents);
    expect($names)->toContain('root.md')
        ->and($names)->toContain('page.html')
        ->and($names)->toContain('nested.md');

    foreach (glob($tempDir . '/sub/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($tempDir . '/sub');

    foreach (glob($tempDir . '/*') ?: [] as $f) {
        unlink($f);
    }
    rmdir($tempDir);
});

it('ignores files with unsupported extensions', function (): void {
    $tempDir = sys_get_temp_dir() . '/doc_test_' . uniqid();
    mkdir($tempDir);
    file_put_contents($tempDir . '/allowed.md', '# Allowed');
    file_put_contents($tempDir . '/ignore.txt', 'Ignore me');
    file_put_contents($tempDir . '/also.pdf', 'PDF content');

    $reader = new FileDocumentReader($tempDir);
    $documents = $reader->getDocuments();

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->sourceName)->toBe('allowed.md');

    unlink($tempDir . '/allowed.md');
    unlink($tempDir . '/ignore.txt');
    unlink($tempDir . '/also.pdf');
    rmdir($tempDir);
});

it('normalizes HTML content by stripping tags', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'doc');
    unlink($tempFile);
    $tempFile .= '.html';
    file_put_contents($tempFile, '<html><body><h1>Header</h1><p>Paragraph with   spaces</p></body></html>');

    $reader = new FileDocumentReader($tempFile);
    $documents = $reader->getDocuments();

    expect($documents[0]->content)->not->toContain('<')
        ->and($documents[0]->content)->toContain('Header')
        ->and($documents[0]->content)->toContain('Paragraph');

    unlink($tempFile);
});

it('returns Document with correct sourceType sourceName and id', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'doc');
    unlink($tempFile);
    $tempFile .= '.md';
    $content = '# Test';
    file_put_contents($tempFile, $content);

    $reader = new FileDocumentReader($tempFile);
    $documents = $reader->getDocuments();

    expect($documents[0]->sourceType)->toBe('files')
        ->and($documents[0]->sourceName)->toBe(basename($tempFile))
        ->and($documents[0]->id)->toBe(hash('sha256', $content));

    unlink($tempFile);
});

it('returns empty array for single file with unsupported extension', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'test');
    $txt_file = $tmp . '.txt';
    rename($tmp, $txt_file);
    file_put_contents($txt_file, 'some content');

    $reader = new FileDocumentReader($txt_file);
    expect($reader->getDocuments())->toBe([]);

    unlink($txt_file);
});

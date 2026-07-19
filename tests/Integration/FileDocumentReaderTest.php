<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modules\AI\Services\Documentation\FileDocumentReader;

it('returns no documents when the path does not exist', function (): void {
    $reader = new FileDocumentReader(sys_get_temp_dir() . '/lp-reader-missing-' . uniqid());

    expect($reader->getDocuments())->toBe([]);
});

it('reads a single markdown file from a file path', function (): void {
    $path = sys_get_temp_dir() . '/lp-reader-single-' . uniqid() . '.md';
    file_put_contents($path, '# Hello docs');

    try {
        $reader = new FileDocumentReader($path);
        $documents = $reader->getDocuments();

        expect($documents)->toHaveCount(1)
            ->and($documents[0]->getContent())->toBe('# Hello docs')
            ->and($documents[0]->getSourceName())->toBe(basename($path))
            ->and($documents[0]->sourceType)->toBe('files');
    } finally {
        @unlink($path);
    }
});

it('returns no documents when a file path has an unsupported extension', function (): void {
    $path = sys_get_temp_dir() . '/lp-reader-bad-' . uniqid() . '.txt';
    file_put_contents($path, 'ignored');

    try {
        expect((new FileDocumentReader($path))->getDocuments())->toBe([]);
    } finally {
        @unlink($path);
    }
});

it('uses nested relative paths and prefix in source names', function (): void {
    $base = sys_get_temp_dir() . '/lp-reader-' . uniqid();
    mkdir($base . '/nested', 0755, true);
    file_put_contents($base . '/nested/deep.md', '# Deep');

    try {
        $reader = new FileDocumentReader($base, FileDocumentReader::DOCUMENT_EXTENSIONS, 'faq-module-Core');
        $documents = $reader->getDocuments();

        expect($documents)->toHaveCount(1)
            ->and($documents[0]->getSourceName())->toBe('faq-module-Core/nested/deep.md');
    } finally {
        File::deleteDirectory($base);
    }
});

it('ignores unsupported extensions when scanning a directory', function (): void {
    $base = sys_get_temp_dir() . '/lp-reader-ext-' . uniqid();
    mkdir($base, 0755, true);
    file_put_contents($base . '/notes.md', '# Keep');
    file_put_contents($base . '/ignore.txt', 'Skip me');

    try {
        $documents = (new FileDocumentReader($base))->getDocuments();

        expect($documents)->toHaveCount(1)
            ->and($documents[0]->getSourceName())->toBe('notes.md');
    } finally {
        File::deleteDirectory($base);
    }
});

it('strips html markup when indexing html files', function (): void {
    $base = sys_get_temp_dir() . '/lp-reader-html-' . uniqid();
    mkdir($base, 0755, true);
    file_put_contents($base . '/page.html', "<html><body><h1>Title</h1>\n<p>Body</p></body></html>");

    try {
        $documents = (new FileDocumentReader($base))->getDocuments();

        expect($documents)->toHaveCount(1)
            ->and($documents[0]->getContent())->toBe('Title Body');
    } finally {
        File::deleteDirectory($base);
    }
});

it('extracts RAG front matter into document metadata without indexing it as content', function (): void {
    $path = sys_get_temp_dir() . '/lp-reader-front-matter-' . uniqid() . '.md';
    file_put_contents($path, <<<'MARKDOWN'
---
audience: user
module: CMS
locale: it
canonical_source: cms/content/editing
safe_source_label: Modifica dei contenuti
required_permissions:
  - cms.content.view
tenant_scope: global
version: '1.0'
policy_classification: user_safe
policy_classification_version: in-app-docs-v1
---
# Modifica

Apri il contenuto e seleziona Modifica.
MARKDOWN);

    try {
        $document = (new FileDocumentReader($path))->getDocuments()[0];

        expect($document->getContent())->toStartWith('# Modifica')
            ->and($document->getContent())->not->toContain('policy_classification')
            ->and($document->metadata)->toMatchArray([
                'audience' => 'user',
                'module' => 'CMS',
                'required_permissions' => ['cms.content.view'],
                'tenant_scope' => 'global',
            ]);
    } finally {
        @unlink($path);
    }
});

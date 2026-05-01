<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation;

use NeuronAI\RAG\Document;

/**
 * Reads markdown and HTML files from a path (file or directory) for documentation indexing.
 */
final readonly class FileDocumentReader
{
    /**
     * @var list<string>
     */
    public const array DOCUMENT_EXTENSIONS = ['md', 'markdown', 'html', 'htm'];

    /**
     * @var string[]
     */
    private const array DEFAULT_EXTENSIONS = self::DOCUMENT_EXTENSIONS;

    /**
     * @param  string[]  $extensions
     * @param  string  $source_name_prefix  Prepended to each document sourceName (use when merging several roots to avoid collisions).
     */
    public function __construct(
        private string $file_path,
        private array $extensions = self::DEFAULT_EXTENSIONS,
        private string $source_name_prefix = '',
    ) {}

    /**
     * @return Document[]
     */
    public function getDocuments(): array
    {
        if (! file_exists($this->file_path)) {
            return [];
        }

        if (is_dir($this->file_path)) {
            return $this->getDocumentsFromDirectory($this->file_path, '');
        }

        $content = $this->getContentFromFile($this->file_path);

        if ($content === false) {
            return [];
        }

        return [$this->createDocument($content, basename($this->file_path))];
    }

    /**
     * @return Document[]
     */
    private function getDocumentsFromDirectory(string $directory, string $relative_prefix): array
    {
        $documents = [];
        $entries = scandir($directory);

        if ($entries === false) {
            return []; // @codeCoverageIgnore
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }
            $full_path = $directory . DIRECTORY_SEPARATOR . $entry;
            $child_relative = $relative_prefix === '' ? $entry : $relative_prefix . '/' . $entry;

            if (is_dir($full_path)) {
                $documents = array_merge(
                    $documents,
                    $this->getDocumentsFromDirectory($full_path, $child_relative),
                );

                continue;
            }

            $content = $this->getContentFromFile($full_path);

            if ($content !== false) {
                $documents[] = $this->createDocument($content, $this->normalizeRelativeSourceName($child_relative));
            }
        }

        return $documents;
    }

    private function normalizeRelativeSourceName(string $relative): string
    {
        return str_replace('\\', '/', $relative);
    }

    private function getContentFromFile(string $path): string|false
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, $this->extensions, true)) {
            return false;
        }

        $content = file_get_contents($path);

        return $content !== false ? $this->normalizeContent($content, $extension) : false;
    }

    private function normalizeContent(string $content, string $extension): string
    {
        if (in_array($extension, ['html', 'htm'], true)) {
            $text = strip_tags($content);

            return mb_trim(preg_replace('/\s+/', ' ', $text) ?? $content);
        }

        return $content;
    }

    private function createDocument(string $content, string $source_name): Document
    {
        $document = new Document($content);
        $document->sourceType = 'files';
        $document->sourceName = $this->qualifiedSourceName($source_name);
        $document->id = hash('sha256', $content);

        return $document;
    }

    private function qualifiedSourceName(string $source_name): string
    {
        if ($this->source_name_prefix === '') {
            return $source_name;
        }

        return $this->source_name_prefix . '/' . $source_name;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation;

use NeuronAI\RAG\Document;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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

        $file = $this->getContentFromFile($this->file_path);

        if ($file === false) {
            return [];
        }

        return [$this->createDocument($file['content'], basename($this->file_path), $file['metadata'])];
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

            $file = $this->getContentFromFile($full_path);

            if ($file !== false) {
                $documents[] = $this->createDocument(
                    $file['content'],
                    $this->normalizeRelativeSourceName($child_relative),
                    $file['metadata'],
                );
            }
        }

        return $documents;
    }

    private function normalizeRelativeSourceName(string $relative): string
    {
        return str_replace('\\', '/', $relative);
    }

    /**
     * @return array{content: string, metadata: array<string, mixed>}|false
     */
    private function getContentFromFile(string $path): array|false
    {
        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, $this->extensions, true)) {
            return false;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return false;
        }

        [$content, $metadata] = $this->extractFrontMatter($content);

        return [
            'content' => $this->normalizeContent($content, $extension),
            'metadata' => $metadata,
        ];
    }

    private function normalizeContent(string $content, string $extension): string
    {
        if (in_array($extension, ['html', 'htm'], true)) {
            $text = strip_tags($content);

            return mb_trim(preg_replace('/\s+/', ' ', $text) ?? $content);
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function createDocument(string $content, string $source_name, array $metadata = []): Document
    {
        $metadata['heading_breadcrumb'] ??= [];

        $document = new Document($content);
        $document->sourceType = 'files';
        $document->sourceName = $this->qualifiedSourceName($source_name);
        $document->id = hash('sha256', $content);
        $document->metadata = $metadata;

        return $document;
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function extractFrontMatter(string $content): array
    {
        if (preg_match('/\A---\R(.*?)\R---\R?/s', $content, $matches) !== 1) {
            return [$content, []];
        }

        $body = mb_substr($content, mb_strlen($matches[0]));

        try {
            $metadata = Yaml::parse($matches[1]);
        } catch (ParseException) {
            return [$body, []];
        }

        return [$body, is_array($metadata) ? $metadata : []];
    }

    private function qualifiedSourceName(string $source_name): string
    {
        if ($this->source_name_prefix === '') {
            return $source_name;
        }

        return $this->source_name_prefix . '/' . $source_name;
    }
}

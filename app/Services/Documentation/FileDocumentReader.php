<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation;

use LLPhant\Embeddings\DataReader\DataReader;
use LLPhant\Embeddings\Document;

/**
 * Reads markdown and HTML files from a path (file or directory) for documentation indexing.
 * Similar to LLPhant's FileDataReader but focused on docs (e.g. resources/docs).
 */
final readonly class FileDocumentReader implements DataReader
{
    /**
     * @var string[]
     */
    private const array DEFAULT_EXTENSIONS = ['md', 'markdown', 'html', 'htm'];

    /**
     * @param  string[]  $extensions
     */
    public function __construct(
        private string $file_path,
        private string $document_class = Document::class,
        private array $extensions = self::DEFAULT_EXTENSIONS,
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
            return $this->getDocumentsFromDirectory($this->file_path);
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
    private function getDocumentsFromDirectory(string $directory): array
    {
        $documents = [];
        $entries = scandir($directory);

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }
            $full_path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($full_path)) {
                $documents = array_merge(
                    $documents,
                    $this->getDocumentsFromDirectory($full_path),
                );
            } else {
                $content = $this->getContentFromFile($full_path);

                if ($content !== false) {
                    $documents[] = $this->createDocument($content, $entry);
                }
            }
        }

        return $documents;
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
        $document = new $this->document_class;
        $document->content = $content;
        $document->sourceType = 'files';
        $document->sourceName = $source_name;
        $document->hash = hash('sha256', $content);

        return $document;
    }
}

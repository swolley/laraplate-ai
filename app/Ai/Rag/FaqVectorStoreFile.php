<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag;

/**
 * The file a filesystem vector store of the documentation keeps its documents in, split the way
 * Neuron's `FileVectorStore` takes it (a directory, a name and an extension that it joins back), so
 * that the store writes exactly the file that the rest of the module checks, sizes and removes.
 */
final readonly class FaqVectorStoreFile
{
    public function __construct(public string $path) {}

    public function directory(): string
    {
        return dirname($this->path);
    }

    public function name(): string
    {
        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    /**
     * With its dot, or empty when the path has none: the form `FileVectorStore` joins to the name.
     */
    public function extension(): string
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);

        return $extension === '' ? '' : '.' . $extension;
    }
}

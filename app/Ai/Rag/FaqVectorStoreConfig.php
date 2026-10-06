<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag;

use function ai_config_string;

/**
 * Where the documentation vectors live, read from one place: which driver holds them, and for the
 * filesystem driver which file. The agent that writes the store and the service that checks it, sizes it
 * and removes it for a full rebuild both ask here, so they cannot disagree about the file.
 */
final class FaqVectorStoreConfig
{
    /**
     * The driver of `ai.features.faq.vector_store` in `config.php`, used when a test or a deployment
     * has no value at all.
     */
    public const string DEFAULT_DRIVER = 'elasticsearch';

    public const string DEFAULT_FILE = 'app/ai/faq-vectorstore.store';

    public static function driver(): string
    {
        return ai_config_string('ai.features.faq.vector_store', self::DEFAULT_DRIVER);
    }

    /**
     * The file of a profile: the configured path, or an explicit one, with `-user` before the
     * extension for the user index, which the developer index does not take.
     */
    public static function file(DocumentationIndexProfile $profile, ?string $path = null): FaqVectorStoreFile
    {
        $configured = config('ai.features.faq.vector_store_path');
        $path = match (true) {
            is_string($path) && $path !== '' => $path,
            is_string($configured) && $configured !== '' => $configured,
            default => storage_path(self::DEFAULT_FILE),
        };

        if ($profile === DocumentationIndexProfile::Developer) {
            return new FaqVectorStoreFile($path);
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $suffix = $extension === '' ? '' : '.' . $extension;
        $base = $suffix === '' ? $path : mb_substr($path, 0, -mb_strlen($suffix));

        return new FaqVectorStoreFile($base . '-user' . $suffix);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Exceptions;

use Modules\AI\Services\Documentation\DocumentationMetadata;
use RuntimeException;

/**
 * A documentation source declares an `audience` outside {@see DocumentationMetadata::AUDIENCES}.
 * Thrown while the sources are read, before anything is written to a documentation index, so the
 * indexing stops and the index keeps what it held.
 */
final class UnknownDocumentAudienceException extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly mixed $audience,
    ) {
        parent::__construct(sprintf(
            'Documentation source "%s" declares an unknown audience %s; expected one of: %s. Fix its front matter and index again.',
            $path,
            is_string($audience) ? '"' . $audience . '"' : 'of type ' . get_debug_type($audience),
            implode(', ', DocumentationMetadata::AUDIENCES),
        ));
    }
}

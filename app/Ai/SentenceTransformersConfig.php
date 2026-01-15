<?php

declare(strict_types=1);

namespace Modules\AI\Ai;

final class SentenceTransformersConfig
{
    public int $timeout = 10;

    public bool $truncate = true;

    public bool $normalizeEmbeddings = true;

    public function __construct(
        public ?string $apiKey = null,
        public string $url = 'http://localhost:8000',
    ) {}
}

<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Rag;

use InvalidArgumentException;

enum DocumentationIndexProfile: string
{
    case Developer = 'developer';
    case User = 'user';

    public function indexName(): string
    {
        self::assertDistinctConfiguration();

        $key = $this === self::Developer ? 'developer_index' : 'user_index';
        $index = config("ai.features.faq.elasticsearch.{$key}");

        if (! is_string($index) || trim($index) === '') {
            throw new InvalidArgumentException("Elasticsearch RAG {$this->value} index name cannot be empty.");
        }

        return trim($index);
    }

    public static function assertDistinctConfiguration(): void
    {
        $developer_index = config('ai.features.faq.elasticsearch.developer_index');
        $user_index = config('ai.features.faq.elasticsearch.user_index');

        if (! is_string($developer_index) || trim($developer_index) === ''
            || ! is_string($user_index) || trim($user_index) === '') {
            throw new InvalidArgumentException('Both Elasticsearch RAG profile indexes must be configured.');
        }

        if (trim($developer_index) === trim($user_index)) {
            throw new InvalidArgumentException('Developer and user Elasticsearch RAG indexes must be distinct.');
        }
    }
}

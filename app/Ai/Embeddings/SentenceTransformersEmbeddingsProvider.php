<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Exception;
use GuzzleHttp\Client;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;

/**
 * Custom NeuronAI embeddings provider for SentenceTransformers API.
 * Communicates with a self-hosted SentenceTransformers REST endpoint.
 */
final class SentenceTransformersEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    private readonly Client $client;

    private int $batch_size_limit = 128;

    public function __construct(
        string $url = 'http://localhost:8000',
        ?string $api_key = null,
        int $timeout = 10,
        private readonly bool $truncate = true,
        private readonly bool $normalize = true,
    ) {
        if (preg_match('/^https?:\/\//', $url) === 0) {
            $url = 'http://' . $url;
        }

        $options = [
            'base_uri' => $url,
            'timeout' => $timeout,
            'read_timeout' => $timeout,
        ];

        if ($api_key !== null && $api_key !== '') {
            $options['headers'] = ['Authorization' => 'Bearer ' . $api_key];
        }

        $this->client = new Client($options);
    }

    /**
     * @return float[]
     */
    public function embedText(string $text): array
    {
        $text = str_replace("\n", ' ', mb_convert_encoding($text, 'UTF-8', 'UTF-8'));

        $response = $this->client->post('embed', [
            'json' => [
                'text' => $text,
                'truncation' => $this->truncate,
                'normalize_embeddings' => $this->normalize,
                'max_length' => $this->getEmbeddingLength(),
            ],
        ]);

        $result = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        throw_unless(is_array($result) && isset($result['embeddings']), Exception::class, 'SentenceTransformers returned unexpected format');

        return $result['embeddings'][0];
    }

    /**
     * @param  Document[]  $documents
     * @return Document[]
     */
    public function embedDocuments(array $documents): array
    {
        $batches = array_chunk($documents, $this->batch_size_limit);
        $processed = [];

        foreach ($batches as $batch) {
            $texts = array_map(
                fn (Document $doc): string => mb_convert_encoding(
                    $doc->formattedContent ?? $doc->content,
                    'UTF-8',
                    'UTF-8',
                ),
                $batch,
            );

            $response = $this->client->post('embed', [
                'json' => [
                    'texts' => $texts,
                    'truncation' => $this->truncate,
                    'normalize_embeddings' => $this->normalize,
                    'max_length' => $this->getEmbeddingLength(),
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            throw_unless(is_array($result) && isset($result['embeddings']), Exception::class, 'SentenceTransformers returned unexpected format');

            $embeddings = $result['embeddings'];
            $batch_count = count($batch);
            $embedding_count = count($embeddings);

            throw_if($embedding_count !== $batch_count, Exception::class, "Embeddings count mismatch: expected {$batch_count}, got {$embedding_count}");

            for ($i = 0; $i < $batch_count; $i++) {
                $batch[$i]->embedding = $embeddings[$i];
                $processed[] = $batch[$i];
            }

            unset($batch, $embeddings, $result);
        }

        return $processed;
    }

    private function getEmbeddingLength(): int
    {
        return 512;
    }
}

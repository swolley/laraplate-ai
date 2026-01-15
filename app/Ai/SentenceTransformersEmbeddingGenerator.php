<?php

declare(strict_types=1);

namespace Modules\AI\Ai;

use Exception;
use GuzzleHttp\Client;
use JsonException;
use LLPhant\Embeddings\Document;
use LLPhant\Embeddings\DocumentUtils;
use LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface;
use Psr\Http\Client\ClientExceptionInterface;

final class SentenceTransformersEmbeddingGenerator implements EmbeddingGeneratorInterface
{
    public Client $client;

    public int $batch_size_limit = 128;

    public bool $truncate = true;

    public bool $normalizeEmbeddings = true;

    public function __construct(SentenceTransformersConfig $config)
    {
        $this->truncate = $config->truncate;
        $this->normalizeEmbeddings = $config->normalizeEmbeddings;

        // Ensure URL has protocol
        $url = $config->url;

        if (in_array(preg_match('/^https?:\/\//', $url), [0, false], true)) {
            $url = 'http://' . $url;
        }

        $options = [
            'base_uri' => $url,
            'timeout' => $config->timeout,
            // 'connect_timeout' => $config->timeout,
            'read_timeout' => $config->timeout,
        ];

        if (! in_array($config->apiKey, [null, '', '0'], true)) {
            $options['headers'] = ['Authorization' => 'Bearer ' . $config->apiKey];
        }

        $this->client = new Client($options);
    }

    /**
     * @return float[]
     */
    public function embedText(string $text): array
    {
        $text = str_replace("\n", ' ', DocumentUtils::toUtf8($text));

        $body = [
            'text' => $text,
            'truncation' => $this->truncate,
            'normalize_embeddings' => $this->normalizeEmbeddings,
            'max_length' => $this->getEmbeddingLength(),
        ];

        $response = $this->client->post('embed', [
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);

        $searchResults = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

        throw_unless(is_array($searchResults), Exception::class, "Request to SentenceTransformers didn't returned an array: " . $response->getBody()->getContents());

        throw_unless(isset($searchResults['embeddings']), Exception::class, "Request to SentenceTransformers didn't returned expected format: " . $response->getBody()->getContents());

        return $searchResults['embeddings'][0];
    }

    public function embedDocument(Document $document): Document
    {
        $text = $document->formattedContent ?? $document->content;
        $document->embedding = $this->embedText($text);

        return $document;
    }

    /**
     * @param  Document[]  $documents
     *
     * @throws ClientExceptionInterface
     * @throws JsonException
     * @throws Exception
     *
     * @return Document[]
     */
    public function embedDocuments(array $documents): array
    {
        throw_if($this->batch_size_limit <= 0, Exception::class, 'Batch size limit must be greater than 0.');

        // Process documents in batches
        $batches = array_chunk($documents, $this->batch_size_limit);
        $processedDocuments = [];

        foreach ($batches as $batch) {
            $texts = array_map(DocumentUtils::class . '::getUtf8Data', $batch);

            $body = [
                'texts' => $texts,
                'truncation' => $this->truncate,
                'normalize_embeddings' => $this->normalizeEmbeddings,
                'max_length' => $this->getEmbeddingLength(),
            ];

            $response = $this->client->post('embed', [
                'body' => json_encode($body, JSON_THROW_ON_ERROR),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $searchResults = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);

            throw_unless(is_array($searchResults), Exception::class, "Request to SentenceTransformers didn't returned an array: " . $response->getBody()->getContents());

            throw_unless(isset($searchResults['embeddings']), Exception::class, "Request to SentenceTransformers didn't returned expected format: " . $response->getBody()->getContents());

            // Assign embeddings to documents in this batch
            $embeddings = $searchResults['embeddings'];
            $batchSize = count($batch);
            $embeddingsCount = count($embeddings);

            // Check if embeddings count matches batch size
            throw_if($embeddingsCount !== $batchSize, Exception::class, sprintf('Embeddings count mismatch: expected %d, got %d. Response: ', $batchSize, $embeddingsCount) . json_encode($searchResults));

            for ($i = 0; $i < $batchSize; $i++) {
                $batch[$i]->embedding = $embeddings[$i];
                $processedDocuments[] = $batch[$i];
            }

            // Force garbage collection after each batch to free memory
            unset($batch, $embeddings, $searchResults);
            gc_collect_cycles();
        }

        return $processedDocuments;
    }

    public function getEmbeddingLength(): int
    {
        return 512;
    }
}

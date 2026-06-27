<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Response\Elasticsearch;
use GuzzleHttp\Psr7\Response;

/**
 * Build an Elasticsearch client response for unit tests.
 *
 * @param  array<string, mixed>|bool  $payload
 */
function make_elasticsearch_response(array|bool $payload = [], int $status = 200): Elasticsearch
{
    $body = is_bool($payload) ? json_encode($payload) : json_encode($payload, JSON_THROW_ON_ERROR);

    $response = new Elasticsearch;
    $response->setResponse(
        new Response(
            $status,
            [
                'Content-Type' => 'application/json',
                'X-Elastic-Product' => 'Elasticsearch',
            ],
            (string) $body,
        ),
        false,
    );

    return $response;
}

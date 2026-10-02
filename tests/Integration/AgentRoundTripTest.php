<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\Agents\DocumentationAgent;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Tests\Stubs\Embeddings\RecordingEmbeddingsProvider;
use Modules\AI\Tests\Stubs\RecordingHttpClient;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;

/**
 * These run a real agent end to end, with only the socket replaced: mocking chat() would
 * hide a broken agent, as it hid DocumentationAgent never calling its parent constructor.
 */
beforeEach(function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://ollama.test');
});

it('completes a chat round trip on a real ChatAgent', function (): void {
    $client = new RecordingHttpClient(['message' => ['role' => 'assistant', 'content' => 'Hello back.']]);
    $provider = ProviderFactory::make('ollama', 'phi3');
    $provider->setHttpClient($client);

    $agent = ChatAgent::make('ollama', 'Be brief.', 'phi3');
    $agent->setAiProvider($provider);

    $answer = $agent->chat(new UserMessage('Hello'))->getMessage()->getContent();

    expect($answer)->toBe('Hello back.')
        ->and($client->lastRequest)->not->toBeNull()
        ->and($client->lastRequest->body['model'])->toBe('phi3');
});

it('completes a chat round trip on a real DocumentationAgent', function (): void {
    $client = new RecordingHttpClient(['message' => ['role' => 'assistant', 'content' => 'The docs do not say.']]);
    $provider = ProviderFactory::make('ollama', 'phi3');
    $provider->setHttpClient($client);
    $embeddings = new RecordingEmbeddingsProvider();

    $agent = DocumentationAgent::make('ollama', 'memory');
    $agent->setAiProvider($provider);
    $agent->setEmbeddingsProvider($embeddings);
    $agent->setVectorStore(new MemoryVectorStore());

    $answer = $agent->chat(new UserMessage('How do I configure search?'))->getMessage()->getContent();

    expect($answer)->toBe('The docs do not say.')
        ->and($embeddings->textsSent)->not->toBeEmpty()
        ->and($client->lastRequest)->not->toBeNull();
});

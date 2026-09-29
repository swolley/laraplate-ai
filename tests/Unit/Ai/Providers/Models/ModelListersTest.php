<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\Providers\Models\AnthropicModelLister;
use Modules\AI\Ai\Providers\Models\ListedModel;
use Modules\AI\Ai\Providers\Models\MistralModelLister;
use Modules\AI\Ai\Providers\Models\OllamaModelLister;
use Modules\AI\Ai\Providers\Models\OpenAiModelLister;
use Modules\AI\Enums\ModelCapability;

/**
 * @param  list<ListedModel>  $models
 * @return array<string, ?list<string>>
 */
function listedModelsById(array $models): array
{
    $by_id = [];

    foreach ($models as $model) {
        $by_id[$model->id] = $model->capabilities === null
            ? null
            : array_map(static fn (ModelCapability $capability): string => $capability->value, $model->capabilities);
    }

    return $by_id;
}

it('lists OpenAI models without capabilities and drops the incompatible families', function (): void {
    Http::fake(['api.openai.com/v1/models' => Http::response(['object' => 'list', 'data' => [
        ['id' => 'gpt-4o'], ['id' => 'text-embedding-3-small'], ['id' => 'whisper-1'], ['id' => 'tts-1'],
        ['id' => 'dall-e-3'], ['id' => 'gpt-image-1'], ['id' => 'omni-moderation-latest'], ['id' => 'davinci-002'],
        ['id' => 'gpt-3.5-turbo-instruct'], ['id' => 'gpt-4o-realtime-preview'], ['id' => 'gpt-4o-transcribe'],
    ]])]);

    expect(listedModelsById((new OpenAiModelLister('sk-test'))->list()))->toBe(['gpt-4o' => null]);

    Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer sk-test'));
});

it('fails loudly when OpenAI answers with an error', function (): void {
    Http::fake(['api.openai.com/*' => Http::response([], 500)]);

    (new OpenAiModelLister('sk-test'))->list();
})->throws(RequestException::class);

it('pages through Anthropic models and reads image input as vision', function (): void {
    Http::fake(['api.anthropic.com/v1/models*' => Http::sequence()
        ->push(['data' => [['id' => 'claude-opus-5', 'capabilities' => ['image_input' => ['supported' => true]]]], 'has_more' => true, 'last_id' => 'claude-opus-5'])
        ->push(['data' => [
            ['id' => 'claude-text-only', 'capabilities' => ['image_input' => ['supported' => false]]],
            ['id' => 'claude-legacy', 'capabilities' => null],
        ], 'has_more' => false, 'last_id' => 'claude-legacy'])]);

    expect(listedModelsById((new AnthropicModelLister('ak-test'))->list()))->toBe([
        'claude-opus-5' => ['chat', 'tools', 'vision'],
        'claude-text-only' => ['chat', 'tools'],
        'claude-legacy' => ['chat', 'tools', 'vision'],
    ]);

    Http::assertSent(static fn (Request $request): bool => $request->hasHeader('x-api-key', 'ak-test')
        && $request->hasHeader('anthropic-version', '2023-06-01'));
    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'after_id=claude-opus-5'));
});

it('stops paging when Anthropic claims more results without a cursor', function (): void {
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => [['id' => 'claude-opus-5', 'capabilities' => null]], 'has_more' => true, 'last_id' => null])]);

    expect((new AnthropicModelLister('ak-test'))->list())->toHaveCount(1);

    Http::assertSentCount(1);
});

it('maps Mistral capabilities and skips archived models', function (): void {
    Http::fake(['api.mistral.ai/v1/models' => Http::response(['object' => 'list', 'data' => [
        ['id' => 'mistral-large-latest', 'capabilities' => ['completion_chat' => true, 'function_calling' => true, 'vision' => false]],
        ['id' => 'pixtral-large-latest', 'capabilities' => ['completion_chat' => true, 'function_calling' => false, 'vision' => true]],
        ['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false, 'function_calling' => false, 'vision' => false]],
        ['id' => 'old-model', 'archived' => true, 'capabilities' => ['completion_chat' => true]],
        ['id' => 'no-capabilities'],
    ]])]);

    expect(listedModelsById((new MistralModelLister('mk-test'))->list()))->toBe([
        'mistral-large-latest' => ['chat', 'tools'],
        'pixtral-large-latest' => ['chat', 'vision'],
        'mistral-embed' => [],
        'no-capabilities' => null,
    ]);
});

it('reads Ollama capabilities per model and falls back to unknown', function (): void {
    Http::fake([
        'ollama.test/api/tags' => Http::response(['models' => [
            ['name' => 'llama3.2:3b'], ['name' => 'old-model:7b'], ['name' => 'nomic-embed-text:latest'], ['name' => 'bge-m3:latest'],
        ]]),
        'ollama.test/api/show' => static fn (Request $request) => match ($request->data()['model']) {
            'llama3.2:3b' => Http::response(['capabilities' => ['completion', 'tools']]),
            'bge-m3:latest' => Http::response(['capabilities' => ['embedding']]),
            default => Http::response([], 404),
        },
    ]);

    expect(listedModelsById((new OllamaModelLister('http://ollama.test/'))->list()))->toBe([
        'llama3.2:3b' => ['chat', 'tools'],
        'old-model:7b' => null,
        'bge-m3:latest' => [],
    ]);
});

it('fails when Ollama cannot be reached', function (): void {
    Http::fake(['ollama.test/*' => Http::failedConnection('Connection refused')]);

    (new OllamaModelLister('http://ollama.test'))->list();
})->throws(ConnectionException::class);

it('treats unknown capabilities as satisfying any requirement', function (): void {
    expect((new ListedModel('m', null))->satisfies([ModelCapability::Vision]))->toBeTrue()
        ->and((new ListedModel('m', [ModelCapability::Chat]))->satisfies([ModelCapability::Chat, ModelCapability::Tools]))->toBeFalse()
        ->and((new ListedModel('m', [ModelCapability::Chat, ModelCapability::Tools]))->satisfies([ModelCapability::Chat]))->toBeTrue();
});

it('fails on an answer without a model list instead of listing nothing', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response('<html>proxy error</html>', 200),
        'ollama.test/api/tags' => Http::response(['error' => 'loading']),
    ]);

    expect(fn () => (new OpenAiModelLister('sk-test'))->list())->toThrow(UnexpectedValueException::class)
        ->and(fn () => (new OllamaModelLister('http://ollama.test'))->list())->toThrow(UnexpectedValueException::class);
});

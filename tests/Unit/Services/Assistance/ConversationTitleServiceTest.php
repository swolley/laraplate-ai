<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Data\GeneratedConversationTitle;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\ConversationTitleService;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

/**
 * A title service whose model is Neuron's fake provider, which answers with the given titles in order.
 *
 * @param  list<string>  $replies  raw replies of the model: JSON for the structured output
 */
function titleServiceOn(?FakeAIProvider &$provider, array $replies): ConversationTitleService
{
    $provider = new FakeAIProvider(...array_map(static fn (string $reply): AssistantMessage => new AssistantMessage($reply), $replies));

    return new ConversationTitleService(
        app(AssistantPolicyCompiler::class),
        AssistanceGuardrailPipeline::defaults(),
        static fn (string $system): ChatAgent => ChatAgent::make(systemPrompt: $system)->setAiProvider($provider),
    );
}

function titleReply(string $title): string
{
    return json_encode(['title' => $title], JSON_THROW_ON_ERROR);
}

it('uses the title the model returns when the rules accept it', function (string $title): void {
    $service = titleServiceOn($provider, [titleReply($title)]);

    expect($service->titleFor('How do I export the orders list?', 'Use the export action.', 'en'))->toBe(['title' => $title, 'generated' => true]);

    $provider->assertCallCount(1);
})->with([
    'a plain title' => ['Export the orders list'],
    'an apostrophe inside a word' => ["Layout dell'utente"],
    'accents' => ['Liste più leggibili sul telefono'],
    'two words' => ['Orders export'],
    'five words' => ['How orders export really works'],
    'forty characters' => ['Internationalization settings explained'],
]);

it('asks the model for a structured title, with its rules in the schema', function (): void {
    $service = titleServiceOn($provider, [titleReply('Export the orders list')]);

    $service->titleFor('How do I export the orders list?', 'Use the export action.', 'en');

    $record = $provider->getRecorded()[0];

    expect($record->method)->toBe('structured')
        ->and($record->structuredClass)->toBe(GeneratedConversationTitle::class)
        ->and(json_encode($record->structuredSchema))->toContain('"title"')->toContain('"maxLength":40');
});

it('lets Neuron ask again once when the title breaks a rule, and uses the second answer', function (): void {
    $service = titleServiceOn($provider, [titleReply('"Export the orders list."'), titleReply('Export the orders list')]);

    expect($service->titleFor('How do I export the orders list?', 'Use the export action.', 'en'))->toBe(['title' => 'Export the orders list', 'generated' => true]);

    $provider->assertCallCount(1 + ConversationTitleService::MAX_RETRIES);
});

it('takes the title from the question when no answer of the model keeps to the rules', function (string $broken): void {
    $service = titleServiceOn($provider, [titleReply($broken), titleReply($broken)]);

    expect($service->titleFor('How do I export the whole list of orders to a spreadsheet file?', 'Use the export action.', 'en'))
        ->toBe(['title' => 'How do I export the whole list of orders', 'generated' => false]);

    $provider->assertCallCount(1 + ConversationTitleService::MAX_RETRIES);
})->with([
    'quotes' => ['"Export the orders list"'],
    'typographic quotes' => ['“Export the orders list”'],
    'a full stop' => ['Export the orders list.'],
    'an exclamation mark' => ['Export the orders list!'],
    'an ellipsis' => ['Export the orders list…'],
    'markdown emphasis' => ['**Export** the orders list'],
    'a heading' => ['# Export the orders list'],
    'a code span' => ['`Export` the orders list'],
    'a line break' => ["Export the\norders list"],
    'one word' => ['Export'],
    'six words' => ['How to export the whole orders'],
    'forty-one characters' => ['Internationalization settings explained!!'],
    'nothing' => [''],
    'blanks' => ['   '],
]);

it('takes the title from the question when the model fails or answers with something else than the schema', function (array $replies): void {
    $service = titleServiceOn($provider, $replies);

    expect($service->titleFor('How do I export the orders?', 'Use the export action.', 'en'))
        ->toBe(['title' => 'How do I export the orders', 'generated' => false]);
})->with([
    'no answer at all' => [[]],
    'text instead of JSON' => [['Export the orders list', 'Export the orders list']],
    'JSON without the title' => [['{"name":"Export the orders list"}', '{"name":"Export the orders list"}']],
]);

it('takes the title from the question when the guardrails refuse the title', function (): void {
    $unsafe = titleReply('Use key sk-abcdefghijklmnop1234567890');
    $service = titleServiceOn($provider, [$unsafe]);

    expect($service->titleFor('How do I export the orders?', 'Use the export action.', 'en'))
        ->toBe(['title' => 'How do I export the orders', 'generated' => false]);
});

it('gives the model the two messages as data, under the title policy and with no tool', function (): void {
    $service = titleServiceOn($provider, [titleReply('Export the orders list')]);

    $service->titleFor('How do I export <b>the</b> orders?', 'Use the export action.', 'it');

    $record = $provider->getRecorded()[0];
    $prompt = (string) $record->messages[0]->getContent();
    $policy = app(AssistantPolicyCompiler::class)->compile(Modules\AI\Enums\AssistantProfile::InAppAssistance, [ConversationTitleService::CAPABILITY]);

    $provider->assertSystemPrompt($policy->systemPrompt);
    $provider->assertToolsConfigured([]);

    expect($prompt)->toContain('orders?')->toContain('Use the export action.')->toContain('Language of the user: it')
        // Only the four markers keep their angle brackets: the ones in the question could not close its marker.
        ->and(mb_substr_count($prompt, '<'))->toBe(4)
        ->and($policy->allowedTools)->toBe([])
        ->and($policy->allowedCorpora)->toBe([]);
});

it('bounds what the model is given to what a title needs', function (): void {
    $service = titleServiceOn($provider, [titleReply('Export the orders list')]);

    $service->titleFor(str_repeat('q', 5000), str_repeat('a', 5000), 'en');

    expect(mb_strlen((string) $provider->getRecorded()[0]->messages[0]->getContent()))->toBeLessThan(2 * ConversationTitleService::MAX_INPUT_LENGTH + 400);
});

it('takes the first words of the question, cut at a word boundary to 40 characters', function (string $question, ?string $expected): void {
    expect(ConversationTitleService::fallback($question))->toBe($expected);
})->with([
    'a short question' => ['How do I export the orders?', 'How do I export the orders'],
    'cut at a word boundary' => ['How do I export the whole list of orders to a spreadsheet file', 'How do I export the whole list of orders'],
    'one long word' => [str_repeat('x', 60), str_repeat('x', 40)],
    'quotes and markup are dropped' => ['**"Where"** is `the` export?', 'Where is the export'],
    'a blank first line' => ["\nHow do I export?", 'How do I export'],
    'a single word is allowed' => ['Export?', 'Export'],
    'nothing to use' => ["  \n ", null],
    'only markup' => ['***', null],
    'a long text of accents' => [str_repeat('é', 100) . ' ' . str_repeat('è', 100), str_repeat('é', 40)],
]);

it('writes titles with the model chosen for the chat summaries, capped to what a title needs', function (): void {
    config()->set('ai.features.chat.summary.model', 'ollama:summary-model');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = ConversationTitleService::defaultAgent('Write a title.');
    $read = static fn (string $property): mixed => new ReflectionProperty($agent, $property)->getValue($agent);

    expect($read('providerName'))->toBe('ollama')
        ->and($read('model'))->toBe('summary-model')
        ->and($read('systemPrompt'))->toBe('Write a title.')
        ->and($read('maxOutputTokens'))->toBe(ConversationTitleService::MAX_OUTPUT_TOKENS);
});

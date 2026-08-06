<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Services\Assistance\Scope\AssistantScopeResolver;
use Modules\AI\Services\Assistance\Scope\DataAccess;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\DocumentationService;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\Models\User;
use NeuronAI\RAG\Document;
use NeuronAI\Tools\Tool;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  Closure(string, AssistantAccessContext, AssistantScope): list<Document>  $retrieve
 * @param  Closure(string, string, mixed, list<Tool>): string  $complete
 */
function scopedAssistanceService(
    Request $request,
    Closure $retrieve,
    Closure $complete,
): InAppAssistanceService {
    $toolProvider = Mockery::mock(ContextualToolProviderInterface::class);
    $toolProvider->shouldReceive('tools')->andReturn([]);

    return new InAppAssistanceService(
        app(AssistantAccessContextFactory::class),
        app(AssistantPolicyCompiler::class),
        AssistanceGuardrailPipeline::defaults(),
        app(DocumentationService::class),
        $toolProvider,
        new ToolRegistry,
        app(ChatService::class),
        $request,
        new AssistantScopeResolver,
        $retrieve,
        $complete,
    );
}

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->conversation = Conversation::query()->create([
        'user_id' => $this->user->id,
        'system_message' => null,
    ]);
    $this->request = Request::create('/app/ai/assistance', 'POST');
    $this->request->setUserResolver(fn (): User => $this->user);
});

it('passes a module-scoped AssistantScope to documentation retrieval when a module context is present', function (): void {
    $this->request->attributes->set('assistant_application_context', ['module' => 'erp']);

    $capturedScope = null;
    $service = scopedAssistanceService(
        $this->request,
        function (string $input, AssistantAccessContext $access, AssistantScope $scope) use (&$capturedScope): array {
            $capturedScope = $scope;

            return [];
        },
        static fn (): string => 'Open the ERP module to continue.',
    );

    $service->respond($this->conversation, $this->user, 'How do I use the ERP module?');

    expect($capturedScope)->toBeInstanceOf(AssistantScope::class)
        ->and($capturedScope->moduleKey)->toBe('erp')
        ->and($capturedScope->dataAccess)->toBe(DataAccess::Module);
});

it('resolves DataAccess::None and builds no application-data tools when no module context is present', function (): void {
    $capturedScope = null;
    $capturedTools = null;
    $service = scopedAssistanceService(
        $this->request,
        function (string $input, AssistantAccessContext $access, AssistantScope $scope) use (&$capturedScope): array {
            $capturedScope = $scope;

            return [];
        },
        function (string $input, string $systemPrompt, mixed $context, array $tools) use (&$capturedTools): string {
            $capturedTools = $tools;

            return 'Open Settings and select Profile.';
        },
    );

    $service->respond($this->conversation, $this->user, 'How do I update my profile?');

    expect($capturedScope)->toBeInstanceOf(AssistantScope::class)
        ->and($capturedScope->dataAccess)->toBe(DataAccess::None)
        ->and($capturedTools)->toBe([]);
});

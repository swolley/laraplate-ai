<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Exceptions\AssistancePolicyViolationException;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\ApplicationContent\ApplicationContentCitationMapper;
use Modules\AI\Services\ApplicationContent\Data\ApplicationContentRequestContext;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Policies\CompiledAssistantPolicy;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\DocumentationService;
use Modules\AI\Services\Tools\CompositeContextualToolProvider;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\Tools\Tool;
use Throwable;

final readonly class InAppAssistanceService implements InAppAssistanceServiceInterface
{
    /**
     * @param  (Closure(string, AssistantAccessContext): list<Document>)|null  $documentation_retrieval
     * @param  (Closure(string, string, AssistantPromptContext, list<Tool>): string)|null  $completion
     */
    public function __construct(
        private AssistantAccessContextFactory $access_context_factory,
        private AssistantPolicyCompiler $policy_compiler,
        private AssistanceGuardrailPipeline $guardrails,
        private DocumentationService $documentation,
        private ContextualToolProviderInterface $tool_provider,
        private ToolRegistry $tool_registry,
        private ChatService $chat_service,
        private Request $request,
        private ?Closure $documentation_retrieval = null,
        private ?Closure $completion = null,
        private ?ApplicationContentCitationMapper $application_content_citations = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $request_context
     */
    public function respond(
        Conversation $conversation,
        User $authenticated_user,
        string $user_input,
        ?array $request_context = null,
    ): Message {
        $access = $this->access_context_factory->forInApp($conversation, $authenticated_user);
        $this->assertRequestIdentity($access);
        $application_content = $this->applicationContentCitations();
        $application_content->reset();

        try {
            $policy = $this->policy_compiler->compile(
                AssistantProfile::InAppAssistance,
                ['application_content', 'in_app_rag', 'read_only_graph'],
            );
            $input = $this->guardrails->validateInput($user_input);
            $documents = $this->documentation_retrieval instanceof Closure
                ? ($this->documentation_retrieval)($input, $access)
                : $this->documentation->retrieveForInApp($input, $access);
            $prompt_context = $this->guardrails->validateContext(
                $this->promptContext($policy, $documents, $request_context),
            );
            $tools = $this->contextualTools($access, $input, $policy);

            if ($application_content->clarificationRequired()) {
                $output = $this->guardrails->clarificationRequired($access->locale);
            } else {
                $output = $this->completion instanceof Closure
                    ? ($this->completion)($input, $policy->systemPrompt, $prompt_context, $tools)
                    : $this->complete($input, $policy, $prompt_context, $tools);
            }

            if ($application_content->attempted() && ! $application_content->hasEvidence()) {
                $output = $this->guardrails->insufficientEvidence($access->locale);
            } else {
                $output = $this->guardrails->validateOutput($output);
            }

            $prompt_context = $this->mergeApplicationContent($prompt_context, $application_content);

            $conversation->addMessage('user', $input, $this->presentationPreferences($request_context));

            return $conversation->addMessage('assistant', $output, [
                'citations' => $prompt_context->safeCitations,
            ]);
        } catch (Throwable $exception) {
            $reason_code = $exception instanceof AssistancePolicyViolationException
                ? $exception->reasonCode
                : 'assistance_unavailable';

            Log::notice('In-app assistance refused', [
                'reason_code' => $reason_code,
                'profile' => AssistantProfile::InAppAssistance->value,
                'conversation_id' => $access->conversationId,
            ]);

            return $conversation->addMessage('assistant', $this->safeRefusal(), [
                'refused' => true,
            ]);
        }
    }

    /**
     * @return list<Tool>
     */
    private function contextualTools(
        AssistantAccessContext $access,
        string $input,
        CompiledAssistantPolicy $policy,
    ): array {
        if ($this->tool_provider instanceof CompositeContextualToolProvider) {
            $definitions = $this->tool_provider->toolsForRequest(
                $access,
                $input,
                $this->serverApplicationContext(),
            );

            return $this->tool_registry->getNeuronToolsForDefinitions($definitions, $policy->allowedTools);
        }

        return $this->tool_registry->getContextualNeuronTools(
            $this->tool_provider,
            $access,
            $policy->allowedTools,
        );
    }

    private function serverApplicationContext(): ?ApplicationContentRequestContext
    {
        $context = $this->request->attributes->get('assistant_application_context');

        if (! is_array($context) || ! is_string($context['module'] ?? null)) {
            return null;
        }

        try {
            return new ApplicationContentRequestContext(
                module: $context['module'],
                entity: is_string($context['entity'] ?? null) ? $context['entity'] : null,
                recordKey: is_int($context['record_key'] ?? null) || is_string($context['record_key'] ?? null)
                    ? $context['record_key']
                    : null,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function applicationContentCitations(): ApplicationContentCitationMapper
    {
        return $this->application_content_citations ?? app(ApplicationContentCitationMapper::class);
    }

    private function mergeApplicationContent(
        AssistantPromptContext $context,
        ApplicationContentCitationMapper $applicationContent,
    ): AssistantPromptContext {
        if (! $applicationContent->hasEvidence()) {
            return $context;
        }

        return $this->guardrails->validateContext(new AssistantPromptContext(
            policyVersion: $context->policyVersion,
            presentationPreferences: $context->presentationPreferences,
            safeCitations: array_slice([
                ...$applicationContent->citations(),
                ...$context->safeCitations,
            ], 0, 10),
            authorizedResults: array_slice([
                ...$applicationContent->results(),
                ...$context->authorizedResults,
            ], 0, 50),
        ));
    }

    private function assertRequestIdentity(AssistantAccessContext $access): void
    {
        $request_user_id = $this->request->user()?->getAuthIdentifier();

        if ($request_user_id === null || $access->userId !== mb_trim((string) $request_user_id)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Assistant access context is unavailable.');
        }
    }

    /**
     * @param  list<Document>  $documents
     * @param  array<string, mixed>|null  $request_context
     */
    private function promptContext(
        CompiledAssistantPolicy $policy,
        array $documents,
        ?array $request_context,
    ): AssistantPromptContext {
        $citations = [];
        $authorized_results = [];

        foreach ($documents as $document) {
            if (! $document instanceof Document) {
                throw new AssistancePolicyViolationException('unsafe_document');
            }

            $label = mb_trim($document->getSourceName());

            if ($label === '') {
                throw new AssistancePolicyViolationException('unsafe_citation');
            }

            $excerpt = Str::limit($document->getContent(), 1000);
            $citations[] = [
                'label' => $label,
                'excerpt' => Str::limit($excerpt, 300),
                'score' => $document->getScore(),
            ];
            $authorized_results[] = array_filter([
                'content' => $excerpt,
                'heading_breadcrumb' => $this->metadataString($document, 'heading_breadcrumb'),
                'label' => $label,
                'locale' => $this->metadataString($document, 'locale'),
                'module' => $this->metadataString($document, 'module'),
                'version' => $this->metadataString($document, 'version'),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return new AssistantPromptContext(
            policyVersion: $policy->version,
            presentationPreferences: $this->presentationPreferences($request_context),
            safeCitations: $citations,
            authorizedResults: $authorized_results,
        );
    }

    /**
     * @param  list<Tool>  $tools
     */
    private function complete(
        string $input,
        CompiledAssistantPolicy $policy,
        AssistantPromptContext $prompt_context,
        array $tools,
    ): string {
        $agent = $this->chat_service->buildProtectedAgent($policy, $prompt_context);

        foreach ($tools as $tool) {
            $agent->addTool($tool);
        }

        return $agent->chat(new UserMessage($input))->getMessage()->getContent() ?? '';
    }

    /**
     * @param  array<string, mixed>|null  $request_context
     * @return array<string, mixed>
     */
    private function presentationPreferences(?array $request_context): array
    {
        if ($request_context === null) {
            return [];
        }

        return array_intersect_key($request_context, array_flip([
            'accessibility',
            'locale',
            'response_format',
            'verbosity',
        ]));
    }

    private function metadataString(Document $document, string $key): ?string
    {
        $value = $document->metadata[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function safeRefusal(): string
    {
        return $this->guardrails->validateOutput(
            'I cannot provide that information. I can help you use the features available in the application.',
        );
    }
}

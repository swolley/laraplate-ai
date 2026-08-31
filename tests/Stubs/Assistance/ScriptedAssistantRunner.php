<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Assistance;

use Closure;
use Illuminate\Http\Request;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\AssistantPromptContext;
use Modules\AI\Services\Assistance\Evaluation\AssistantEvaluationCase;
use Modules\AI\Services\Assistance\Scope\AssistantScope;
use Modules\AI\Tests\Stubs\ApplicationContent\InAppAssistanceContentProvider;
use Modules\AI\Tests\Stubs\Documentation\CoreUserDocumentationCorpus;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderInterface;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use NeuronAI\RAG\Document;
use RuntimeException;

/**
 * Drives the real InAppAssistanceService::respond() from an AssistantEvaluationCase,
 * over fake application-content providers and a scripted tool-invoking completion,
 * so the assistant's composition (routing, tool offering, citation assembly,
 * clarification, abstention) can be evaluated deterministically without a live LLM.
 *
 * Reuses ScriptedAssistantFixtures for the exact same service construction as
 * InAppApplicationContentAssistanceTest.php — no duplicated wiring.
 */
final class ScriptedAssistantRunner
{
    private function __construct(
        private readonly User $user,
        private readonly Conversation $conversation,
        private readonly Request $request,
    ) {}

    public static function bootstrap(): self
    {
        $role = Role::factory()->create([
            'name' => config('permission.roles.superadmin'),
            'guard_name' => 'web',
        ]);
        $user = User::factory()->create(['lang' => 'en']);
        $user->assignRole($role);
        auth()->login($user);

        $conversation = Conversation::query()->create([
            'user_id' => $user->id,
            'system_message' => null,
        ]);

        $request = Request::create('/app/ai/assistance', 'POST');
        $request->setUserResolver(fn (): User => $user);

        return new self($user, $conversation, $request);
    }

    public function run(AssistantEvaluationCase $case): Message
    {
        if ($case->moduleKey !== null) {
            $this->request->attributes->set('assistant_application_context', [
                'module' => $case->moduleKey,
            ]);
        } else {
            $this->request->attributes->remove('assistant_application_context');
        }

        $service = ScriptedAssistantFixtures::inAppContentService(
            $this->request,
            $this->providersFor($case),
            $this->completionFor($case),
            null,
            $this->documentationRetrievalFor($case),
        );

        return $service->respond($this->conversation, $this->user, $case->query);
    }

    /**
     * @return list<ApplicationContentRetrievalProviderInterface>
     */
    private function providersFor(AssistantEvaluationCase $case): array
    {
        return match ($case->expectedSurface) {
            'application_content' => [new InAppAssistanceContentProvider(
                $this->contentDescriptorFor($case),
                ScriptedAssistantFixtures::inAppContentResult(
                    source: $this->sourceFor($case),
                    label: $case->expectedCitations[0] ?? 'Publishing guide',
                    reference: "/app/{$case->moduleKey}/contents/5",
                ),
            )],
            'refuse' => [new InAppAssistanceContentProvider(
                $this->contentDescriptorFor($case),
                new ApplicationContentResult($this->sourceFor($case), [], 'lexical', false),
            )],
            // No moduleKey/context: a generic query that matches neither source by
            // name lets ApplicationContentSourceRouter fall through to
            // ClarificationRequired, exactly as in the sibling ambiguous-routing test.
            'clarify' => [
                new InAppAssistanceContentProvider(
                    ScriptedAssistantFixtures::inAppContentDescriptor(),
                    ScriptedAssistantFixtures::inAppContentResult(),
                ),
                new InAppAssistanceContentProvider(
                    ScriptedAssistantFixtures::inAppContentDescriptor('erp.orders', 'erp', 'orders', ['erp', 'orders']),
                    new ApplicationContentResult('erp.orders', [], 'lexical', false),
                ),
            ],
            'documentation', 'graph' => [],
            default => throw new RuntimeException("Unsupported expected surface [{$case->expectedSurface}]."),
        };
    }

    private function completionFor(AssistantEvaluationCase $case): Closure
    {
        return match ($case->expectedSurface) {
            'application_content' => function (
                string $input,
                string $systemPrompt,
                AssistantPromptContext $context,
                array $tools,
            ) use ($case): string {
                ScriptedAssistantFixtures::executeInAppContentTool($tools, $case->query, $this->sourceFor($case));

                return "Scripted application content answer for case [{$case->id}].";
            },
            // Empty evidence still needs the tool invoked so the citation mapper
            // marks the attempt (see ApplicationContentToolProvider::invoke()); only
            // then does respond() rewrite the output as the guardrail's
            // insufficient-evidence refusal (Modules\AI\Services\Assistance\AssistanceGuardrailPipeline).
            'refuse' => function (
                string $input,
                string $systemPrompt,
                AssistantPromptContext $context,
                array $tools,
            ) use ($case): string {
                if ($tools !== []) {
                    ScriptedAssistantFixtures::executeInAppContentTool($tools, $case->query, $this->sourceFor($case));
                }

                return "Scripted refusal-path answer for case [{$case->id}].";
            },
            // Documentation is already retrieved into $context before completion runs;
            // graph tools are mocked empty at this L1 level, so both surfaces just
            // need a scripted answer with no tool invocation.
            'documentation', 'graph' => static fn (
                string $input,
                string $systemPrompt,
                AssistantPromptContext $context,
                array $tools,
            ): string => "Scripted {$case->expectedSurface} answer for case [{$case->id}].",
            // respond() short-circuits to the clarification guardrail before the
            // completion closure is ever invoked (ApplicationContentCitationMapper::
            // clarificationRequired() is set while building tools). Mirrors the
            // sibling ambiguous-routing test's "must not run" assertion.
            'clarify' => static fn (): never => throw new RuntimeException(
                "Completion must not run for case [{$case->id}]: clarification is required.",
            ),
            default => throw new RuntimeException("Unsupported expected surface [{$case->expectedSurface}]."),
        };
    }

    /**
     * @return (Closure(string, AssistantAccessContext, ?AssistantScope): list<Document>)|null
     */
    private function documentationRetrievalFor(AssistantEvaluationCase $case): ?Closure
    {
        if ($case->expectedSurface !== 'documentation') {
            return null;
        }

        $retrieval = CoreUserDocumentationCorpus::retrieval();

        return static fn (string $question, AssistantAccessContext $access, ?AssistantScope $scope): array => $retrieval->retrieve($question, $access, $scope);
    }

    private function contentDescriptorFor(AssistantEvaluationCase $case): ApplicationContentSourceDescriptor
    {
        $module = $case->moduleKey ?? 'cms';

        return ScriptedAssistantFixtures::inAppContentDescriptor(
            source: $this->sourceFor($case),
            module: $module,
            entity: 'contents',
            intents: [$module, 'contents', 'content'],
        );
    }

    private function sourceFor(AssistantEvaluationCase $case): string
    {
        return ($case->moduleKey ?? 'cms') . '.contents';
    }
}

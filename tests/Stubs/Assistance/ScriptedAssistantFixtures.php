<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Assistance;

use Closure;
use Illuminate\Http\Request;
use Mockery;
use Modules\AI\Services\ApplicationContent\ApplicationContentCitationMapper;
use Modules\AI\Services\ApplicationContent\ApplicationContentDeadlineExecutor;
use Modules\AI\Services\ApplicationContent\ApplicationContentPromptProjector;
use Modules\AI\Services\ApplicationContent\ApplicationContentSourceRouter;
use Modules\AI\Services\ApplicationContent\ApplicationContentToolProvider;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\AssistantAccessContextFactory;
use Modules\AI\Services\Assistance\AssistantPromptContext;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Services\Assistance\Scope\AssistantScopeResolver;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\DocumentationService;
use Modules\AI\Services\Tools\CompositeContextualToolProvider;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalProviderRegistry;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalService;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderInterface;
use Modules\Core\ApplicationContent\Data\ApplicationContentHit;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Services\Authorization\AuthorizationService;
use NeuronAI\Tools\Tool;
use PHPUnit\Framework\Assert;

/**
 * Reusable scaffolding for building a real InAppAssistanceService over fake
 * application-content providers and a scripted completion. Canonical copy of
 * the helpers that originally lived at the top of
 * InAppApplicationContentAssistanceTest.php (extracted so ScriptedAssistantRunner
 * can share the exact same construction without duplicating it).
 */
final class ScriptedAssistantFixtures
{
    public static function inAppContentDescriptor(
        string $source = 'cms.contents',
        string $module = 'cms',
        string $entity = 'contents',
        array $intents = ['cms', 'contents', 'content'],
    ): ApplicationContentSourceDescriptor {
        return new ApplicationContentSourceDescriptor(
            $source,
            $module,
            $entity,
            ['en', 'it'],
            ['lexical'],
            $intents,
        );
    }

    public static function inAppContentResult(
        string $excerpt = 'Use the visible publishing controls.',
        string $source = 'cms.contents',
        string $label = 'Publishing guide',
        string $reference = '/app/cms/contents/5',
    ): ApplicationContentResult {
        return new ApplicationContentResult($source, [
            new ApplicationContentHit(
                id: $source . ':5',
                source: $source,
                module: 'cms',
                entity: 'contents',
                recordKey: 5,
                excerpt: $excerpt,
                label: $label,
                canonicalReference: $reference,
                locale: 'en',
                strategy: 'lexical',
                score: 0.9,
                revision: 'rev-1',
                truncated: false,
            ),
        ], 'lexical', false);
    }

    /**
     * @param  list<ApplicationContentRetrievalProviderInterface>  $providers
     * @param  Closure(string, string, AssistantPromptContext, list<Tool>): string  $completion
     * @param  (Closure(string, \Modules\AI\Services\Assistance\AssistantAccessContext, ?\Modules\AI\Services\Assistance\Scope\AssistantScope): list<\NeuronAI\RAG\Document>)|null  $documentationRetrieval
     */
    public static function inAppContentService(
        Request $request,
        array $providers,
        Closure $completion,
        ?ContextualToolProviderInterface $graphTools = null,
        ?Closure $documentationRetrieval = null,
    ): InAppAssistanceService {
        $registry = new ApplicationContentRetrievalProviderRegistry;

        foreach ($providers as $provider) {
            $registry->register($provider);
        }

        $citations = new ApplicationContentCitationMapper;
        $content_tools = new ApplicationContentToolProvider(
            $registry,
            new ApplicationContentRetrievalService($registry, app(AuthorizationService::class)),
            app(AuthorizationService::class),
            new ApplicationContentSourceRouter,
            new ApplicationContentPromptProjector,
            new ApplicationContentDeadlineExecutor,
            $citations,
            AssistanceGuardrailPipeline::defaults(),
            $request,
        );

        if ($graphTools === null) {
            $graphTools = Mockery::mock(ContextualToolProviderInterface::class);
            $graphTools->shouldReceive('tools')->andReturn([]);
        }

        return new InAppAssistanceService(
            app(AssistantAccessContextFactory::class),
            app(AssistantPolicyCompiler::class),
            AssistanceGuardrailPipeline::defaults(),
            app(DocumentationService::class),
            new CompositeContextualToolProvider([$graphTools, $content_tools]),
            new ToolRegistry,
            app(ChatService::class),
            $request,
            new AssistantScopeResolver,
            $documentationRetrieval ?? static fn (): array => [],
            $completion,
            $citations,
        );
    }

    /**
     * @param  list<Tool>  $tools
     */
    public static function executeInAppContentTool(
        array $tools,
        string $query = 'publishing content',
        string $source = 'cms.contents',
    ): void {
        $tool = collect($tools)->first(
            static fn (Tool $candidate): bool => $candidate->getName() === 'application_content_search',
        );

        Assert::assertInstanceOf(Tool::class, $tool);

        $tool->setInputs([
            'source' => $source,
            'query' => $query,
            'locale' => 'en',
            'limit' => 5,
        ]);
        $tool->execute();
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Services\ApplicationContent\Data\ApplicationContentRequestContext;
use Modules\AI\Services\ApplicationContent\Enums\ApplicationContentRoutingStatus;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\AssistantPromptContext;
use Modules\AI\Services\Tools\ContextualToolProviderInterface;
use Modules\AI\Services\Tools\ToolDefinition;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalService;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Models\User;
use Modules\Core\Services\Authorization\AuthorizationService;
use Nwidart\Modules\Facades\Module;
use Throwable;

final readonly class ApplicationContentToolProvider implements ContextualToolProviderInterface
{
    public function __construct(
        private ApplicationContentRetrievalProviderRegistryInterface $providers,
        private ApplicationContentRetrievalService $retrieval,
        private AuthorizationService $authorization,
        private ApplicationContentSourceRouter $router,
        private ApplicationContentPromptProjector $projector,
        private ApplicationContentDeadlineExecutor $deadline,
        private ApplicationContentCitationMapper $citations,
        private AssistanceGuardrailPipeline $guardrails,
        private Request $request,
    ) {}

    public function tools(AssistantAccessContext $context): array
    {
        return $this->toolsForRequest($context, '');
    }

    /**
     * Build a tool only after server-side routing has selected one authorized source.
     *
     * @return list<ToolDefinition>
     */
    public function toolsForRequest(
        AssistantAccessContext $context,
        string $userQuery,
        ?ApplicationContentRequestContext $requestContext = null,
        ?string $explicitSourceIntent = null,
    ): array {
        $descriptors = $this->authorizedDescriptors($context);

        if ($descriptors === []) {
            return [];
        }

        $decision = $this->router->route(
            $userQuery,
            $descriptors,
            $requestContext,
            $explicitSourceIntent,
        );

        if ($decision->status !== ApplicationContentRoutingStatus::Selected
            || $decision->selectedSource === null) {
            return [];
        }

        $selected_source = $decision->selectedSource;
        $selected_descriptor = collect($descriptors)->first(
            static fn (ApplicationContentSourceDescriptor $descriptor): bool => $descriptor->source === $selected_source,
        );

        if (! $selected_descriptor instanceof ApplicationContentSourceDescriptor) {
            return [];
        }

        return [new ToolDefinition(
            name: 'application_content_search',
            description: 'Retrieve bounded read-only evidence from one authorized application content source.',
            parameters: [
                [
                    'name' => 'source',
                    'type' => 'string',
                    'description' => 'Authorized application content source.',
                    'required' => true,
                    'enum' => [$selected_source],
                ],
                [
                    'name' => 'query',
                    'type' => 'string',
                    'description' => 'Natural-language evidence query.',
                    'required' => true,
                    'minLength' => 1,
                    'maxLength' => min(4000, max(1, (int) config('application-content.max_query_chars', 2000))),
                ],
                [
                    'name' => 'locale',
                    'type' => 'string',
                    'description' => 'Requested evidence locale.',
                    'required' => false,
                    'enum' => $selected_descriptor->supportedLocales,
                ],
                [
                    'name' => 'limit',
                    'type' => 'integer',
                    'description' => 'Maximum evidence item count.',
                    'required' => false,
                    'minimum' => 1,
                    'maximum' => $this->maximumResults(),
                ],
            ],
            riskLevel: 'low',
            handler: fn (
                mixed $source,
                mixed $query,
                mixed $locale = null,
                mixed $limit = null,
            ): array => $this->invoke($context, $selected_source, $source, $query, $locale, $limit),
        )];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoke(
        AssistantAccessContext $context,
        string $selectedSource,
        mixed $source,
        mixed $query,
        mixed $locale,
        mixed $limit,
    ): array {
        try {
            $this->citations->markAttempted();

            if (! is_string($source) || $source !== $selectedSource || ! is_string($query)) {
                return $this->unavailable();
            }

            $descriptors = $this->authorizedDescriptors($context);
            $decision = $this->router->route($query, $descriptors, explicitSource: $selectedSource);

            if ($decision->status !== ApplicationContentRoutingStatus::Selected
                || $decision->selectedSource === null) {
                return $this->unavailable();
            }

            $resolved_locale = $locale === null ? $context->locale : $locale;

            if (! is_string($resolved_locale)) {
                return $this->unavailable();
            }

            $resolved_limit = $limit === null ? $this->maximumResults() : $limit;

            if (! is_int($resolved_limit)) {
                return $this->unavailable();
            }

            $resolved_limit = max(1, min($resolved_limit, $this->maximumResults()));
            $result = $this->deadline->run(
                fn () => $this->retrieval->retrieve(
                    $this->request,
                    new ApplicationContentQuery(
                        $decision->selectedSource,
                        $query,
                        $resolved_locale,
                        $resolved_limit,
                    ),
                ),
                $this->timeoutSeconds(),
            );

            $this->guardrails->validateContext(new AssistantPromptContext(
                policyVersion: 'application-content-tool-v1',
                presentationPreferences: [],
                safeCitations: $this->citations->citationsFor($result),
                authorizedResults: $this->citations->resultsFor($result),
            ));
            $this->citations->record($result);

            return $this->projector->project($result);
        } catch (Throwable) {
            return $this->unavailable();
        }
    }

    /**
     * @return list<ApplicationContentSourceDescriptor>
     */
    private function authorizedDescriptors(AssistantAccessContext $context): array
    {
        if ($context->profile !== AssistantProfile::InAppAssistance || ! $this->identityMatches($context)) {
            return [];
        }

        return array_values(array_filter(
            $this->providers->descriptors(),
            fn (ApplicationContentSourceDescriptor $descriptor): bool => $this->moduleEnabled($descriptor)
                && in_array($context->locale, $descriptor->supportedLocales, true)
                && $this->authorization->checkPermission($this->request, $descriptor->entity, 'select'),
        ));
    }

    private function moduleEnabled(ApplicationContentSourceDescriptor $descriptor): bool
    {
        try {
            return Module::isEnabled(Str::studly($descriptor->module));
        } catch (Throwable) {
            return false;
        }
    }

    private function identityMatches(AssistantAccessContext $context): bool
    {
        $request_user = $this->request->user();
        $guard_user = Auth::user();

        return $request_user instanceof User
            && $guard_user instanceof User
            && $request_user === $guard_user
            && $context->userId === mb_trim((string) $request_user->getAuthIdentifier());
    }

    private function maximumResults(): int
    {
        return min(50, max(1, (int) config('application-content.max_results', 8)));
    }

    private function timeoutSeconds(): int
    {
        return min(30, max(1, (int) config('ai.features.application_content.timeout_seconds', 2)));
    }

    /**
     * @return array{available: false, items: array{}, truncated: false, reason_code: string}
     */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'items' => [],
            'truncated' => false,
            'reason_code' => 'application_content_unavailable',
        ];
    }
}

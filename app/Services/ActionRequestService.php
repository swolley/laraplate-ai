<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use JsonException;
use Modules\AI\Exceptions\InvalidActionRequestStateException;
use Modules\AI\Exceptions\UnknownToolException;
use Modules\AI\Jobs\ExecuteActionRequestJob;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Tools\RiskClassifier;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\Models\User;

use function ai_config_nullable_string;

final readonly class ActionRequestService
{
    public function __construct(
        private ToolRegistry $toolRegistry,
        private RiskClassifier $riskClassifier,
    ) {}

    /**
     * Create an action request from a tool call. Sets status based on risk level.
     *
     * @param  array<string, mixed>  $args
     */
    public function createRequest(
        User $user,
        string $toolName,
        array $args,
        ?Conversation $conversation = null,
    ): ActionRequest {
        $definition = $this->toolRegistry->getTool($toolName);

        throw_if(! $definition instanceof Tools\ToolDefinition, UnknownToolException::class, "Unknown tool: {$toolName}");

        $config_risk = ai_config_nullable_string("ai.features.tools.definitions.{$toolName}.risk_level");
        $risk_level = $this->riskClassifier->classifyRisk($toolName, $args, $config_risk);

        $status = match ($risk_level) {
            'low' => 'approved',
            'medium' => 'pending_user_confirmation',
            'high' => 'pending_admin_approval',
            default => 'pending_user_confirmation',
        };

        $request = ActionRequest::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $conversation?->id,
            'tool_name' => $toolName,
            'tool_args' => $args,
            'risk_level' => $risk_level,
            'status' => $status,
        ]);

        if ($risk_level === 'low') {
            $this->executeRequest($request);
        }

        return $request;
    }

    /**
     * Execute an action request (dispatch job or run synchronously).
     */
    public function executeRequest(ActionRequest $request): void
    {
        if ($request->status !== 'approved') {
            $request->update(['status' => 'executing']);
        }

        dispatch(new ExecuteActionRequestJob($request));
    }

    /**
     * Confirm a medium-risk request (user confirmed).
     */
    public function confirmRequest(ActionRequest $request): void
    {
        throw_if($request->status !== 'pending_user_confirmation', InvalidActionRequestStateException::class, 'Request is not pending user confirmation.');

        $request->update(['status' => 'approved']);
        $this->executeRequest($request);
    }

    /**
     * Approve a high-risk request (admin approved). Called when Modification is approved.
     */
    public function approveRequest(ActionRequest $request): void
    {
        throw_if($request->status !== 'pending_admin_approval', InvalidActionRequestStateException::class, 'Request is not pending admin approval.');

        $request->update(['status' => 'approved']);
        $this->executeRequest($request);
    }

    /**
     * Reject a request.
     */
    public function rejectRequest(ActionRequest $request): void
    {
        $request->update(['status' => 'rejected']);
    }

    /**
     * Run the tool handler for an action request (called by ExecuteActionRequestJob).
     *
     * @throws JsonException
     */
    public function runToolHandler(ActionRequest $request): mixed
    {
        $definition = $this->toolRegistry->getTool($request->tool_name);

        if (! $definition instanceof Tools\ToolDefinition) {
            throw new UnknownToolException("Unknown tool: {$request->tool_name}");
        }

        $handler = $definition->handler;
        $args = $request->tool_args;

        if (is_array($args)) {
            return $handler(...array_values($args));
        }

        return $handler($args);
    }
}

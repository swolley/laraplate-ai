<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Exception;
use JsonException;
use Modules\AI\Jobs\ExecuteActionRequestJob;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Tools\RiskClassifier;
use Modules\AI\Services\Tools\ToolRegistry;
use Modules\Core\Models\User;

final class ActionRequestService
{
    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly RiskClassifier $riskClassifier,
    ) {}

    /**
     * Create an action request from a tool call. Sets status based on risk level.
     */
    public function createRequest(
        User $user,
        string $toolName,
        array $args,
        ?Conversation $conversation = null,
    ): ActionRequest {
        $definition = $this->toolRegistry->getTool($toolName);

        if ($definition === null) {
            throw new Exception("Unknown tool: {$toolName}");
        }

        $config_risk = config("ai.features.tools.definitions.{$toolName}.risk_level");
        $risk_level = $this->riskClassifier->classifyRisk($toolName, $args, $config_risk);

        $status = match ($risk_level) {
            'low' => 'approved',
            'medium' => 'pending_user_confirmation',
            'high' => 'pending_admin_approval',
            default => 'pending_user_confirmation',
        };

        $request = ActionRequest::create([
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
        if (! in_array($request->status, ['approved'], true)) {
            $request->update(['status' => 'executing']);
        }

        ExecuteActionRequestJob::dispatch($request);
    }

    /**
     * Confirm a medium-risk request (user confirmed).
     */
    public function confirmRequest(ActionRequest $request): void
    {
        if ($request->status !== 'pending_user_confirmation') {
            throw new Exception('Request is not pending user confirmation.');
        }

        $request->update(['status' => 'approved']);
        $this->executeRequest($request);
    }

    /**
     * Approve a high-risk request (admin approved). Called when Modification is approved.
     */
    public function approveRequest(ActionRequest $request, User $approver): void
    {
        if ($request->status !== 'pending_admin_approval') {
            throw new Exception('Request is not pending admin approval.');
        }

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

        if ($definition === null) {
            throw new Exception("Unknown tool: {$request->tool_name}");
        }

        $handler = $definition->handler;
        $args = $request->tool_args;

        if (is_array($args)) {
            return $handler(...array_values($args));
        }

        return $handler($args);
    }
}

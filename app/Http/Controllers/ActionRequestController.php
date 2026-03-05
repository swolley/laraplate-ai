<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Http\Requests\ApproveActionRequest;
use Modules\AI\Http\Requests\RejectActionRequest;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Services\ActionRequestService;
use Modules\Core\Helpers\ResponseBuilder;

final class ActionRequestController extends Controller
{
    public function __construct(
        private readonly ActionRequestService $actionRequestService,
    ) {}

    /**
     * List action requests for the authenticated user.
     * Admins can see all requests with pending_admin_approval status.
     */
    public function list(): JsonResponse
    {
        $user = Auth::user();
        $is_admin = $user->hasRole(['admin', 'superadmin']);

        $query = ActionRequest::query()
            ->with(['conversation:id,title'])
            ->latest();

        if ($is_admin) {
            // Admins see their own requests + all pending admin approval
            $query->where(function ($q) use ($user): void {
                $q->where('user_id', $user->id)
                    ->orWhere('status', 'pending_admin_approval');
            });
        } else {
            // Regular users see only their own requests
            $query->forUser($user->id);
        }

        $requests = $query->paginate(request()->integer('per_page', 20));

        return new ResponseBuilder(request())
            ->setData($requests->items())
            ->setTotalRecords($requests->total())
            ->setCurrentRecords($requests->count())
            ->setCurrentPage($requests->currentPage())
            ->setTotalPages($requests->lastPage())
            ->setPagination($requests->perPage())
            ->json();
    }

    /**
     * Get details of a specific action request.
     */
    public function detail(ActionRequest $actionRequest): JsonResponse
    {
        $this->authorizeAccess($actionRequest);

        $actionRequest->load(['conversation:id,title', 'user:id,name,email']);

        return new ResponseBuilder(request())
            ->setData([
                'id' => $actionRequest->id,
                'user' => $actionRequest->user ? [
                    'id' => $actionRequest->user->id,
                    'name' => $actionRequest->user->name,
                    'email' => $actionRequest->user->email,
                ] : null,
                'conversation' => $actionRequest->conversation ? [
                    'id' => $actionRequest->conversation->id,
                    'title' => $actionRequest->conversation->title,
                ] : null,
                'tool_name' => $actionRequest->tool_name,
                'tool_args' => $actionRequest->tool_args,
                'risk_level' => $actionRequest->risk_level,
                'status' => $actionRequest->status,
                'result' => $actionRequest->result,
                'error' => $actionRequest->error,
                'executed_at' => $actionRequest->executed_at?->toIso8601String(),
                'created_at' => $actionRequest->created_at?->toIso8601String(),
                'updated_at' => $actionRequest->updated_at?->toIso8601String(),
            ])
            ->json();
    }

    /**
     * Confirm a medium-risk action request (user confirmation).
     * Only the owner of the request can confirm it.
     */
    public function confirm(ActionRequest $actionRequest): JsonResponse
    {
        $this->authorizeOwnership($actionRequest);

        if ($actionRequest->status !== 'pending_user_confirmation') {
            return new ResponseBuilder(request())
                ->setError('This action request is not pending user confirmation.')
                ->setStatus(Response::HTTP_BAD_REQUEST)
                ->json();
        }

        $this->actionRequestService->confirmRequest($actionRequest);

        return new ResponseBuilder(request())
            ->setData([
                'id' => $actionRequest->id,
                'status' => $actionRequest->fresh()->status,
                'message' => 'Action request confirmed and queued for execution.',
            ])
            ->json();
    }

    /**
     * Approve a high-risk action request (admin approval).
     * Only admins can approve these requests.
     */
    public function approve(ApproveActionRequest $request, ActionRequest $actionRequest): JsonResponse
    {
        $user = Auth::user();

        if (! $user->hasRole(['admin', 'superadmin'])) {
            return new ResponseBuilder($request)
                ->setError('Only administrators can approve high-risk action requests.')
                ->setStatus(Response::HTTP_FORBIDDEN)
                ->json();
        }

        if ($actionRequest->status !== 'pending_admin_approval') {
            return new ResponseBuilder($request)
                ->setError('This action request is not pending admin approval.')
                ->setStatus(Response::HTTP_BAD_REQUEST)
                ->json();
        }

        $this->actionRequestService->approveRequest($actionRequest);

        return new ResponseBuilder($request)
            ->setData([
                'id' => $actionRequest->id,
                'status' => $actionRequest->fresh()->status,
                'message' => 'Action request approved and queued for execution.',
            ])
            ->json();
    }

    /**
     * Reject an action request.
     * Users can reject their own pending requests.
     * Admins can reject any pending request.
     */
    public function reject(RejectActionRequest $request, ActionRequest $actionRequest): JsonResponse
    {
        $this->authorizeAccess($actionRequest);

        if (! in_array($actionRequest->status, ['pending_user_confirmation', 'pending_admin_approval'], true)) {
            return new ResponseBuilder($request)
                ->setError('This action request cannot be rejected in its current state.')
                ->setStatus(Response::HTTP_BAD_REQUEST)
                ->json();
        }

        $this->actionRequestService->rejectRequest($actionRequest);

        return new ResponseBuilder($request)
            ->setData([
                'id' => $actionRequest->id,
                'status' => 'rejected',
                'message' => 'Action request rejected.',
            ])
            ->json();
    }

    /**
     * Verify that the authenticated user can access this action request.
     * Users can access their own requests. Admins can access all requests.
     */
    private function authorizeAccess(ActionRequest $actionRequest): void
    {
        $user = Auth::user();
        $is_admin = $user->hasRole(['admin', 'superadmin']);

        abort_if(! $is_admin && $actionRequest->user_id !== $user->id, Response::HTTP_FORBIDDEN, 'You do not have access to this action request.');
    }

    /**
     * Verify that the authenticated user owns this action request.
     */
    private function authorizeOwnership(ActionRequest $actionRequest): void
    {
        abort_if($actionRequest->user_id !== Auth::id(), Response::HTTP_FORBIDDEN, 'You do not own this action request.');
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Http\Requests\GenerateSuggestionRequest;
use Modules\AI\Models\ContextualSuggestion;
use Modules\AI\Services\ContextualSuggestionService;
use Modules\Core\Helpers\ResponseBuilder;

final class SuggestionController extends Controller
{
    public function __construct(
        private readonly ContextualSuggestionService $suggestionService,
    ) {}

    /**
     * List pending suggestions for the current user.
     */
    public function listSuggestions(Request $request): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return (new ResponseBuilder($request))
                ->setError('Unauthorized')
                ->setStatus(Response::HTTP_UNAUTHORIZED)
                ->json();
        }

        $suggestions = $this->suggestionService->getPendingSuggestions($user);

        return (new ResponseBuilder($request))
            ->setData($suggestions->map(static fn (ContextualSuggestion $s): array => [
                'id' => $s->id,
                'suggestion' => $s->suggestion,
                'context' => $s->context,
                'created_at' => $s->created_at?->toIso8601String(),
            ]))
            ->setCurrentRecords($suggestions->count())
            ->json();
    }

    /**
     * Generate a new contextual suggestion.
     */
    public function generateSuggestion(GenerateSuggestionRequest $request): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return (new ResponseBuilder($request))
                ->setError('Unauthorized')
                ->setStatus(Response::HTTP_UNAUTHORIZED)
                ->json();
        }

        $validated = $request->validated();

        $suggestion = $this->suggestionService->generateSuggestion($user, $validated['context']);

        if ($suggestion === null) {
            return (new ResponseBuilder($request))
                ->setData(null)
                ->json();
        }

        return (new ResponseBuilder($request))
            ->setData([
                'id' => $suggestion->id,
                'suggestion' => $suggestion->suggestion,
                'context' => $suggestion->context,
                'created_at' => $suggestion->created_at?->toIso8601String(),
            ])
            ->setStatus(Response::HTTP_CREATED)
            ->json();
    }

    /**
     * Dismiss a suggestion.
     */
    public function dismissSuggestion(Request $request, ContextualSuggestion $suggestion): JsonResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return (new ResponseBuilder($request))
                ->setError('Unauthorized')
                ->setStatus(Response::HTTP_UNAUTHORIZED)
                ->json();
        }

        if ($suggestion->user_id !== $user->id) {
            return (new ResponseBuilder($request))
                ->setError('Forbidden')
                ->setStatus(Response::HTTP_FORBIDDEN)
                ->json();
        }

        $this->suggestionService->dismissSuggestion($suggestion);

        return (new ResponseBuilder($request))
            ->setData(['success' => true])
            ->json();
    }
}

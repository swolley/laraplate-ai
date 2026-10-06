<?php

declare(strict_types=1);

namespace Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Modules\AI\Http\Resources\AiCapabilitiesResource;
use Modules\AI\Services\Assistance\AssistantCapabilities;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Models\User;

final class CapabilitiesController extends Controller
{
    public function __construct(private readonly AssistantCapabilities $capabilities) {}

    /**
     * What the assistant offers, for a signed-in user. The guest account is refused like every
     * assistant call: it has no assistant to show.
     */
    public function show(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if ($user->isGuest()) {
            abort(Response::HTTP_FORBIDDEN);
        }

        return new ResponseBuilder($request)
            ->setData(new AiCapabilitiesResource($this->capabilities))
            ->json();
    }
}

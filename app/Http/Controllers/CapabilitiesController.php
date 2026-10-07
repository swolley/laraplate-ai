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
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\Core\Helpers\ResponseBuilder;
use Modules\Core\Models\User;

final class CapabilitiesController extends Controller
{
    public function __construct(private readonly AssistantCapabilities $capabilities, private readonly CrudToolProvider $crud_tools) {}

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
            ->setData(new AiCapabilitiesResource($this->capabilities, $this->actionsFor()))
            ->json();
    }

    /**
     * @return list<array{entity: string, operation: string, kind: string, requires_approval: bool}>
     */
    private function actionsFor(): array
    {
        if (! $this->capabilities->enabled() || ! $this->capabilities->configured()) {
            return [];
        }

        return array_map(
            static fn (array $offered): array => [
                'entity' => mb_strtolower($offered['module'] . '.' . $offered['entity']),
                'operation' => $offered['operation'],
                'kind' => $offered['write'] ? 'write' : 'read',
                'requires_approval' => $offered['requires_approval'],
            ],
            $this->crud_tools->offeredOperations(),
        );
    }
}

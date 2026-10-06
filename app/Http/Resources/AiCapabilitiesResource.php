<?php

declare(strict_types=1);

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Services\Assistance\AssistantCapabilities;
use Override;

/**
 * The body of `GET /app/ai/capabilities`: `enabled`, `configured` and the `features` a client may rely on.
 *
 * @property AssistantCapabilities $resource
 */
final class AiCapabilitiesResource extends JsonResource
{
    /**
     * @return array{enabled: bool, configured: bool, features: array{proposals: bool, streaming: bool}}
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}

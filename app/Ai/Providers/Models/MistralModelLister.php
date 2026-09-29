<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Illuminate\Support\Facades\Http;
use Modules\AI\Enums\ModelCapability;
use Override;
use UnexpectedValueException;

final readonly class MistralModelLister implements ModelLister
{
    public function __construct(private string $apiKey) {}

    #[Override]
    public function list(): array
    {
        $data = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->withToken($this->apiKey)
            ->get('https://api.mistral.ai/v1/models')
            ->throw()
            ->json('data');

        if (! is_array($data)) {
            throw new UnexpectedValueException('Mistral answered without a model list.');
        }

        $models = [];

        foreach ($data as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (! is_string($id) || $id === '' || ($item['archived'] ?? false) === true) {
                continue;
            }

            $models[] = new ListedModel($id, self::capabilities($item['capabilities'] ?? null));
        }

        return $models;
    }

    /**
     * @return list<ModelCapability>|null
     */
    private static function capabilities(mixed $declared): ?array
    {
        if (! is_array($declared)) {
            return null;
        }

        $map = [
            'completion_chat' => ModelCapability::Chat,
            'function_calling' => ModelCapability::Tools,
            'vision' => ModelCapability::Vision,
        ];
        $capabilities = [];

        foreach ($map as $field => $capability) {
            if (($declared[$field] ?? false) === true) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }
}

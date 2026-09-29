<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Modules\AI\Enums\ModelCapability;
use Override;
use UnexpectedValueException;

/**
 * Lists the locally installed models, then asks `/api/show` for each model's capabilities.
 * Only a failed `/api/tags` fails the provider: a failed `show`, or an older Ollama that
 * returns no capabilities, leaves that model's capabilities unknown.
 */
final readonly class OllamaModelLister implements ModelLister
{
    public function __construct(private string $url) {}

    #[Override]
    public function list(): array
    {
        $base = mb_rtrim($this->url, '/');
        $data = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->get($base . '/api/tags')
            ->throw()
            ->json('models');

        if (! is_array($data)) {
            throw new UnexpectedValueException('Ollama answered without a model list.');
        }

        $models = [];

        foreach ($data as $item) {
            $name = is_array($item) ? ($item['name'] ?? null) : null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $capabilities = $this->capabilities($base, $name);

            if ($capabilities === null && str_contains($name, 'embed')) {
                continue;
            }

            $models[] = new ListedModel($name, $capabilities);
        }

        return $models;
    }

    /**
     * @return list<ModelCapability>|null
     */
    private function capabilities(string $base, string $name): ?array
    {
        try {
            $declared = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->post($base . '/api/show', ['model' => $name])
                ->throw()
                ->json('capabilities');
        } catch (ConnectionException|RequestException) {
            return null;
        }

        if (! is_array($declared)) {
            return null;
        }

        $map = ['completion' => ModelCapability::Chat, 'tools' => ModelCapability::Tools, 'vision' => ModelCapability::Vision];
        $capabilities = [];

        foreach ($map as $declared_name => $capability) {
            if (in_array($declared_name, $declared, true)) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }
}

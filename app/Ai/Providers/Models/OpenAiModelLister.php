<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Illuminate\Support\Facades\Http;
use Override;

/**
 * OpenAI lists every model family without capabilities: the families no AI feature can use
 * are dropped by id, everything else is kept with unknown capabilities.
 */
final readonly class OpenAiModelLister implements ModelLister
{
    private const array INCOMPATIBLE = [
        'embedding', 'whisper', 'tts', 'audio', 'realtime', 'transcribe',
        'dall-e', 'image', 'moderation', 'babbage', 'davinci', 'instruct',
    ];

    public function __construct(private string $apiKey) {}

    #[Override]
    public function list(): array
    {
        $data = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->withToken($this->apiKey)
            ->get('https://api.openai.com/v1/models')
            ->throw()
            ->json('data');

        $models = [];

        foreach (is_array($data) ? $data : [] as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (is_string($id) && $id !== '' && ! self::isIncompatible($id)) {
                $models[] = new ListedModel($id, null);
            }
        }

        return $models;
    }

    private static function isIncompatible(string $id): bool
    {
        return array_any(self::INCOMPATIBLE, static fn (string $marker): bool => str_contains($id, $marker));
    }
}

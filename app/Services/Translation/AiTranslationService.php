<?php

declare(strict_types=1);

namespace Modules\AI\Services\Translation;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class AiTranslationService implements TranslationServiceInterface
{
    public function translate(string $text, string $from_locale, string $to_locale): string
    {
        if ($text === '' || $text === '0') {
            return $text;
        }

        $provider = config('ai.features.translation.default_provider');
        $prompt = $this->buildPrompt($text, $from_locale, $to_locale);

        try {
            return match ($provider) {
                'openai' => $this->translateWithOpenAI($prompt),
                'ollama' => $this->translateWithOllama($prompt),
                'mistral' => $this->translateWithMistral($prompt),
                default => throw new Exception("Unsupported AI provider: {$provider}"),
            };
        } catch (Exception $e) {
            Log::error('AI translation error', [
                'error' => $e->getMessage(),
                'provider' => $provider,
                'from' => $from_locale,
                'to' => $to_locale,
            ]);

            throw $e;
        }
    }

    public function translateBatch(array $texts, string $from_locale, string $to_locale): array
    {
        $translations = [];

        foreach ($texts as $text) {
            $translations[] = $this->translate($text, $from_locale, $to_locale);
        }

        return $translations;
    }

    private function buildPrompt(string $text, string $from_locale, string $to_locale): string
    {
        return "Translate the following text from {$from_locale} to {$to_locale}. Return only the translation, without any explanations or additional text:\n\n{$text}";
    }

    private function translateWithOpenAI(string $prompt): string
    {
        $api_key = config('ai.providers.openai.api_key');
        $model = config('ai.providers.openai.openai_model', 'gpt-3.5-turbo');

        throw_if(empty($api_key), Exception::class, 'OpenAI API key is not configured');

        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,
            ]);

        if ($response->successful()) {
            $data = $response->json();

            return mb_trim($data['choices'][0]['message']['content'] ?? '');
        }

        throw new Exception('OpenAI translation failed: ' . $response->status());
    }

    private function translateWithOllama(string $prompt): string
    {
        $api_url = config('ai.providers.ollama.api_url', 'http://localhost:11434');
        $model = config('ai.providers.ollama.model', 'llama3.2:3b');

        $response = Http::timeout(120)
            ->post(mb_rtrim((string) $api_url, '/') . '/api/generate', [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
            ]);

        if ($response->successful()) {
            $data = $response->json();

            return mb_trim($data['response'] ?? '');
        }

        throw new Exception('Ollama translation failed: ' . $response->status());
    }

    private function translateWithMistral(string $prompt): string
    {
        $api_key = config('ai.providers.mistral.api_key');
        $model = config('ai.providers.mistral.model', 'mistral-large-latest');

        throw_if(empty($api_key), Exception::class, 'Mistral API key is not configured');

        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ])
            ->post('https://api.mistral.ai/v1/chat/completions', [
                'model' => $model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,
            ]);

        if ($response->successful()) {
            $data = $response->json();

            return mb_trim($data['choices'][0]['message']['content'] ?? '');
        }

        throw new Exception('Mistral translation failed: ' . $response->status());
    }
}

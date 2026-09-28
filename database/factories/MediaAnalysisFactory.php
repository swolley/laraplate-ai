<?php

declare(strict_types=1);

namespace Modules\AI\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AI\Enums\MediaAnalysisStatus;
use Modules\AI\Models\MediaAnalysis;

/**
 * @extends Factory<MediaAnalysis>
 */
final class MediaAnalysisFactory extends Factory
{
    /**
     * @var class-string<MediaAnalysis>
     */
    protected $model = MediaAnalysis::class;

    public function definition(): array
    {
        return [
            'content_hash' => hash('sha256', (string) $this->faker->unique()->uuid()),
            'entities' => [$this->faker->word(), $this->faker->word()],
            'idea' => $this->faker->sentence(),
            'intent' => $this->faker->sentence(),
            'ocr_text' => null,
            'transcript' => null,
            'analysis' => [],
            'provenance' => ['idea' => 'llm', 'intent' => 'llm'],
            'analysis_status' => MediaAnalysisStatus::Completed->value,
            'analysis_model_version' => 'claude-sonnet-5',
            'analyzed_at' => now(),
        ];
    }

    public function pending(): self
    {
        return $this->state(fn (array $attributes): array => [
            'analysis_status' => MediaAnalysisStatus::Pending->value,
            'analyzed_at' => null,
            'idea' => null,
            'intent' => null,
            'entities' => null,
            'provenance' => [],
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'analysis_status' => MediaAnalysisStatus::Failed->value,
            'analyzed_at' => null,
        ]);
    }
}

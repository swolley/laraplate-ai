<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation;

use Closure;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use RuntimeException;
use Throwable;

final readonly class AssistantEvaluationService
{
    private Closure $clock;

    private AssistanceGuardrailPipeline $guardrails;

    public function __construct(?Closure $clock = null, ?AssistanceGuardrailPipeline $guardrails = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
        $this->guardrails = $guardrails ?? AssistanceGuardrailPipeline::defaults();
    }

    /**
     * @param  callable(AssistantEvaluationCase): Message  $runner
     * @return array<string, mixed>
     */
    public function evaluate(
        AssistantEvaluationDataset $dataset,
        string $mode,
        callable $runner,
    ): array {
        $records = [];

        foreach ($dataset->cases as $case) {
            $started_at = ($this->clock)();
            $content = '';
            $labels = [];
            $refused = false;
            $unavailable = false;

            try {
                $message = $runner($case);

                if (! $message instanceof Message) {
                    throw new RuntimeException('Invalid evaluation message.');
                }

                $content = (string) $message->content;
                $metadata = $message->metadata ?? [];
                $refused = ($metadata['refused'] ?? null) === true;

                $citations = $metadata['citations'] ?? [];

                if (is_array($citations)) {
                    foreach ($citations as $citation) {
                        if (is_array($citation) && is_string($citation['label'] ?? null)) {
                            $labels[] = $citation['label'];
                        }
                    }
                }
            } catch (Throwable) {
                $unavailable = true;
                $content = '';
                $labels = [];
                $refused = false;
            }

            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);
            $records[] = [
                'case' => $case,
                'content' => $content,
                'labels' => $labels,
                'refused' => $refused,
                'unavailable' => $unavailable,
                'latency_ms' => $elapsed,
            ];
        }

        return [
            'schema_version' => '1',
            'module' => $dataset->module,
            'mode' => mb_trim($mode),
            'dataset_version' => $dataset->version,
            'corpus_revision' => $dataset->corpusRevision,
            'data_classification' => $dataset->dataClassification,
            'case_count' => count($records),
            'metrics' => $this->metrics($records),
            'latency_ms' => $this->latency($records),
            'slices' => $this->slices($records),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, float>
     */
    private function metrics(array $records): array
    {
        $citation_relevant = 0;
        $citation_matched = 0;
        $clarification_total = 0;
        $clarification_ok = 0;
        $abstention_total = 0;
        $abstention_ok = 0;
        $valid_total = 0;
        $valid_ok = 0;
        $unavailable = 0;

        foreach ($records as $record) {
            /** @var AssistantEvaluationCase $case */
            $case = $record['case'];
            $labels = $record['labels'];
            $content = $record['content'];

            if ($case->expectedCitations !== []) {
                $citation_relevant++;
                $missing = array_diff($case->expectedCitations, $labels);
                $citation_matched += (int) ($missing === []);
            }

            if ($case->expectClarification) {
                $clarification_total++;
                $clarification_ok += (int) ($content === $this->guardrails->clarificationRequired($case->locale));
            }

            if ($case->expectRefusal) {
                $abstention_total++;
                $abstention_ok += (int) (
                    $record['refused'] === true
                    || $content === $this->guardrails->insufficientEvidence($case->locale)
                );
            }

            if (! $record['unavailable']) {
                $valid_total++;
                $valid_ok += (int) (mb_trim($content) !== '');
            }

            $unavailable += (int) $record['unavailable'];
        }

        $count = count($records);

        return [
            'citation_assembly' => $this->ratio($citation_matched, $citation_relevant),
            'clarification_trigger_accuracy' => $this->ratio($clarification_ok, $clarification_total),
            'abstention_accuracy' => $this->ratio($abstention_ok, $abstention_total),
            'output_valid' => $this->ratio($valid_ok, $valid_total),
            'unavailable_rate' => $this->ratio($unavailable, $count),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, float>
     */
    private function latency(array $records): array
    {
        $values = array_map(static fn (array $record): float => $record['latency_ms'], $records);
        sort($values, SORT_NUMERIC);

        return [
            'average' => $this->rounded(array_sum($values) / count($values)),
            'p50' => $this->rounded($this->percentile($values, 0.50)),
            'p95' => $this->rounded($this->percentile($values, 0.95)),
            'max' => $this->rounded(max($values)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, array<string, float>>>
     */
    private function slices(array $records): array
    {
        $locales = [];
        $categories = [];

        foreach ($records as $record) {
            /** @var AssistantEvaluationCase $case */
            $case = $record['case'];
            $locales[$case->locale][] = $record;

            foreach ($case->slices as $slice) {
                $categories[$slice][] = $record;
            }
        }

        ksort($locales, SORT_STRING);
        ksort($categories, SORT_STRING);

        return [
            'locale' => array_map(fn (array $slice): array => $this->metrics($slice), $locales),
            'category' => array_map(fn (array $slice): array => $this->metrics($slice), $categories),
        ];
    }

    private function ratio(float|int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : $this->rounded($numerator / $denominator);
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $percentile): float
    {
        $index = max(0, (int) ceil($percentile * count($values)) - 1);

        return $values[$index];
    }

    private function rounded(float $value): float
    {
        return round($value, 4);
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use Closure;
use NeuronAI\RAG\Document;
use RuntimeException;
use Throwable;

final readonly class DocumentationEvaluationService
{
    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * @param  callable(string, \Modules\AI\Services\Assistance\AssistantAccessContext, DocumentationEvaluationCase): list<Document>  $retrieval
     * @return array<string, mixed>
     */
    public function evaluate(
        DocumentationEvaluationDataset $dataset,
        string $driver,
        callable $retrieval,
    ): array {
        $records = [];

        foreach ($dataset->cases as $case) {
            $started_at = ($this->clock)();
            $labels = [];
            $unavailable = false;

            try {
                $documents = $retrieval($case->query, $case->accessContext(), $case);

                foreach ($documents as $document) {
                    if (! $document instanceof Document || ! is_string($document->sourceName)) {
                        throw new RuntimeException('Invalid evaluation document.');
                    }

                    $labels[] = $document->sourceName;
                }
            } catch (Throwable) {
                $unavailable = true;
                $labels = [];
            }

            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);
            $records[] = [
                'case' => $case,
                'labels' => $labels,
                'empty' => $labels === [],
                'unavailable' => $unavailable,
                'latency_ms' => $elapsed,
            ];
        }

        return [
            'schema_version' => '1',
            'module' => $dataset->module,
            'index_profile' => $dataset->indexProfile,
            'driver' => mb_trim($driver),
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
        $relevant = 0;
        $hits = 0;
        $reciprocal = 0.0;
        $returned = 0;
        $correct = 0;
        $authorized_empty = 0;
        $authorized_empty_ok = 0;
        $supported = 0;
        $supported_ok = 0;
        $refusal_ok = 0;
        $unavailable = 0;

        foreach ($records as $record) {
            /** @var DocumentationEvaluationCase $case */
            $case = $record['case'];
            $labels = $record['labels'];
            $empty = $record['empty'];

            if ($case->expectedSourceLabels !== []) {
                $relevant++;
                $first_rank = null;

                foreach ($labels as $index => $label) {
                    if (in_array($label, $case->expectedSourceLabels, true)) {
                        $first_rank ??= $index + 1;
                    }
                }

                if ($first_rank !== null) {
                    $hits++;
                    $reciprocal += 1 / $first_rank;
                }
            }

            foreach ($labels as $label) {
                $returned++;
                $correct += (int) in_array($label, $case->expectedCitationLabels, true);
            }

            if ($case->expectAuthorizedEmpty) {
                $authorized_empty++;
                $authorized_empty_ok += (int) $empty;
            }

            if ($case->expectSupportedAnswer) {
                $supported++;
                $supported_ok += (int) ! $empty;
            }

            $refusal_ok += (int) ($empty === $case->expectRefusal);
            $unavailable += (int) $record['unavailable'];
        }

        $count = count($records);

        return [
            'source_hit_at_k' => $this->ratio($hits, $relevant),
            'mean_reciprocal_rank' => $this->ratio($reciprocal, $relevant),
            'citation_precision' => $this->ratio($correct, $returned),
            'authorized_empty_accuracy' => $this->ratio($authorized_empty_ok, $authorized_empty),
            'supported_answer_rate' => $this->ratio($supported_ok, $supported),
            'refusal_accuracy' => $this->ratio($refusal_ok, $count),
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
            /** @var DocumentationEvaluationCase $case */
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

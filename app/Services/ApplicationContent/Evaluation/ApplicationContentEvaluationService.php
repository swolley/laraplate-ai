<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use Closure;
use InvalidArgumentException;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Throwable;

final readonly class ApplicationContentEvaluationService
{
    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * @param  callable(ApplicationContentQuery, ApplicationContentAuthorization, ApplicationContentEvaluationCase): ApplicationContentResult  $retrieval
     * @return array<string, mixed>
     */
    public function evaluate(
        ApplicationContentEvaluationDataset $dataset,
        string $source,
        string $driver,
        callable $retrieval,
    ): array {
        $source = ApplicationContentSourceDescriptor::normalizeSource($source);

        if ($dataset->source !== $source || mb_trim($driver) === '' || mb_strlen($driver) > 100) {
            throw new InvalidArgumentException('Evaluation driver is invalid.');
        }

        $this->assertDatasetSource($dataset, $source);
        $records = [];

        foreach ($dataset->cases as $case) {
            $started_at = ($this->clock)();
            $result = null;
            $unavailable = false;

            try {
                $candidate = $retrieval(
                    new ApplicationContentQuery($source, $case->query, $case->locale, $case->limit),
                    $case->authorization,
                    $case,
                );

                if (! $candidate instanceof ApplicationContentResult || $candidate->source !== $source) {
                    throw new InvalidArgumentException('Evaluation retrieval returned an invalid result.');
                }

                $result = $candidate;
            } catch (Throwable) {
                $unavailable = true;
            }

            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);
            $records[] = $this->record($case, $result, $unavailable, $elapsed);
        }

        return [
            'schema_version' => '1',
            'source' => $source,
            'driver' => mb_trim($driver),
            'dataset_version' => $dataset->version,
            'provider_version' => $dataset->providerVersion,
            'corpus_revision' => $dataset->corpusRevision,
            'data_classification' => $dataset->dataClassification,
            'case_count' => count($records),
            'metrics' => $this->metrics($records),
            'latency_ms' => $this->latency($records),
            'slices' => $this->slices($records),
        ];
    }

    private function assertDatasetSource(ApplicationContentEvaluationDataset $dataset, string $source): void
    {
        foreach ($dataset->cases as $case) {
            foreach ($case->expectedHitIds as $id) {
                if (! str_starts_with($id, $source . ':')) {
                    throw new InvalidArgumentException('Evaluation case source does not match the requested source.');
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function record(
        ApplicationContentEvaluationCase $case,
        ?ApplicationContentResult $result,
        bool $unavailable,
        float $latency,
    ): array {
        $hits = $result?->hits ?? [];

        return [
            'case' => $case,
            'hit_ids' => array_map(static fn ($hit): string => $hit->id, $hits),
            'references' => array_map(static fn ($hit): string => $hit->canonicalReference, $hits),
            'empty' => $hits === [],
            'unavailable' => $unavailable,
            'latency_ms' => $latency,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, float>
     */
    private function metrics(array $records): array
    {
        $relevant_cases = 0;
        $hits_at_k = 0;
        $reciprocal_rank = 0.0;
        $returned_citations = 0;
        $correct_citations = 0;
        $authorized_empty_cases = 0;
        $authorized_empty_correct = 0;
        $supported_cases = 0;
        $supported_answers = 0;
        $abstention_correct = 0;
        $unavailable = 0;

        foreach ($records as $record) {
            /** @var ApplicationContentEvaluationCase $case */
            $case = $record['case'];
            $hit_ids = $record['hit_ids'];
            $references = $record['references'];
            $empty = $record['empty'];

            if ($case->expectedHitIds !== []) {
                $relevant_cases++;
                $first_rank = null;

                foreach ($hit_ids as $index => $id) {
                    if (in_array($id, $case->expectedHitIds, true)) {
                        $first_rank ??= $index + 1;
                    }
                }

                if ($first_rank !== null) {
                    $hits_at_k++;
                    $reciprocal_rank += 1 / $first_rank;
                }
            }

            foreach ($references as $reference) {
                $returned_citations++;
                $correct_citations += (int) in_array($reference, $case->expectedCitationReferences, true);
            }

            if ($case->expectAuthorizedEmpty) {
                $authorized_empty_cases++;
                $authorized_empty_correct += (int) $empty;
            }

            if ($case->expectSupportedAnswer) {
                $supported_cases++;
                $supported_answers += (int) ! $empty;
            }

            $abstention_correct += (int) ($empty === $case->expectAbstention);
            $unavailable += (int) $record['unavailable'];
        }

        $count = count($records);

        return [
            'hit_at_5' => $this->ratio($hits_at_k, $relevant_cases),
            'mean_reciprocal_rank' => $this->ratio($reciprocal_rank, $relevant_cases),
            'citation_precision' => $this->ratio($correct_citations, $returned_citations),
            'authorized_empty_accuracy' => $this->ratio($authorized_empty_correct, $authorized_empty_cases),
            'supported_answer_rate' => $this->ratio($supported_answers, $supported_cases),
            'abstention_accuracy' => $this->ratio($abstention_correct, $count),
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
            /** @var ApplicationContentEvaluationCase $case */
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

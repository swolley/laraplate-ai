<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation;

use InvalidArgumentException;
use Modules\AI\Services\Evaluation\EvaluationDatasetReader;

final readonly class AssistantEvaluationDataset
{
    /**
     * @param  list<AssistantEvaluationCase>  $cases
     */
    public function __construct(
        public string $version,
        public string $corpusRevision,
        public string $module,
        public array $cases,
        public string $dataClassification = 'synthetic',
    ) {
        $ids = array_map(static fn (AssistantEvaluationCase $case): string => $case->id, $this->cases);

        if (! EvaluationDatasetReader::isValidRevision($this->version)
            || ! EvaluationDatasetReader::isValidRevision($this->corpusRevision)
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->module) !== 1
            || $this->cases === []
            || count($this->cases) > 1000
            || ! array_is_list($this->cases)
            || count(array_unique($ids)) !== count($ids)
            || $this->dataClassification !== 'synthetic') {
            throw new InvalidArgumentException('Assistant evaluation dataset is invalid.');
        }
    }

    public static function fromFile(string $path): self
    {
        $data = self::reader()->readFile($path);

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        self::reader()->assertExactKeys($data, [
            'cases',
            'corpus_revision',
            'data_classification',
            'module',
            'version',
        ]);

        $raw_cases = $data['cases'] ?? null;

        if (! is_array($raw_cases) || ! array_is_list($raw_cases)) {
            throw new InvalidArgumentException('Assistant evaluation dataset is invalid.');
        }

        $cases = array_map(static function (mixed $case): AssistantEvaluationCase {
            if (! is_array($case)) {
                throw new InvalidArgumentException('Assistant evaluation case is invalid.');
            }

            return self::caseFromArray($case);
        }, $raw_cases);

        return new self(
            version: self::reader()->string($data, 'version'),
            corpusRevision: self::reader()->string($data, 'corpus_revision'),
            module: self::reader()->string($data, 'module'),
            cases: $cases,
            dataClassification: self::reader()->string($data, 'data_classification'),
        );
    }

    private static function reader(): EvaluationDatasetReader
    {
        return new EvaluationDatasetReader('Assistant');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function caseFromArray(array $data): AssistantEvaluationCase
    {
        self::reader()->assertKeys($data, [
            'expect_clarification',
            'expect_refusal',
            'expected_citations',
            'expected_surface',
            'id',
            'locale',
            'module_key',
            'query',
            'slices',
        ], ['expected_proposals', 'page']);

        $module_key = $data['module_key'] ?? null;

        if ($module_key !== null && ! is_string($module_key)) {
            throw new InvalidArgumentException('Assistant evaluation module key is invalid.');
        }

        return new AssistantEvaluationCase(
            id: self::reader()->string($data, 'id'),
            query: self::reader()->string($data, 'query'),
            locale: self::reader()->string($data, 'locale'),
            moduleKey: $module_key,
            expectedSurface: self::reader()->string($data, 'expected_surface'),
            expectedCitations: self::reader()->stringList($data, 'expected_citations'),
            expectClarification: self::reader()->boolean($data, 'expect_clarification'),
            expectRefusal: self::reader()->boolean($data, 'expect_refusal'),
            slices: self::reader()->stringList($data, 'slices'),
            page: self::reader()->optionalObject($data, 'page'),
            expectedProposals: self::reader()->optionalInteger($data, 'expected_proposals'),
        );
    }
}

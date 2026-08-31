<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Evaluation;

use InvalidArgumentException;
use JsonException;

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

        if (! $this->validRevision($this->version)
            || ! $this->validRevision($this->corpusRevision)
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
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Assistant evaluation dataset is unavailable.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || mb_strlen($contents) > 2_000_000) {
            throw new InvalidArgumentException('Assistant evaluation dataset is invalid.');
        }

        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Assistant evaluation dataset is invalid.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Assistant evaluation dataset is invalid.');
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data, [
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
            version: self::string($data, 'version'),
            corpusRevision: self::string($data, 'corpus_revision'),
            module: self::string($data, 'module'),
            cases: $cases,
            dataClassification: self::string($data, 'data_classification'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function caseFromArray(array $data): AssistantEvaluationCase
    {
        self::assertExactKeys($data, [
            'expect_clarification',
            'expect_refusal',
            'expected_citations',
            'expected_surface',
            'id',
            'locale',
            'module_key',
            'query',
            'slices',
        ]);

        $module_key = $data['module_key'] ?? null;

        if ($module_key !== null && ! is_string($module_key)) {
            throw new InvalidArgumentException('Assistant evaluation module key is invalid.');
        }

        return new AssistantEvaluationCase(
            id: self::string($data, 'id'),
            query: self::string($data, 'query'),
            locale: self::string($data, 'locale'),
            moduleKey: $module_key,
            expectedSurface: self::string($data, 'expected_surface'),
            expectedCitations: self::stringList($data, 'expected_citations'),
            expectClarification: self::boolean($data, 'expect_clarification'),
            expectRefusal: self::boolean($data, 'expect_refusal'),
            slices: self::stringList($data, 'slices'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function assertExactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        if ($actual !== $keys) {
            throw new InvalidArgumentException('Assistant evaluation schema is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException('Assistant evaluation value is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException('Assistant evaluation value is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Assistant evaluation value is invalid.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Assistant evaluation value is invalid.');
            }
        }

        return $value;
    }

    private function validRevision(string $value): bool
    {
        return mb_trim($value) !== ''
            && mb_strlen($value) <= 200
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1;
    }
}

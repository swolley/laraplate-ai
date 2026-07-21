<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use InvalidArgumentException;
use JsonException;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;

final readonly class ApplicationContentEvaluationDataset
{
    /**
     * @param  list<ApplicationContentEvaluationCase>  $cases
     */
    public function __construct(
        public string $version,
        public string $providerVersion,
        public string $corpusRevision,
        public array $cases,
        public string $source = 'cms.contents',
        public string $dataClassification = 'synthetic',
    ) {
        $ids = array_map(
            static fn (ApplicationContentEvaluationCase $case): string => $case->id,
            $this->cases,
        );

        if (! $this->validRevision($this->version)
            || ! $this->validRevision($this->providerVersion)
            || ! $this->validRevision($this->corpusRevision)
            || $this->cases === []
            || count($this->cases) > 1000
            || ! array_is_list($this->cases)
            || count(array_unique($ids)) !== count($ids)
            || $this->source !== ApplicationContentSourceDescriptor::normalizeSource($this->source)
            || $this->dataClassification !== 'synthetic') {
            throw new InvalidArgumentException('Application content evaluation dataset is invalid.');
        }
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Application content evaluation dataset is unavailable.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || mb_strlen($contents) > 2_000_000) {
            throw new InvalidArgumentException('Application content evaluation dataset is invalid.');
        }

        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Application content evaluation dataset is invalid.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Application content evaluation dataset is invalid.');
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
            'provider_version',
            'source',
            'version',
        ]);

        $raw_cases = $data['cases'] ?? null;

        if (! is_array($raw_cases) || ! array_is_list($raw_cases)) {
            throw new InvalidArgumentException('Application content evaluation dataset is invalid.');
        }

        $cases = array_map(static function (mixed $case): ApplicationContentEvaluationCase {
            if (! is_array($case)) {
                throw new InvalidArgumentException('Application content evaluation case is invalid.');
            }

            return self::caseFromArray($case);
        }, $raw_cases);

        return new self(
            version: self::string($data, 'version'),
            providerVersion: self::string($data, 'provider_version'),
            corpusRevision: self::string($data, 'corpus_revision'),
            cases: $cases,
            source: self::string($data, 'source'),
            dataClassification: self::string($data, 'data_classification'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function caseFromArray(array $data): ApplicationContentEvaluationCase
    {
        self::assertExactKeys($data, [
            'authorization',
            'expect_abstention',
            'expect_authorized_empty',
            'expect_supported_answer',
            'expected_citation_references',
            'expected_hit_ids',
            'id',
            'limit',
            'locale',
            'query',
            'slices',
        ]);

        $authorization = $data['authorization'] ?? null;

        if (! is_array($authorization)) {
            throw new InvalidArgumentException('Application content evaluation authorization is invalid.');
        }

        self::assertExactKeys($authorization, ['filters', 'permission']);
        $filters = $authorization['filters'] ?? null;

        return new ApplicationContentEvaluationCase(
            id: self::string($data, 'id'),
            query: self::string($data, 'query'),
            locale: self::string($data, 'locale'),
            limit: self::integer($data, 'limit'),
            expectedHitIds: self::stringList($data, 'expected_hit_ids'),
            expectedCitationReferences: self::stringList($data, 'expected_citation_references'),
            expectAuthorizedEmpty: self::boolean($data, 'expect_authorized_empty'),
            expectSupportedAnswer: self::boolean($data, 'expect_supported_answer'),
            expectAbstention: self::boolean($data, 'expect_abstention'),
            slices: self::stringList($data, 'slices'),
            authorization: new ApplicationContentAuthorization(
                self::string($authorization, 'permission'),
                $filters === null ? null : self::filtersGroup($filters),
            ),
        );
    }

    private static function filtersGroup(mixed $data, int $depth = 0): FiltersGroup
    {
        if (! is_array($data) || $depth > 5) {
            throw new InvalidArgumentException('Application content evaluation filters are invalid.');
        }

        self::assertExactKeys($data, ['filters', 'operator']);
        $operator = WhereClause::tryFrom(self::string($data, 'operator'));
        $raw_filters = $data['filters'] ?? null;

        if ($operator === null || ! is_array($raw_filters) || ! array_is_list($raw_filters) || count($raw_filters) > 20) {
            throw new InvalidArgumentException('Application content evaluation filters are invalid.');
        }

        $filters = array_map(static function (mixed $filter) use ($depth): Filter|FiltersGroup {
            if (! is_array($filter)) {
                throw new InvalidArgumentException('Application content evaluation filter is invalid.');
            }

            if (array_key_exists('filters', $filter)) {
                return self::filtersGroup($filter, $depth + 1);
            }

            self::assertExactKeys($filter, ['operator', 'property', 'value']);
            $property = self::string($filter, 'property');
            $operator = FilterOperator::tryFrom(self::string($filter, 'operator'));
            $value = $filter['value'] ?? null;

            if ($operator === null
                || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*$/', $property) !== 1
                || ! self::validFilterValue($value)) {
                throw new InvalidArgumentException('Application content evaluation filter is invalid.');
            }

            return new Filter($property, $value, $operator);
        }, $raw_filters);

        return new FiltersGroup($filters, $operator);
    }

    private static function validFilterValue(mixed $value): bool
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return true;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= 500;
        }

        if (! is_array($value) || ! array_is_list($value) || count($value) > 100) {
            return false;
        }

        foreach ($value as $item) {
            if (is_array($item) || is_object($item) || is_resource($item) || ! self::validFilterValue($item)) {
                return false;
            }
        }

        return true;
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
            throw new InvalidArgumentException('Application content evaluation schema is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException('Application content evaluation value is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException('Application content evaluation value is invalid.');
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
            throw new InvalidArgumentException('Application content evaluation value is invalid.');
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
            throw new InvalidArgumentException('Application content evaluation value is invalid.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Application content evaluation value is invalid.');
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

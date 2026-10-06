<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use InvalidArgumentException;
use Modules\AI\Services\Evaluation\EvaluationDatasetReader;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use Modules\Core\Casts\Filter;
use Modules\Core\Casts\FilterOperator;
use Modules\Core\Casts\FiltersGroup;
use Modules\Core\Casts\WhereClause;

final readonly class ApplicationContentEvaluationDataset
{
    /**
     * `synthetic` is invented data, committed as a fixture. `private` is built from a real corpus,
     * so it holds queries about content nobody may redistribute: {@see self::fromFile()} refuses it
     * inside the project directory, where it could end up in a commit.
     *
     * @var list<string>
     */
    public const array CLASSIFICATIONS = ['synthetic', 'private'];

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

        if (! EvaluationDatasetReader::isValidRevision($this->version)
            || ! EvaluationDatasetReader::isValidRevision($this->providerVersion)
            || ! EvaluationDatasetReader::isValidRevision($this->corpusRevision)
            || $this->cases === []
            || count($this->cases) > 1000
            || ! array_is_list($this->cases)
            || count(array_unique($ids)) !== count($ids)
            || $this->source !== ApplicationContentSourceDescriptor::normalizeSource($this->source)
            || ! in_array($this->dataClassification, self::CLASSIFICATIONS, true)) {
            throw new InvalidArgumentException('Application content evaluation dataset is invalid.');
        }
    }

    public static function fromFile(string $path): self
    {
        $data = self::reader()->readFile($path);

        if (($data['data_classification'] ?? null) === 'private') {
            self::assertOutsideProject($path);
        }

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
            version: self::reader()->string($data, 'version'),
            providerVersion: self::reader()->string($data, 'provider_version'),
            corpusRevision: self::reader()->string($data, 'corpus_revision'),
            cases: $cases,
            source: self::reader()->string($data, 'source'),
            dataClassification: self::reader()->string($data, 'data_classification'),
        );
    }

    private static function reader(): EvaluationDatasetReader
    {
        return new EvaluationDatasetReader('Application content');
    }

    /**
     * The real path is checked, so a link from outside the project to a file inside it does not
     * get around the rule.
     */
    private static function assertOutsideProject(string $path): void
    {
        $real = realpath($path);
        $project = realpath(base_path());

        if ($real === false || ($project !== false && str_starts_with($real, $project . DIRECTORY_SEPARATOR))) {
            throw new InvalidArgumentException('A private evaluation dataset must be kept outside the project directory, so it cannot be committed.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function caseFromArray(array $data): ApplicationContentEvaluationCase
    {
        self::reader()->assertExactKeys($data, [
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

        self::reader()->assertExactKeys($authorization, ['filters', 'permission']);
        $filters = $authorization['filters'] ?? null;

        return new ApplicationContentEvaluationCase(
            id: self::reader()->string($data, 'id'),
            query: self::reader()->string($data, 'query'),
            locale: self::reader()->string($data, 'locale'),
            limit: self::reader()->integer($data, 'limit'),
            expectedHitIds: self::reader()->stringList($data, 'expected_hit_ids'),
            expectedCitationReferences: self::reader()->stringList($data, 'expected_citation_references'),
            expectAuthorizedEmpty: self::reader()->boolean($data, 'expect_authorized_empty'),
            expectSupportedAnswer: self::reader()->boolean($data, 'expect_supported_answer'),
            expectAbstention: self::reader()->boolean($data, 'expect_abstention'),
            slices: self::reader()->stringList($data, 'slices'),
            authorization: new ApplicationContentAuthorization(
                self::reader()->string($authorization, 'permission'),
                $filters === null ? null : self::filtersGroup($filters),
            ),
        );
    }

    private static function filtersGroup(mixed $data, int $depth = 0): FiltersGroup
    {
        if (! is_array($data) || $depth > 5) {
            throw new InvalidArgumentException('Application content evaluation filters are invalid.');
        }

        self::reader()->assertExactKeys($data, ['filters', 'operator']);
        $operator = WhereClause::tryFrom(self::reader()->string($data, 'operator'));
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

            self::reader()->assertExactKeys($filter, ['operator', 'property', 'value']);
            $property = self::reader()->string($filter, 'property');
            $operator = FilterOperator::tryFrom(self::reader()->string($filter, 'operator'));
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
}

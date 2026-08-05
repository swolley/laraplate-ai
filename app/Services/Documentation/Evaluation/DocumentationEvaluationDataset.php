<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use InvalidArgumentException;
use JsonException;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Enums\AssistantTenantScope;

final readonly class DocumentationEvaluationDataset
{
    /**
     * @param  list<DocumentationEvaluationCase>  $cases
     */
    public function __construct(
        public string $version,
        public string $corpusRevision,
        public string $module,
        public string $indexProfile,
        public array $cases,
        public string $dataClassification = 'synthetic',
    ) {
        $ids = array_map(static fn (DocumentationEvaluationCase $case): string => $case->id, $this->cases);

        if (! $this->validRevision($this->version)
            || ! $this->validRevision($this->corpusRevision)
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->module) !== 1
            || DocumentationIndexProfile::tryFrom($this->indexProfile) === null
            || $this->cases === []
            || count($this->cases) > 1000
            || ! array_is_list($this->cases)
            || count(array_unique($ids)) !== count($ids)
            || $this->dataClassification !== 'synthetic') {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Documentation evaluation dataset is unavailable.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || mb_strlen($contents) > 2_000_000) {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }

        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
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
            'index_profile',
            'module',
            'version',
        ]);

        $raw_cases = $data['cases'] ?? null;

        if (! is_array($raw_cases) || ! array_is_list($raw_cases)) {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }

        $cases = array_map(static function (mixed $case): DocumentationEvaluationCase {
            if (! is_array($case)) {
                throw new InvalidArgumentException('Documentation evaluation case is invalid.');
            }

            return self::caseFromArray($case);
        }, $raw_cases);

        return new self(
            version: self::string($data, 'version'),
            corpusRevision: self::string($data, 'corpus_revision'),
            module: self::string($data, 'module'),
            indexProfile: self::string($data, 'index_profile'),
            cases: $cases,
            dataClassification: self::string($data, 'data_classification'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function caseFromArray(array $data): DocumentationEvaluationCase
    {
        self::assertExactKeys($data, [
            'effective_permissions',
            'expect_authorized_empty',
            'expect_refusal',
            'expect_supported_answer',
            'expected_citation_labels',
            'expected_source_labels',
            'id',
            'locale',
            'query',
            'slices',
            'tenant_id',
            'tenant_scope',
            'top_k',
        ]);

        $scope = AssistantTenantScope::tryFrom(self::string($data, 'tenant_scope'));

        if ($scope === null) {
            throw new InvalidArgumentException('Documentation evaluation tenant scope is invalid.');
        }

        $tenant_id = $data['tenant_id'] ?? null;

        if ($tenant_id !== null && ! is_string($tenant_id)) {
            throw new InvalidArgumentException('Documentation evaluation tenant id is invalid.');
        }

        return new DocumentationEvaluationCase(
            id: self::string($data, 'id'),
            query: self::string($data, 'query'),
            locale: self::string($data, 'locale'),
            topK: self::integer($data, 'top_k'),
            expectedSourceLabels: self::stringList($data, 'expected_source_labels'),
            expectedCitationLabels: self::stringList($data, 'expected_citation_labels'),
            expectAuthorizedEmpty: self::boolean($data, 'expect_authorized_empty'),
            expectSupportedAnswer: self::boolean($data, 'expect_supported_answer'),
            expectRefusal: self::boolean($data, 'expect_refusal'),
            slices: self::stringList($data, 'slices'),
            tenantScope: $scope,
            tenantId: $tenant_id,
            effectivePermissions: self::stringList($data, 'effective_permissions'),
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
            throw new InvalidArgumentException('Documentation evaluation schema is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException('Documentation evaluation value is invalid.');
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
            throw new InvalidArgumentException('Documentation evaluation value is invalid.');
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
            throw new InvalidArgumentException('Documentation evaluation value is invalid.');
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
            throw new InvalidArgumentException('Documentation evaluation value is invalid.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Documentation evaluation value is invalid.');
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

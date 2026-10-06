<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use InvalidArgumentException;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Evaluation\EvaluationDatasetReader;

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

        if (! EvaluationDatasetReader::isValidRevision($this->version)
            || ! EvaluationDatasetReader::isValidRevision($this->corpusRevision)
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
            version: self::reader()->string($data, 'version'),
            corpusRevision: self::reader()->string($data, 'corpus_revision'),
            module: self::reader()->string($data, 'module'),
            indexProfile: self::reader()->string($data, 'index_profile'),
            cases: $cases,
            dataClassification: self::reader()->string($data, 'data_classification'),
        );
    }

    private static function reader(): EvaluationDatasetReader
    {
        return new EvaluationDatasetReader('Documentation');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function caseFromArray(array $data): DocumentationEvaluationCase
    {
        self::reader()->assertExactKeys($data, [
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

        $scope = AssistantTenantScope::tryFrom(self::reader()->string($data, 'tenant_scope'));

        if ($scope === null) {
            throw new InvalidArgumentException('Documentation evaluation tenant scope is invalid.');
        }

        $tenant_id = $data['tenant_id'] ?? null;

        if ($tenant_id !== null && ! is_string($tenant_id)) {
            throw new InvalidArgumentException('Documentation evaluation tenant id is invalid.');
        }

        return new DocumentationEvaluationCase(
            id: self::reader()->string($data, 'id'),
            query: self::reader()->string($data, 'query'),
            locale: self::reader()->string($data, 'locale'),
            topK: self::reader()->integer($data, 'top_k'),
            expectedSourceLabels: self::reader()->stringList($data, 'expected_source_labels'),
            expectedCitationLabels: self::reader()->stringList($data, 'expected_citation_labels'),
            expectAuthorizedEmpty: self::reader()->boolean($data, 'expect_authorized_empty'),
            expectSupportedAnswer: self::reader()->boolean($data, 'expect_supported_answer'),
            expectRefusal: self::reader()->boolean($data, 'expect_refusal'),
            slices: self::reader()->stringList($data, 'slices'),
            tenantScope: $scope,
            tenantId: $tenant_id,
            effectivePermissions: self::reader()->stringList($data, 'effective_permissions'),
        );
    }
}

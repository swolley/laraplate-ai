<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\ApplicationContent;

use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderInterface;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentHit;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;

final class EvaluationCommandContentProvider implements ApplicationContentRetrievalProviderInterface
{
    public function descriptor(): ApplicationContentSourceDescriptor
    {
        return new ApplicationContentSourceDescriptor(
            'cms.evaluation_records',
            'cms',
            'contents',
            ['en'],
            ['lexical'],
            ['evaluation'],
        );
    }

    public function retrieve(
        ApplicationContentQuery $query,
        ApplicationContentAuthorization $authorization,
    ): ApplicationContentResult {
        return new ApplicationContentResult('cms.evaluation_records', [
            new ApplicationContentHit(
                id: 'cms.evaluation_records:1',
                source: 'cms.evaluation_records',
                module: 'cms',
                entity: 'contents',
                recordKey: 1,
                excerpt: 'Visible generated evaluation evidence.',
                label: 'Evaluation evidence',
                canonicalReference: '/app/cms/contents/1',
                locale: 'en',
                strategy: 'lexical',
                score: 0.8,
                revision: '1',
                truncated: false,
            ),
        ], 'lexical', false);
    }
}

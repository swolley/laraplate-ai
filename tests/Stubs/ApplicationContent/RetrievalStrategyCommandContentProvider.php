<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\ApplicationContent;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderInterface;
use Modules\Core\ApplicationContent\Contracts\ProvidesPermissionModel;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;

/**
 * Fake provider for {@see \Modules\AI\Console\EvaluateApplicationContentRetrievalStrategiesCommand}
 * feature tests. Declares a permission model (per {@see ProvidesPermissionModel}) so the
 * command can resolve the {@see Model} instance it hands to the injected
 * {@see \Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface}.
 * `retrieve()` is never called: the per-strategy evaluation service only calls the injected
 * retriever seam, not the provider.
 */
final class RetrievalStrategyCommandContentProvider implements ApplicationContentRetrievalProviderInterface, ProvidesPermissionModel
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(private readonly string $model) {}

    public function descriptor(): ApplicationContentSourceDescriptor
    {
        return new ApplicationContentSourceDescriptor(
            'cms.strategy_records',
            'cms',
            'strategy_records',
            ['en'],
            ['lexical'],
            ['evaluation'],
        );
    }

    public function permissionModel(): string
    {
        return $this->model;
    }

    public function retrieve(
        ApplicationContentQuery $query,
        ApplicationContentAuthorization $authorization,
    ): ApplicationContentResult {
        return new ApplicationContentResult('cms.strategy_records', [], 'lexical', false);
    }
}

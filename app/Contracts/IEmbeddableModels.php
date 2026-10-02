<?php

declare(strict_types=1);

namespace Modules\AI\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The searchable models whose records get an embedding.
 */
interface IEmbeddableModels
{
    /**
     * @return list<class-string<Model>>
     */
    public function all(): array;
}

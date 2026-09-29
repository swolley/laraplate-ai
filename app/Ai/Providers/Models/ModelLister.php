<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;

/**
 * Reads the models a provider offers. Short timeouts: the grid runs a refresh inside the
 * request, which must not hang on an unreachable host.
 */
interface ModelLister
{
    public const int CONNECT_TIMEOUT = 3;

    public const int REQUEST_TIMEOUT = 10;

    /**
     * @throws ConnectionException
     * @throws RequestException
     *
     * @return list<ListedModel>
     */
    public function list(): array;
}

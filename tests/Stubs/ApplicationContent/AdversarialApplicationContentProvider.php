<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\ApplicationContent;

use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderInterface;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use RuntimeException;

final class AdversarialApplicationContentProvider implements ApplicationContentRetrievalProviderInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly ApplicationContentSourceDescriptor $descriptor,
        private readonly ApplicationContentResult $result,
        private readonly int $delayMicroseconds = 0,
        private readonly bool $fail = false,
    ) {}

    public function descriptor(): ApplicationContentSourceDescriptor
    {
        return $this->descriptor;
    }

    public function retrieve(
        ApplicationContentQuery $query,
        ApplicationContentAuthorization $authorization,
    ): ApplicationContentResult {
        $this->calls++;

        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        if ($this->fail) {
            throw new RuntimeException('internal index and authorization diagnostics');
        }

        return $this->result;
    }
}

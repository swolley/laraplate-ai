<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\ApplicationContent;

use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderInterface;
use Modules\Core\ApplicationContent\Data\ApplicationContentAuthorization;
use Modules\Core\ApplicationContent\Data\ApplicationContentQuery;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;
use Modules\Core\ApplicationContent\Data\ApplicationContentSourceDescriptor;
use RuntimeException;

final class ApplicationContentToolFakeProvider implements ApplicationContentRetrievalProviderInterface
{
    public int $calls = 0;

    public ?ApplicationContentQuery $capturedQuery = null;

    public function __construct(
        private readonly ApplicationContentSourceDescriptor $source,
        private readonly ?ApplicationContentResult $result = null,
        private readonly bool $fail = false,
        private readonly int $delayMicroseconds = 0,
    ) {}

    public function descriptor(): ApplicationContentSourceDescriptor
    {
        return $this->source;
    }

    public function retrieve(
        ApplicationContentQuery $query,
        ApplicationContentAuthorization $authorization,
    ): ApplicationContentResult {
        $this->calls++;
        $this->capturedQuery = $query;

        if ($this->delayMicroseconds > 0) {
            usleep($this->delayMicroseconds);
        }

        if ($this->fail) {
            throw new RuntimeException('Provider details must remain private.');
        }

        return $this->result ?? new ApplicationContentResult($query->source, [], 'lexical', false);
    }
}

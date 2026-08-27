<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent;

use Modules\AI\Concurrency\ApplicationContentSignalDeadline;
use Modules\AI\Services\ApplicationContent\Exceptions\ApplicationContentDeadlineExceededException;

final readonly class ApplicationContentDeadlineExecutor
{
    /**
     * @param  bool|null  $signalsAllowed  Null auto-detects; false forces the soft deadline.
     */
    public function __construct(
        private ?bool $signalsAllowed = null,
    ) {}

    public function run(callable $operation, int $timeoutSeconds): mixed
    {
        if ($this->supportsSignals()) {
            return (new ApplicationContentSignalDeadline)->run($operation, $timeoutSeconds);
        }

        return $this->runWithSoftDeadline($operation, $timeoutSeconds);
    }

    /**
     * Long-lived workers must not receive process-wide signal handlers: the handler
     * would outlive the request that installed it.
     */
    private function longLivedWorker(): bool
    {
        return class_exists(\Laravel\Octane\Octane::class)
            || extension_loaded('swoole')
            || extension_loaded('openswoole');
    }

    private function supportsSignals(): bool
    {
        if ($this->signalsAllowed === false) {
            return false;
        }

        if ($this->signalsAllowed === null && $this->longLivedWorker()) {
            return false;
        }

        return ApplicationContentSignalDeadline::supported();
    }

    /**
     * Enforces the deadline after the fact: the operation is not interrupted, but a
     * breach is still reported to the caller.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     */
    private function runWithSoftDeadline(callable $operation, int $timeoutSeconds): mixed
    {
        $timeout_seconds = min(30, max(1, $timeoutSeconds));
        $started_at = hrtime(true);

        $result = $operation();

        $elapsed_seconds = (hrtime(true) - $started_at) / 1_000_000_000;

        if ($elapsed_seconds > $timeout_seconds) {
            throw new ApplicationContentDeadlineExceededException('Application content retrieval exceeded its deadline.');
        }

        return $result;
    }
}

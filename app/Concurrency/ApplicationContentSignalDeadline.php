<?php

declare(strict_types=1);

namespace Modules\AI\Concurrency;

use LogicException;
use Modules\AI\Services\ApplicationContent\Exceptions\ApplicationContentDeadlineExceededException;

/**
 * Interrupts an operation with SIGALRM once it overruns its deadline.
 *
 * Lives under Concurrency/ because it installs a process-wide signal handler: that
 * directory is this codebase's home for process-level primitives, and the
 * LongLivedWorkerSafetyTest guardrail excludes it from the request-reachable scan.
 * Callers must consult {@see self::supported()} first — on a long-lived worker the
 * handler would outlive the request that installed it.
 */
final readonly class ApplicationContentSignalDeadline
{
    public static function supported(): bool
    {
        return function_exists('pcntl_alarm')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler')
            && defined('SIGALRM');
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $operation
     * @return TReturn
     */
    public function run(callable $operation, int $timeoutSeconds): mixed
    {
        $timeout_seconds = min(30, max(1, $timeoutSeconds));
        $previous_alarm = pcntl_alarm(0);

        if ($previous_alarm > 0) {
            pcntl_alarm($previous_alarm);

            throw new LogicException('An application content deadline cannot replace an active process alarm.');
        }

        $previous_async_signals = pcntl_async_signals(true);
        $previous_handler = pcntl_signal_get_handler(SIGALRM);
        $started_at = hrtime(true);

        pcntl_signal(SIGALRM, static function (): never {
            throw new ApplicationContentDeadlineExceededException('Application content retrieval exceeded its deadline.');
        });
        pcntl_alarm($timeout_seconds);

        try {
            $result = $operation();
            $elapsed_seconds = (hrtime(true) - $started_at) / 1_000_000_000;

            if ($elapsed_seconds > $timeout_seconds) {
                throw new ApplicationContentDeadlineExceededException('Application content retrieval exceeded its deadline.');
            }

            return $result;
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous_handler);
            pcntl_async_signals($previous_async_signals);
        }
    }
}

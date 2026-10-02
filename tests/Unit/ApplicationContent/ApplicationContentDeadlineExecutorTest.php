<?php

declare(strict_types=1);

use Modules\AI\Concurrency\ApplicationContentSignalDeadline;
use Modules\AI\Services\ApplicationContent\ApplicationContentDeadlineExecutor;
use Modules\AI\Services\ApplicationContent\Exceptions\ApplicationContentDeadlineExceededException;

it('returns the operation result without installing signal handlers', function (): void {
    $executor = new ApplicationContentDeadlineExecutor(signalsAllowed: false);

    $handler_before = function_exists('pcntl_signal_get_handler')
        ? pcntl_signal_get_handler(SIGALRM)
        : null;

    $result = $executor->run(static fn (): string => 'payload', 5);

    expect($result)->toBe('payload');

    if (function_exists('pcntl_signal_get_handler')) {
        expect(pcntl_signal_get_handler(SIGALRM))->toBe($handler_before);
    }
});

it('reports a deadline breach after the fact when signals are unavailable', function (): void {
    $executor = new ApplicationContentDeadlineExecutor(signalsAllowed: false);

    expect(fn (): mixed => $executor->run(static function (): string {
        usleep(1_100_000);

        return 'too slow';
    }, 1))->toThrow(ApplicationContentDeadlineExceededException::class);
});

it('still interrupts with a signal when signals are explicitly allowed', function (): void {
    if (! ApplicationContentSignalDeadline::supported()) {
        $this->markTestSkipped('pcntl signals unavailable in this environment.');
    }

    $executor = new ApplicationContentDeadlineExecutor(signalsAllowed: true);

    expect($executor->run(static fn (): string => 'fast', 5))->toBe('fast');
});

it('falls back to the soft deadline and keeps the alarm of a queue worker', function (): void {
    if (! ApplicationContentSignalDeadline::supported()) {
        $this->markTestSkipped('pcntl signals unavailable in this environment.');
    }

    $worker_handler = static function (): void {};
    $previous_handler = pcntl_signal_get_handler(SIGALRM);
    pcntl_signal(SIGALRM, $worker_handler);
    pcntl_alarm(30);

    try {
        $executor = new ApplicationContentDeadlineExecutor(signalsAllowed: true);

        expect($executor->run(static fn (): string => 'payload', 5))->toBe('payload')
            ->and(fn (): mixed => $executor->run(static function (): string {
                usleep(1_100_000);

                return 'too slow';
            }, 1))->toThrow(ApplicationContentDeadlineExceededException::class)
            ->and(pcntl_signal_get_handler(SIGALRM))->toBe($worker_handler)
            ->and(pcntl_alarm(0))->toBeGreaterThan(0);
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, $previous_handler);
    }
});

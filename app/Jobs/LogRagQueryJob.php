<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\AI\Services\Documentation\Analytics\RagQueryAnalyticsWriter;
use Modules\AI\Services\Documentation\Analytics\RagQueryLog;

/**
 * Writes one documentation query log document off the request. It carries the finished document,
 * built and screened in the request (see {@see RagQueryLog}): no user id, no model.
 *
 * One try: the log is telemetry, and the writer already swallows a failure.
 */
final class LogRagQueryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  array<string, string|int|float|bool>  $document
     */
    public function __construct(public readonly array $document) {}

    public function handle(RagQueryAnalyticsWriter $writer): void
    {
        $writer->write($this->document);
    }
}

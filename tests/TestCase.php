<?php

declare(strict_types=1);

namespace Modules\AI\Tests;

use Illuminate\Support\Facades\Queue;
use Modules\AI\Jobs\GenerateConversationTitleJob;

/**
 * AI tests use the full Laraplate application bootstrap (same as production).
 */
abstract class TestCase extends \Tests\TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs the queue in-process, and this job calls a model: it never runs behind a
        // test that answers a message. A test about the title runs the job itself.
        Queue::fake([GenerateConversationTitleJob::class]);
    }
}

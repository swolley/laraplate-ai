<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\AI\Models\ActionRequest;
use Modules\AI\Services\ActionRequestService;

final class ExecuteActionRequestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public ActionRequest $actionRequest,
    ) {}

    public function handle(ActionRequestService $actionRequestService): void
    {
        $request = $this->actionRequest->fresh();

        if ($request === null || ! in_array($request->status, ['approved', 'executing'], true)) {
            return;
        }

        $request->update(['status' => 'executing']);

        try {
            $result = $actionRequestService->runToolHandler($request);
            $request->update([
                'status' => 'completed',
                'result' => is_array($result) ? $result : ['value' => $result],
                'executed_at' => now(),
            ]);
        } catch (Exception $e) {
            Log::error('Action request execution failed', [
                'action_request_id' => $request->id,
                'tool_name' => $request->tool_name,
                'error' => $e->getMessage(),
            ]);
            $request->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'executed_at' => now(),
            ]);
        }
    }
}

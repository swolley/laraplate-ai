<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Contracts;

use Closure;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\Stream\RunProgress;
use Modules\Core\Models\User;

interface InAppAssistanceServiceInterface
{
    /**
     * @param  array<string, mixed>|null  $request_context
     * @param  (Closure(string, string): void)|null  $progress  told where the run is, with the words of {@see RunProgress}: `started` or `finished` and a step, or `refused` and a code
     */
    public function respond(
        Conversation $conversation,
        User $authenticated_user,
        string $user_input,
        ?array $request_context = null,
        ?Closure $progress = null,
    ): Message;
}

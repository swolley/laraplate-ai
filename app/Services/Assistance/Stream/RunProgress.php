<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Stream;

/**
 * The words `InAppAssistanceService::respond()` uses to report where a run is. They are fixed by the
 * server: a client sees these labels and the coarse refusal code, never what happened inside.
 */
final class RunProgress
{
    public const string STARTED = 'started';

    public const string FINISHED = 'finished';

    public const string REFUSED = 'refused';

    /**
     * Documentation and application content are retrieved and the tools prepared.
     */
    public const string STEP_RETRIEVE = 'retrieve';

    /**
     * The model writes the answer.
     */
    public const string STEP_ANSWER = 'answer';

    /**
     * The complete output is validated and stored.
     */
    public const string STEP_VALIDATE = 'validate';

    /**
     * A policy, guardrail or authorization stopped the run.
     */
    public const string POLICY_DENIED = 'POLICY_DENIED';

    /**
     * Anything else went wrong: the provider, a timeout, a failure of the run itself.
     */
    public const string PROVIDER_ERROR = 'PROVIDER_ERROR';
}

<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Policies\AssistanceSafetyDecision;
use Modules\AI\Services\Assistance\Policies\DeterministicAssistanceSafetyClassifier;
use Modules\AI\Tests\Stubs\Assistance\InjectionCorpus;

it('refuses every attempt to take the assistant out of its rules', function (string $attempt): void {
    expect((new DeterministicAssistanceSafetyClassifier)->classify($attempt))->toBe(AssistanceSafetyDecision::Unsafe);
})->with(InjectionCorpus::attempts());

it('does not refuse an ordinary request that merely looks like an attempt', function (string $request): void {
    expect((new DeterministicAssistanceSafetyClassifier)->classify($request))->toBe(AssistanceSafetyDecision::Safe);
})->with(InjectionCorpus::benign());

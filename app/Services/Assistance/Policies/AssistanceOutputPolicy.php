<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use Modules\AI\Exceptions\AssistancePolicyViolationException;

final readonly class AssistanceOutputPolicy
{
    public function __construct(
        private RestrictedTopicPolicy $restricted_topics,
        private int $max_length = 8000,
    ) {}

    public function validate(string $output): string
    {
        $output = mb_trim($output);

        if ($output === '' || $this->max_length < mb_strlen($output)) {
            throw new AssistancePolicyViolationException('output_bounds');
        }

        if ($this->restricted_topics->isRestricted($output)
            || str_contains($output, '<?php')
            || preg_match('/```(?:php|sql|bash|shell|env)\b/iu', $output) === 1
            || preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b.{0,200}\b(?:FROM|INTO|SET|WHERE)\b/u', $output) === 1
            || preg_match('/\b[A-Z][A-Z0-9_]{2,}\s*=\s*(?:base64:)?[A-Za-z0-9+\/_=-]{16,}/u', $output) === 1
            || preg_match('/\b(?:sk|pk|api)[-_][A-Za-z0-9_-]{16,}\b/u', $output) === 1
            || preg_match('/\bBearer\s+[A-Za-z0-9._~-]{16,}\b/u', $output) === 1
            || preg_match('/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/u', $output) === 1) {
            throw new AssistancePolicyViolationException('unsafe_output');
        }

        return $output;
    }

    public function insufficientEvidence(string $locale): string
    {
        $message = str_starts_with(mb_strtolower($locale), 'it')
            ? 'Non dispongo di informazioni visibili sufficienti per rispondere a questa richiesta.'
            : 'I do not have enough visible information to answer this request.';

        return $this->validate($message);
    }
}

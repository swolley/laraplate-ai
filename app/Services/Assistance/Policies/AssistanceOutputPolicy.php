<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use Modules\AI\Exceptions\AssistancePolicyViolationException;

final readonly class AssistanceOutputPolicy
{
    /**
     * An answer that says a change was made, in English or Italian: "I have updated", "has been
     * applied", "ho modificato", "è stato salvato".
     */
    private const string APPLIED_CLAIM = '/\b(?:i(?:\'|’)?ve|i have|we(?:\'|’)?ve|we have|has been|have been|was|were|is now|are now|ho|abbiamo|è stato|è stata|sono stati|sono state|è ora|sono ora|ora è)\s+(?:\p{L}+\s+){0,2}?(?:changed|updated|applied|set|saved|switched|enabled|disabled|modified|configured|done|modificat\p{L}*|cambiat\p{L}*|aggiornat\p{L}*|impostat\p{L}*|applicat\p{L}*|salvat\p{L}*|attivat\p{L}*|disattivat\p{L}*|fatto)\b/iu';

    public function __construct(
        private RestrictedTopicPolicy $restricted_topics,
        private int $max_length = 8000,
    ) {}

    /**
     * Whether the text says that a change was made, in English or Italian.
     */
    public static function claimsChange(string $output): bool
    {
        return preg_match(self::APPLIED_CLAIM, $output) === 1;
    }

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

    /**
     * A message that carries proposals must not say that one of them was carried out: a proposal
     * waits for the user, and nothing has changed. An answer that claims otherwise is replaced by a
     * plain statement that the suggestion waits, the proposals themselves being untouched.
     */
    public function reportPendingProposals(string $output, string $locale): string
    {
        if (! self::claimsChange($output)) {
            return $output;
        }

        $message = str_starts_with(mb_strtolower($locale), 'it')
            ? 'Ti ho preparato un suggerimento da rivedere. Non cambia nulla finché non lo accetti.'
            : 'I prepared a suggestion for you to review. Nothing changes until you accept it.';

        return $this->validate($message);
    }

    /**
     * A message that carries write proposals must not say that one of them was carried out, and must say
     * that it waits: nothing has changed until the person confirms it in the interface. An answer that
     * claims otherwise is replaced by the plain statement; any other gets the statement appended.
     */
    public function reportPendingWrites(string $output, string $locale): string
    {
        $notice = str_starts_with(mb_strtolower($locale), 'it')
            ? 'Ho preparato una modifica da confermare. Non cambia nulla finché non la confermi.'
            : 'I prepared a change for you to confirm. Nothing changes until you confirm it.';

        if (self::claimsChange($output)) {
            return $this->validate($notice);
        }

        return $this->validate($output . "\n\n" . $notice);
    }

    public function insufficientEvidence(string $locale): string
    {
        $message = str_starts_with(mb_strtolower($locale), 'it')
            ? 'Non dispongo di informazioni visibili sufficienti per rispondere a questa richiesta.'
            : 'I do not have enough visible information to answer this request.';

        return $this->validate($message);
    }

    public function clarificationRequired(string $locale): string
    {
        $message = str_starts_with(mb_strtolower($locale), 'it')
            ? 'Specifica a quale area dell’applicazione si riferisce la richiesta.'
            : 'Please specify which application area your request refers to.';

        return $this->validate($message);
    }
}

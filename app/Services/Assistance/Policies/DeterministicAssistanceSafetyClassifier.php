<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use Modules\AI\Services\Assistance\Contracts\AssistanceSafetyClassifierInterface;

/**
 * The deterministic layer against instructions hidden in what the assistant reads or is told.
 *
 * It refuses text that tries to override the rules, reassign the assistant's role, exfiltrate its
 * instructions, claim permissions, impersonate the chat structure or hide its content. English and
 * Italian. A pattern is added only with a benign look-alike in the tests that it must not flag: a
 * classifier that refuses ordinary questions is a worse assistant, not a safer one.
 */
final class DeterministicAssistanceSafetyClassifier implements AssistanceSafetyClassifierInterface
{
    /**
     * Characters that hide text or reorder it: zero-width, bidirectional controls, the BOM.
     */
    private const string HIDDEN_CHARACTERS = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u';

    /**
     * @var list<string>
     */
    private const array PATTERNS = [
        // Override the rules
        '/\b(ignore|disregard|override|bypass)\b.{0,80}\b(previous|prior|system|instruction|policy|safety)\b/iu',
        '/\b(ignor\w*|dimentic\w*|scavalc\w*|bypass\w*|aggir\w*|trascur\w*)\b.{0,80}\b(precedent\w*|istruzion\w*|regol\w*|politic\w*|sicurezza|vincol\w*|restrizion\w*|guardrail\w*)\b/iu',
        '/\b(forget|disable|turn off|lift|remove|drop)\b.{0,40}\b(your|all|the|these|those|any)\b.{0,30}\b(rules|restrictions|guardrails|safety|limits|instructions|filters)\b/iu',
        '/\b(disattiv\w*|rimuov\w*|togli\w*|sospend\w*)\b.{0,40}\b(le tue|tutte le|le|i)\b.{0,30}\b(regole|restrizioni|guardrail|limiti|istruzioni|filtri|vincoli)\b/iu',
        '/\b(new|updated|revised)\s+(rules|instructions|system prompt)\b\s*[:\-]/iu',
        '/\b(nuove|nuovo)\s+(regole|istruzioni|prompt di sistema)\b\s*[:\-]/iu',
        // Reassign the role
        '/\byou are now\b|\bfrom now on,? (you|act|behave|respond)\b|\bpretend (to be|you are|that you)\b|\bact as (an? |the )?(unrestricted|unfiltered|root|admin\w*|system|developer)\b/iu',
        '/\b(sei ora|da ora in poi|d[\'’]ora in poi)\b|\bfai (finta|come se)\b|\bcomportati (da|come)\b|\bimmagina di essere\b/iu',
        '/\b(jailbreak|DAN mode|developer mode|god mode|modalit[àa] sviluppatore)\b/iu',
        // Exfiltrate the instructions
        '/\b(reveal|show|print|repeat|extract)\b.{0,50}\b(system prompt|instructions?|policy|guardrails?)\b/iu',
        '/\b(rivela\w*|mostra\w*|stampa\w*|ripet\w*|estra\w*|dimmi|elenca\w*)\b.{0,50}\b(le tue (istruzioni|regole|linee guida)|prompt di sistema|il tuo prompt|le istruzioni di sistema|gli strumenti (che hai|a tua disposizione))\b/iu',
        '/\bwhat (are|were) (your|the) (initial|original|hidden|system|first) (instructions|rules|prompt)\b/iu',
        // Claim or grant permissions
        '/\b(i|we) (hereby )?(authori[sz]e|allow|permit|grant) you\b|\byou (now )?have (my|our|full|admin\w*|the) (permission|authori[sz]ation|clearance)\b|\b(grant|give) (me|yourself) (all|full|admin\w*|every)\b.{0,20}\b(permissions?|access|rights)\b/iu',
        '/\bti (autorizzo|permetto|concedo)\b|\bhai (il mio|il nostro|pieno|tutti i) (permesso|permessi|accesso|diritti)\b|\bdammi (tutti i|pieno)\b.{0,20}\b(permessi|accesso|diritti)\b/iu',
        // Impersonate the structure of the conversation
        '/<\/?\s*(system|assistant|tool|developer)\s*>|\[\/?(INST|SYS|SYSTEM)\]|<\|(im_start|im_end|system|assistant)\|>/iu',
        '/^\s*(system|assistant|developer)\s*:\s*\S/imu',
        '/^\s*#{1,4}\s*(system|instructions?|new rules)\b/imu',
    ];

    public function classify(string $input): AssistanceSafetyDecision
    {
        if (preg_match(self::HIDDEN_CHARACTERS, $input) === 1) {
            return AssistanceSafetyDecision::Unsafe;
        }

        foreach ([$input, $this->unspaced($input)] as $candidate) {
            foreach (self::PATTERNS as $pattern) {
                if (preg_match($pattern, $candidate) === 1) {
                    return AssistanceSafetyDecision::Unsafe;
                }
            }
        }

        return AssistanceSafetyDecision::Safe;
    }

    /**
     * Rejoins words that were split into single letters ("i g n o r e   p r e v i o u s", the words
     * kept apart by a longer gap) or by
     * punctuation inside the word ("ig-nore"), which defeats a pattern written for the word.
     */
    private function unspaced(string $input): string
    {
        $joined = preg_replace('/(?<=\b\p{L})[\s._\-*](?=\p{L}\b)/u', '', $input) ?? $input;

        return preg_replace('/(?<=\p{L})[._\-*](?=\p{L})/u', '', $joined) ?? $joined;
    }
}

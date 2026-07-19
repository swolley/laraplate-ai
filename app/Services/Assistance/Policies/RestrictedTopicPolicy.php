<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

final class RestrictedTopicPolicy
{
    /** @var list<string> */
    private const array PATTERNS = [
        '/\b(license key|licence key|chiave di licenza|dettagli (?:della )?licenza)\b/iu',
        '/(?=.*\b(licen[cs](?:e|ing)|licenza|chiave di licenza)\b)(?=.*\b(intern\w*|verific\w*|enforc\w*)\b)/iu',
        '/\b(codice sorgente|source code|codice php|classe php|metodo interno|stack trace)\b/iu',
        '/\b(api[ _-]?key|api token|access token|secret|credential|password|cookie di sessione|session id)\b/iu',
        '/\b(database|schema|tabell[ae]|columns?|colonn[ae]|query sql|connessione).{0,50}\b(intern|us[ao]|dettagl|elenc)/iu',
        '/\b(PostgreSQL|MySQL|MariaDB|SQLite|SQL Server|Oracle Database|MongoDB|Redis)\b/u',
        '#\b(?:postgres|mysql|mongodb|redis|sqlsrv)://[^\s]+#iu',
        '/(?=.*\b(altri utenti|other users)\b)(?=.*\b(privat\w*|personal\w*|dati|data|elenc\w*)\b)/iu',
        '/\b(permission interne|internal permissions|acl intern|ruoli intern|hidden permissions)\b/iu',
        '/\b(algoritmo di cifratura|encryption algorithm|chiave di cifratura|encryption key|cipher|key management)\b/iu',
        '/\b(system prompt|prompt di sistema|regole dei tool|tool internals?|guardrail rules?)\b/iu',
        '/\b(topologia.{0,20}(server|infrastruttur)|infrastructure topology|server interni)\b/iu',
    ];

    public function isRestricted(string $text): bool
    {
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }
}

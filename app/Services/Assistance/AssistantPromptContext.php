<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use InvalidArgumentException;

final readonly class AssistantPromptContext
{
    private const array ALLOWED_PRESENTATION_PREFERENCES = [
        'accessibility',
        'locale',
        'response_format',
        'verbosity',
    ];

    /**
     * @param array<string, mixed> $presentationPreferences
     * @param list<array<string, mixed>> $safeCitations
     * @param list<array<string, mixed>> $authorizedResults
     */
    public function __construct(
        public string $policyVersion,
        public array $presentationPreferences,
        public array $safeCitations,
        public array $authorizedResults,
    ) {
        if (trim($policyVersion) === '') {
            throw new InvalidArgumentException('Assistant policy version cannot be blank.');
        }

        foreach (array_keys($presentationPreferences) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_PRESENTATION_PREFERENCES, true)) {
                throw new InvalidArgumentException('Unsupported assistant presentation preference.');
            }
        }

        AssistantControlPlaneData::assertPromptSafe($presentationPreferences);
        AssistantControlPlaneData::assertPromptSafe($safeCitations);
        AssistantControlPlaneData::assertPromptSafe($authorizedResults);
    }
}

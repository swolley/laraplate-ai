<?php

declare(strict_types=1);

namespace Modules\AI\Console\Concerns;

use Modules\AI\Ai\Rag\DocumentationIndexProfile;

/**
 * The `--profile` option of the documentation index commands: developer, user, or all.
 */
trait ResolvesDocumentationProfiles
{
    /**
     * The profiles the option names, or null (after reporting it) when it names none.
     *
     * @return list<DocumentationIndexProfile>|null
     */
    private function documentationProfiles(): ?array
    {
        $option = $this->option('profile');
        $name = is_string($option) ? mb_strtolower(mb_trim($option)) : '';

        if ($name === 'all') {
            return DocumentationIndexProfile::cases();
        }

        $profile = in_array($name, ['developer', 'user'], true) ? DocumentationIndexProfile::tryFrom($name) : null;

        if ($profile === null) {
            $this->error('Invalid profile. Expected developer, user, or all.');

            return null;
        }

        return [$profile];
    }
}

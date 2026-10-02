<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Moderation;

use Modules\Core\Contracts\ModerationAdapter;
use Modules\Core\Data\ModerationInput;
use Modules\Core\Data\ModerationRequest;
use Modules\Core\Models\Modification;

/**
 * Turns pending {@see ModeratedTestModel} writes into moderation requests, the way a module
 * registers an adapter for its own content.
 */
final readonly class ModeratedTestModelAdapter implements ModerationAdapter
{
    public function modelClass(): string
    {
        return ModeratedTestModel::class;
    }

    public function supports(Modification $modification): bool
    {
        return $modification->modifiable_type === ModeratedTestModel::class;
    }

    public function build(Modification $modification): ModerationRequest
    {
        $body = (string) ($modification->modifications['body']['modified'] ?? '');

        return new ModerationRequest(
            input: new ModerationInput(
                subjectText: $body,
                locale: 'en',
                contextSections: [],
                profile: 'test.moderated',
            ),
            systemPrompt: 'Moderate.',
            userPrompt: $body,
        );
    }
}

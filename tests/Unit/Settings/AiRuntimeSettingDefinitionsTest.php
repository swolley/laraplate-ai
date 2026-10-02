<?php

declare(strict_types=1);

use Modules\AI\Database\Seeders\AIDatabaseSeeder;

it('defines ai runtime settings with current defaults and choices', function (): void {
    $definitions = collect(AIDatabaseSeeder::runtimeSettingDefinitions())->keyBy('name');

    expect($definitions->get('features.embeddings.enabled')['value'])->toBeFalse()
        ->and($definitions->get('features.faq.splitter.driver')['value'])->toBe('markdown_aware')
        ->and($definitions->get('features.faq.splitter.driver')['choices'])
        ->toBe(['markdown_aware', 'sentence', 'delimiter'])
        ->and($definitions->get('features.moderation.approval_mode')['choices'])->toBe(['threshold', 'dual']);
});

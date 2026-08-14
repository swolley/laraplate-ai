<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\AI\Services\FeatureModuleGate;
use Modules\AI\Tests\Unit\SearchableModelStub;
use Modules\Core\Models\Setting;

it('allows any module when the allowlist is empty', function (): void {
    Config::set('ai.features.embeddings.modules', []);

    expect(FeatureModuleGate::allows('embeddings', new SearchableModelStub))->toBeTrue()
        ->and(FeatureModuleGate::allows('embeddings', new Setting))->toBeTrue();
});

it('allows any module when the allowlist key is missing', function (): void {
    Config::set('ai.features.embeddings', ['default_provider' => 'x']);

    expect(FeatureModuleGate::allows('embeddings', new SearchableModelStub))->toBeTrue();
});

it('restricts to the listed modules', function (): void {
    Config::set('ai.features.embeddings.modules', ['ai']);

    // SearchableModelStub is Modules\AI\Tests\Unit\... → module "AI".
    expect(FeatureModuleGate::allows('embeddings', new SearchableModelStub))->toBeTrue()
        // Setting is Modules\Core\... → module "Core", not listed.
        ->and(FeatureModuleGate::allows('embeddings', new Setting))->toBeFalse();
});

it('matches module names case-insensitively', function (): void {
    Config::set('ai.features.translation.modules', ['CMS', 'Ai']);

    expect(FeatureModuleGate::allows('translation', new SearchableModelStub))->toBeTrue();
});

it('excludes models outside any module when an allowlist is set', function (): void {
    Config::set('ai.features.embeddings.modules', ['ai']);

    expect(FeatureModuleGate::allows('embeddings', new App\Models\User))->toBeFalse();
});

it('reads the allowlist per feature independently', function (): void {
    Config::set('ai.features.embeddings.modules', ['ai']);
    Config::set('ai.features.translation.modules', ['cms']);

    expect(FeatureModuleGate::allows('embeddings', new SearchableModelStub))->toBeTrue()
        ->and(FeatureModuleGate::allows('translation', new SearchableModelStub))->toBeFalse();
});

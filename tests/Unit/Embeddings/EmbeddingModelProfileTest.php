<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;

it('tells a service model by its last path segment, ignoring case and spaces', function (string $reported, string $expected, bool $same): void {
    expect(EmbeddingModelProfile::sameServiceModel($reported, $expected))->toBe($same);
})->with([
    'identical' => ['intfloat/multilingual-e5-small', 'intfloat/multilingual-e5-small', true],
    'another organisation prefix' => ['sentence-transformers/all-MiniLM-L6-v2', 'all-MiniLM-L6-v2', true],
    'case' => ['Intfloat/Multilingual-E5-Small', 'intfloat/multilingual-e5-small', true],
    'spaces' => ['  all-MiniLM-L6-v2 ', 'all-MiniLM-L6-v2', true],
    'another model' => ['sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2', 'intfloat/multilingual-e5-small', false],
    'a name that only ends the same' => ['xmultilingual-e5-small', 'multilingual-e5-small', false],
    'an empty name' => ['', 'all-MiniLM-L6-v2', false],
]);

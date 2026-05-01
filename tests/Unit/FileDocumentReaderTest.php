<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Modules\AI\Services\Documentation\FileDocumentReader;

it('uses nested relative paths and prefix in source names', function (): void {
    $base = sys_get_temp_dir() . '/lp-reader-' . uniqid();
    mkdir($base . '/nested', 0755, true);
    file_put_contents($base . '/nested/deep.md', '# Deep');

    try {
        $reader = new FileDocumentReader($base, FileDocumentReader::DOCUMENT_EXTENSIONS, 'faq-module-Core');
        $documents = $reader->getDocuments();

        expect($documents)->toHaveCount(1)
            ->and($documents[0]->getSourceName())->toBe('faq-module-Core/nested/deep.md');
    } finally {
        File::deleteDirectory($base);
    }
});

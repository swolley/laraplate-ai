<?php

declare(strict_types=1);

namespace Modules\AI\Console\Concerns;

use Illuminate\Filesystem\Filesystem;

/**
 * The option and report handling shared by the evaluation commands: a non-empty string option, a check on the
 * output path before any work is done, and an atomic JSON write.
 */
trait WritesJsonReport
{
    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }

    /**
     * Why the report cannot be written to $output_path, or null when it can.
     */
    private function outputPathIssue(Filesystem $files, string $output_path): ?string
    {
        if ($files->exists($output_path) && ! (bool) $this->option('force')) {
            return 'The output report already exists. Use --force to replace it.';
        }

        $output_directory = dirname($output_path);

        if (! $files->isDirectory($output_directory) || ! $files->isWritable($output_directory)) {
            return 'The output directory is unavailable.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeReport(Filesystem $files, string $output_path, array $report): void
    {
        $encoded = json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );
        $temporary_path = $output_path . '.tmp-' . bin2hex(random_bytes(6));

        try {
            $files->put($temporary_path, $encoded . PHP_EOL, true);
            $files->move($temporary_path, $output_path);
        } finally {
            if ($files->exists($temporary_path)) {
                $files->delete($temporary_path);
            }
        }
    }
}

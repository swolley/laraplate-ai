<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Exception;
use Illuminate\Console\Command;
use Modules\AI\Services\DocumentationService;
use Override;

final class LaraplateHelpCommand extends Command
{
    #[Override]
    protected $signature = 'ai:laraplate-help
                            {--question= : Ask a single question and exit}';

    #[Override]
    protected $description = 'Open a terminal RAG assistant for Laraplate documentation (interactive REPL or one-shot with --question). <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(DocumentationService $documentationService): int
    {
        if (! config('ai.features.faq.enabled', true)) {
            $this->warn('FAQ/RAG is disabled in config (ai.features.faq.enabled).');

            return self::FAILURE;
        }

        if (! $documentationService->isAvailable()) {
            $this->warn('RAG index is not available. Run `php artisan ai:index-docs` first.');

            return self::FAILURE;
        }

        $question = (string) ($this->option('question') ?? '');

        if ($question !== '') {
            return $this->answerAndRender($documentationService, $question);
        }

        $this->line('Laraplate help chat is ready. Type your question, or `exit` to quit.');

        while (true) {
            $input = (string) $this->ask('You');
            $trimmed = mb_trim($input);

            if ($trimmed === '') {
                continue;
            }

            if (in_array(mb_strtolower($trimmed), ['exit', 'quit'], true)) {
                $this->line('Bye.');

                return self::SUCCESS;
            }

            $status = $this->answerAndRender($documentationService, $trimmed);

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }
    }

    private function answerAndRender(DocumentationService $documentationService, string $question): int
    {
        try {
            $result = $documentationService->answerQuestion($question);
        } catch (Exception $exception) {
            $this->error('Help query failed: ' . $exception->getMessage());

            return self::FAILURE;
        }

        $answer = (string) ($result['answer'] ?? '');
        $citations = is_array($result['citations'] ?? null) ? $result['citations'] : [];

        $this->newLine();
        $this->line('<info>Assistant:</info>');
        $this->line($answer !== '' ? $answer : '(empty answer)');

        if ($citations !== []) {
            $this->newLine();
            $this->line('<comment>Sources:</comment>');

            foreach ($citations as $citation) {
                $source = (string) ($citation['source'] ?? 'Unknown');
                $score = $citation['score'] ?? null;
                $score_label = is_numeric($score) ? sprintf(' (score: %.3f)', (float) $score) : '';
                $this->line('- ' . $source . $score_label);
            }
        }

        $this->newLine();

        return self::SUCCESS;
    }
}


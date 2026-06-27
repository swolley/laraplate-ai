<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\AI\Contracts\ITranslatableModelClassNames;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Services\Translation\TranslationService;
use Modules\Core\Helpers\LocaleContext;
use Override;

final class TranslateContentCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    #[Override]
    protected $signature = 'model:translate
                            {model : The model to translate}
                            {--id= : Specific model ID to translate}
                            {--locale= : Specific locale to translate to}
                            {--all : Translate to all available locales}
                            {--force : Force translation even if translation exists}
                            {--sync : Run synchronously instead of queued}';

    /**
     * The console command description.
     */
    #[Override]
    protected $description = 'Translate content, categories, or tags to other locales <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function __construct(private readonly ITranslatableModelClassNames $translatable_model_class_names)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @codeCoverageIgnore Depends on Core module (HasTranslations, LocaleContext)
     */
    public function handle(): int
    {
        $model_type = $this->argument('model');
        $model_id = $this->option('id');
        $locale = $this->option('locale');
        $all_locales = $this->option('all');
        $force = $this->option('force');
        $sync = $this->option('sync');

        $locales = $all_locales ? LocaleContext::getAvailable() : ($locale ? [$locale] : []);

        $translatable_models = $this->translatable_model_class_names->all();

        if (! Str::contains($model_type, '\\')) {
            $model_type = '\\' . $model_type;
        }
        $model_class = array_filter($translatable_models, fn (string $model): bool => Str::endsWith($model, $model_type));

        if ($model_class === []) {
            $this->error("Invalid model type: {$model_type}. Not found or not translatable");

            return Command::FAILURE;
        }

        if (count($model_class) > 1) {
            $this->error('Multiple models found: ' . implode(', ', $model_class));

            return Command::FAILURE;
        }

        $model_class = head($model_class);

        if (! is_string($model_class) || $model_class === '') {
            $this->error("Invalid model type: {$model_type}. Not found or not translatable");

            return Command::FAILURE;
        }

        $query = $model_class::query();

        if ($model_id) {
            $query->where('id', $model_id);
        }

        $models = $query->get();
        $count = $models->count();

        if ($count === 0) {
            $this->warn('No models found to translate');

            return Command::SUCCESS;
        }

        $this->info("Found {$count} model(s) to translate");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($models as $model) {
            if ($sync) {
                // Run synchronously
                $job = new TranslateModelJob($model, $locales, $force);
                $job->handle(resolve(TranslationService::class));
            } else {
                // Dispatch to queue
                dispatch(new TranslateModelJob($model, $locales, $force));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Translation job(s) dispatched for {$count} model(s)");

        return Command::SUCCESS;
    }
}

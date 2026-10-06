<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Console\TranslateContentCommand;
use Modules\AI\Contracts\ITranslatableModelClassNames;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Services\DiscoveryTranslatableModelClassNames;
use Modules\AI\Tests\Stubs\TranslatableTestModel;
use Modules\AI\Tests\Stubs\TranslatableTestModelTranslation;
use Modules\AI\Tests\Stubs\Translation\FixedTranslatableModelClassNames;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @param  list<class-string>  $model_list
 */
function translate_content_command_with_models(array $model_list): TranslateContentCommand
{
    $resolver = new FixedTranslatableModelClassNames($model_list);

    return new TranslateContentCommand($resolver);
}

beforeEach(function (): void {
    Queue::fake();
});

it('streams translate command models instead of loading them all', function (): void {
    $source = file_get_contents(base_path('Modules/AI/app/Console/TranslateContentCommand.php'));

    expect($source)->toContain('->lazyById(')
        ->and($source)->not->toContain('$models = $query->get();');
});

it('binds default translatable model class names resolver', function (): void {
    expect(app(ITranslatableModelClassNames::class))->toBeInstanceOf(DiscoveryTranslatableModelClassNames::class);
});

it('returns failure when model type not found', function (): void {
    $command = translate_content_command_with_models([]);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'Post']);

    expect($tester->getStatusCode())->toBe(TranslateContentCommand::FAILURE);
});

it('returns failure when multiple models found', function (): void {
    $command = translate_content_command_with_models([
        'StubNamespace\\Alpha\\TranslatableTestModel',
        'StubNamespace\\Beta\\TranslatableTestModel',
    ]);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'TranslatableTestModel']);

    expect($tester->getStatusCode())->toBe(TranslateContentCommand::FAILURE);
});

it('returns success with no models to translate', function (): void {
    Schema::create('test_translatable_models', function ($table): void {
        $table->id();
        $table->timestamps();
    });
    Schema::create('test_translatable_model_translations', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('translatable_test_model_id');
        $table->string('locale', 10);
        $table->text('title')->nullable();
        $table->text('content')->nullable();
        $table->timestamps();
    });

    $command = translate_content_command_with_models([TranslatableTestModel::class]);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'TranslatableTestModel']);

    expect($tester->getStatusCode())->toBe(TranslateContentCommand::SUCCESS);
    Queue::assertNothingPushed();
});

it('dispatches TranslateModelJob for each model', function (): void {
    Schema::create('test_translatable_models', function ($table): void {
        $table->id();
        $table->timestamps();
    });
    Schema::create('test_translatable_model_translations', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('translatable_test_model_id');
        $table->string('locale', 10);
        $table->text('title')->nullable();
        $table->text('content')->nullable();
        $table->timestamps();
    });

    $model = TranslatableTestModel::query()->create([]);
    TranslatableTestModelTranslation::query()->create([
        'translatable_test_model_id' => $model->id,
        'locale' => config('app.locale'),
        'title' => 'Default title',
        'content' => 'Default content',
    ]);

    $command = translate_content_command_with_models([TranslatableTestModel::class]);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'TranslatableTestModel', '--all' => true]);

    expect($tester->getStatusCode())->toBe(TranslateContentCommand::SUCCESS);
    Queue::assertPushed(TranslateModelJob::class);
});

it('keeps translating the other models when one fails in sync mode', function (): void {
    Schema::create('test_translatable_models', function ($table): void {
        $table->id();
        $table->timestamps();
    });
    Schema::create('test_translatable_model_translations', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('translatable_test_model_id');
        $table->string('locale', 10);
        $table->text('title')->nullable();
        $table->text('content')->nullable();
        $table->timestamps();
    });

    foreach ([1, 2] as $ignored) {
        $model = TranslatableTestModel::query()->create([]);
        TranslatableTestModelTranslation::query()->create([
            'translatable_test_model_id' => $model->id,
            'locale' => config('app.locale'),
            'title' => 'Default title',
            'content' => 'Default content',
        ]);
    }

    $translations = Mockery::mock(Modules\AI\Services\Translation\TranslationService::class);
    $translations->shouldReceive('translate')->andThrow(new RuntimeException('provider down'));
    app()->instance(Modules\AI\Services\Translation\TranslationService::class, $translations);

    $command = translate_content_command_with_models([TranslatableTestModel::class]);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'TranslatableTestModel', '--locale' => 'it', '--sync' => true]);

    expect($tester->getStatusCode())->toBe(TranslateContentCommand::SUCCESS)
        ->and(mb_substr_count($tester->getDisplay(), 'provider down'))->toBe(2);
});

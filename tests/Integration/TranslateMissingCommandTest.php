<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Console\TranslateMissingCommand;
use Modules\AI\Contracts\ITranslatableModelClassNames;
use Modules\AI\Tests\Stubs\TranslatableMissingTestModel;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @param  list<class-string>  $model_list
 */
function translate_missing_command_with_models(array $model_list): TranslateMissingCommand
{
    $resolver = new class($model_list) implements ITranslatableModelClassNames
    {
        public function __construct(private readonly array $model_list) {}

        /**
         * @return list<class-string>
         */
        public function all(): array
        {
            return $this->model_list;
        }
    };

    return new TranslateMissingCommand($resolver);
}

beforeEach(function (): void {
    Queue::fake();
});

it('streams missing translation command models instead of loading each locale fully', function (): void {
    $source = file_get_contents(base_path('Modules/AI/app/Console/TranslateMissingCommand.php'));

    expect($source)->toContain('->lazyById(')
        ->and($source)->not->toContain('$missing = $query->get();');
});

it('returns failure when model type not found', function (): void {
    $command = translate_missing_command_with_models([]);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'Post']);

    expect($tester->getStatusCode())->toBe(TranslateMissingCommand::FAILURE);
});

it('returns failure when multiple models found', function (): void {
    $command = translate_missing_command_with_models([
        'StubNamespace\\Alpha\\TranslatableMissingTestModel',
        'StubNamespace\\Beta\\TranslatableMissingTestModel',
    ]);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'TranslatableMissingTestModel']);

    expect($tester->getStatusCode())->toBe(TranslateMissingCommand::FAILURE);
});

it('returns success with no missing translations', function (): void {
    Schema::create('test_translatable_missing', function ($table): void {
        $table->id();
        $table->timestamps();
    });
    Schema::create('translation_stub', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('translatable_missing_test_model_id');
        $table->string('locale');
        $table->timestamps();
    });

    $command = translate_missing_command_with_models([TranslatableMissingTestModel::class]);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'TranslatableMissingTestModel']);

    expect($tester->getStatusCode())->toBe(TranslateMissingCommand::SUCCESS);
    Queue::assertNothingPushed();
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Console\TranslateMissingCommand;
use Stubs\TranslatableMissingTestModel;
use Stubs\TranslatableMissingTestModelA;
use Stubs\TranslatableMissingTestModelB;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    Config::set('_test_models', [TranslatableMissingTestModel::class]);
    Queue::fake();
});

it('returns failure when model type not found', function (): void {
    Config::set('_test_models', []);

    $command = new TranslateMissingCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'Post']);

    expect($tester->getStatusCode())->toBe(TranslateMissingCommand::FAILURE);
});

it('returns failure when multiple models found', function (): void {
    Config::set('_test_models', [TranslatableMissingTestModelA::class, TranslatableMissingTestModelB::class]);

    $command = new TranslateMissingCommand;
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

    $command = new TranslateMissingCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'TranslatableMissingTestModel']);

    expect($tester->getStatusCode())->toBe(TranslateMissingCommand::SUCCESS);
    Queue::assertNothingPushed();
});

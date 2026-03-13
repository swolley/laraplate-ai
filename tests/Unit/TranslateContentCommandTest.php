<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Console\TranslateContentCommand;
use Modules\AI\Jobs\TranslateModelJob;
use Stubs\TranslatableTestModel;
use Stubs\TranslatableTestModelA;
use Stubs\TranslatableTestModelB;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    Config::set('_test_models', [TranslatableTestModel::class]);
    Queue::fake();
});

it('returns failure when model type not found', function (): void {
    Config::set('_test_models', []);

    $command = new TranslateContentCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'Post']);

    expect($tester->getStatusCode())->toBe(TranslateContentCommand::FAILURE);
});

it('returns failure when multiple models found', function (): void {
    Config::set('_test_models', [TranslatableTestModelA::class, TranslatableTestModelB::class]);

    $command = new TranslateContentCommand;
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

    $command = new TranslateContentCommand;
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

    TranslatableTestModel::query()->create([]);

    $command = new TranslateContentCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['model' => 'TranslatableTestModel', '--all' => true]);

    expect($tester->getStatusCode())->toBe(TranslateContentCommand::SUCCESS);
    Queue::assertPushed(TranslateModelJob::class);
});

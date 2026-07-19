<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\AI\Console\LaraplateHelpCommand;
use Modules\AI\Services\DocumentationService;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    Config::set('ai.features.faq.enabled', true);
});

it('returns failure when FAQ is disabled', function (): void {
    Config::set('ai.features.faq.enabled', false);

    $mock = Mockery::mock(DocumentationService::class);
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I use CRUD APIs?']);

    expect($tester->getStatusCode())->toBe(LaraplateHelpCommand::FAILURE);
});

it('returns failure when rag index is not available', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('isAvailable')
        ->once()
        ->andReturn(false);
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I use CRUD APIs?']);

    expect($tester->getStatusCode())->toBe(LaraplateHelpCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('ai:index-docs');
});

it('answers one-shot question and prints sources', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('isAvailable')
        ->once()
        ->andReturn(true);
    $mock->shouldReceive('answerDeveloperQuestion')
        ->once()
        ->with('How do I use CRUD APIs?', Modules\AI\Enums\AssistantProfile::DeveloperHelp)
        ->andReturn([
            'answer' => 'Use /crud/select and /crud/update endpoints.',
            'citations' => [
                ['source' => 'faq-module-Core:/docs/rag/MODULE.md', 'score' => 0.91],
            ],
        ]);
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I use CRUD APIs?']);

    expect($tester->getStatusCode())->toBe(LaraplateHelpCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('Assistant:')
        ->and($tester->getDisplay())->toContain('Sources:')
        ->and($tester->getDisplay())->toContain('faq-module-Core:/docs/rag/MODULE.md');
});

it('returns failure when answering fails', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('isAvailable')
        ->once()
        ->andReturn(true);
    $mock->shouldReceive('answerDeveloperQuestion')
        ->once()
        ->with('How do I use CRUD APIs?', Modules\AI\Enums\AssistantProfile::DeveloperHelp)
        ->andThrow(new Exception('Provider timeout'));
    app()->instance(DocumentationService::class, $mock);

    $command = new LaraplateHelpCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--question' => 'How do I use CRUD APIs?']);

    expect($tester->getStatusCode())->toBe(LaraplateHelpCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('Provider timeout');
});

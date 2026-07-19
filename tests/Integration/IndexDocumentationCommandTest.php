<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Console\IndexDocumentationCommand;
use Modules\AI\Services\DocumentationService;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    Config::set('ai.features.faq.enabled', true);
});

it('returns failure when FAQ disabled', function (): void {
    Config::set('ai.features.faq.enabled', false);

    $mock = Mockery::mock(DocumentationService::class);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE);
});

it('returns failure when path does not exist', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--path' => '/nonexistent/path/12345']);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE);
});

it('calls documentationService indexDocuments and shows count', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, false, DocumentationIndexProfile::Developer)
        ->andReturn(42);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('42');
});

it('passes full flag to documentationService', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, true, DocumentationIndexProfile::Developer)
        ->andReturn(3);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--full' => true]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('3');
});

it('warns that full indexing resets the in-process memory store', function (): void {
    Config::set('ai.features.faq.vector_store', 'memory');

    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, true, DocumentationIndexProfile::Developer)
        ->andReturn(3);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--full' => true]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('Vector store driver is "memory"')
        ->and($tester->getDisplay())->toContain('3');
});

it('returns failure on exception', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, false, DocumentationIndexProfile::Developer)
        ->andThrow(new Exception('Indexing error'));
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute([]);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('Indexing error');
});

it('indexes the explicitly selected user corpus', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldReceive('indexDocuments')
        ->once()
        ->with(null, false, DocumentationIndexProfile::User)
        ->andReturn(2);
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--profile' => 'user']);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('2');
});

it('rejects an invalid documentation profile', function (): void {
    $mock = Mockery::mock(DocumentationService::class);
    $mock->shouldNotReceive('indexDocuments');
    app()->instance(DocumentationService::class, $mock);

    $command = new IndexDocumentationCommand;
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute(['--profile' => 'unsafe']);

    expect($tester->getStatusCode())->toBe(IndexDocumentationCommand::FAILURE);
});

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Console\RepairMissingEmbeddingsCommand;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @param  array<string, mixed>  $args
 */
function run_repair_embeddings_command(array $args): CommandTester
{
    $command = app(RepairMissingEmbeddingsCommand::class);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute($args);

    return $tester;
}

beforeEach(function (): void {
    Config::set('search.vector_search.enabled', true);

    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
});

it('returns failure when the model class does not exist', function (): void {
    $tester = run_repair_embeddings_command(['model' => 'Nope\\Missing']);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::FAILURE);
});

it('returns failure when vector search is disabled', function (): void {
    Config::set('search.vector_search.enabled', false);

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::FAILURE);
});

it('dispatches jobs only for records missing an embedding and carrying embeddable text', function (): void {
    Queue::fake();

    $alpha = new EmbeddableTestModel(['title' => 'Alpha']);
    $alpha->saveQuietly();

    $beta = new EmbeddableTestModel(['title' => 'Beta']);
    $beta->saveQuietly();

    // No embeddable text -> skipped.
    $empty = new EmbeddableTestModel(['title' => '']);
    $empty->saveQuietly();

    // Already embedded -> excluded by whereDoesntHave.
    $already = new EmbeddableTestModel(['title' => 'Delta']);
    $already->saveQuietly();
    $already->embeddings()->create(['embedding' => [0.1, 0.2]]);

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS);
    Queue::assertPushed(GenerateEmbeddingsJob::class, 2);
});

<?php

declare(strict_types=1);

namespace Modules\AI\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

require_once __DIR__ . '/Stubs/helpers.php';
require_once __DIR__ . '/Stubs/CoreTraits.php';
require_once __DIR__ . '/Stubs/TranslatableTestModels.php';
require_once __DIR__ . '/Stubs/TranslatableMissingTestModels.php';
require_once __DIR__ . '/Stubs/TranslateModelJobStub.php';
require_once __DIR__ . '/Stubs/AppController.php';
require_once __DIR__ . '/Stubs/CoreModuleServiceProvider.php';
require_once __DIR__ . '/Stubs/CoreTranslationInterface.php';
require_once __DIR__ . '/Stubs/CoreSearchable.php';
require_once __DIR__ . '/Stubs/CoreEvents.php';
require_once __DIR__ . '/Stubs/CoreResponseBuilder.php';
require_once __DIR__ . '/Stubs/CoreUser.php';

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [];
    }

    protected function defineEnvironment($app): void
    {
        $app->make(\Illuminate\Contracts\Config\Repository::class)->set('ai', require __DIR__ . '/../config/config.php');
        $app->make(\Illuminate\Contracts\Config\Repository::class)->set('database.default', 'testing');
        $app->make(\Illuminate\Contracts\Config\Repository::class)->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('system_message')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('memory_enabled')->default(true);
            $table->text('summary')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->unsignedInteger('token_count')->nullable();
            $table->timestamps();
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('ai_action_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('ai_conversations')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('tool_name');
            $table->json('tool_args');
            $table->string('risk_level', 16);
            $table->string('status', 32);
            $table->unsignedBigInteger('modification_id')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_conversation_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->text('summary');
            $table->json('facts')->nullable();
            $table->unsignedInteger('message_count');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ai_contextual_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('context')->nullable();
            $table->text('suggestion');
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
}

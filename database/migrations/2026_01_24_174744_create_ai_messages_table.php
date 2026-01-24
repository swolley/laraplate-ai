<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->string('role', 20)->comment('Message role: system, user, assistant');
            $table->text('content')->comment('Message content');
            $table->json('metadata')->nullable()->comment('Additional data: context, tool calls, citations, etc.');
            $table->unsignedInteger('token_count')->nullable()->comment('Token count for cost tracking');
            MigrateUtils::timestamps($table);

            /**
             * Creating an index on both 'conversation_id' and 'created_at' allows for more efficient queries
             * when retrieving messages of a specific conversation ordered by time (e.g., fetching conversation history).
             * This composite index helps the database quickly locate all messages belonging to a conversation and sort/filter by their creation timestamp,
             * resulting in faster performance when loading conversation threads, especially as the number of messages grows.
             */
            $table->index(['conversation_id', 'created_at'], 'ai_messages_conversation_created_IDX');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
    }
};

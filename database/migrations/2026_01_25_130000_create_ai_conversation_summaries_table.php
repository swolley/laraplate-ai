<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add memory fields to conversations
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->boolean('memory_enabled')->default(true)->after('metadata')
                ->comment('Whether memory/summary is enabled for this conversation');
            $table->text('summary')->nullable()->after('memory_enabled')
                ->comment('Rolling summary of the conversation');
        });

        // Create summaries table for historical snapshots
        Schema::create('ai_conversation_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('ai_conversations')->cascadeOnDelete();
            $table->text('summary')->comment('Summary text at this point in time');
            $table->json('facts')->nullable()->comment('Extracted key facts from conversation');
            $table->unsignedInteger('message_count')->comment('Number of messages when summary was created');
            $table->timestamp('created_at')->useCurrent();

            $table->index('conversation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_conversation_summaries');

        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropColumn(['memory_enabled', 'summary']);
        });
    }
};

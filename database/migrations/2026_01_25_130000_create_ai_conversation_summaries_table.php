<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Enums\AITables;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add memory fields to conversations
        Schema::table(AITables::Conversations->value, function (Blueprint $table): void {
            $table->boolean('memory_enabled')->default(true)->after('metadata')
                ->comment('Whether memory/summary is enabled for this conversation');
            $table->text('summary')->nullable()->after('memory_enabled')
                ->comment('Rolling summary of the conversation');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );
        });

        // Create summaries table for historical snapshots
        $table_name = AITables::ConversationSummaries->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained(AITables::Conversations->value, 'id', "{$table_name}_conversation_id_FK")->cascadeOnDelete();
            $table->text('summary')->comment('Summary text at this point in time');
            $table->json('facts')->nullable()->comment('Extracted key facts from conversation');
            $table->unsignedInteger('message_count')->comment('Number of messages when summary was created');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );

            $table->index('conversation_id', "{$table_name}_conversation_id_IDX");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(AITables::ConversationSummaries->value);

        Schema::table(AITables::Conversations->value, function (Blueprint $table): void {
            $table->dropColumn(['memory_enabled', 'summary']);
        });
    }
};

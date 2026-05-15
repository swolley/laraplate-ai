<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Enums\AITables;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $table_name = AITables::ContextualSuggestions->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('user_id')->constrained(CoreTables::Users->value, 'id', "{$table_name}_user_id_FK")->cascadeOnDelete();
            $table->json('context')->comment('UI context (page, action, data)');
            $table->text('suggestion')->comment('AI-generated suggestion text');
            $table->timestamp('dismissed_at')->nullable()->comment('When user dismissed the suggestion');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );

            $table->index(['user_id', 'created_at'], "{$table_name}_user_created_IDX");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(AITables::ContextualSuggestions->value);
    }
};

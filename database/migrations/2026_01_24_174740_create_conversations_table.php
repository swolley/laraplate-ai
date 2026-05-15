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
        $table_name = AITables::Conversations->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('user_id')->constrained(CoreTables::Users->value, 'id', "{$table_name}_user_id_FK")->cascadeOnDelete();
            $table->string('title')->nullable()->comment('Conversation title (auto-generated or manual)');
            $table->text('system_message')->nullable()->comment('Custom system prompt for this conversation');
            $table->json('metadata')->nullable()->comment('Additional context data');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(AITables::Conversations->value);
    }
};

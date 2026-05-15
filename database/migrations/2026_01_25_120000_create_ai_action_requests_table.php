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
        $table_name = AITables::ActionRequests->value;
        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained(AITables::Conversations->value, 'id', "{$table_name}_conversation_id_FK")->nullOnDelete();
            $table->foreignId('user_id')->constrained(CoreTables::Users->value, 'id', "{$table_name}_user_id_FK")->cascadeOnDelete();
            $table->string('tool_name')->comment('Tool identifier from ToolRegistry');
            $table->json('tool_args')->comment('Arguments for the tool');
            $table->string('risk_level', 16)->comment('low, medium, high');
            $table->string('status', 32)->comment('pending_user_confirmation, pending_admin_approval, approved, executing, completed, failed, rejected');
            $table->unsignedBigInteger('modification_id')->nullable()->comment('Link to Modification for high-risk approval');
            $table->json('result')->nullable()->comment('Tool execution result');
            $table->text('error')->nullable()->comment('Error message if failed');
            $table->timestamp('executed_at')->nullable()->comment('When the tool was executed');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );

            $table->index(['user_id', 'status']);
            $table->index('status');

            if (Schema::hasTable(CoreTables::Modifications->value)) {
                $table->foreign('modification_id', "{$table_name}_modification_id_FK")->references('id')->on(CoreTables::Modifications->value)->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(AITables::ActionRequests->value);
    }
};

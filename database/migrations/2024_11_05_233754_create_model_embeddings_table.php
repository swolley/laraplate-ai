<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MigrateUtils;
use Modules\AI\Models\ModelEmbedding;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $connection = new ModelEmbedding()->getConnection();
        $driver = $connection->getDriverName();

        $supports_vector = $driver === 'pgsql' && DB::connection($connection->getName())->table('pg_available_extensions')->where('name', 'vector')->exists();

        Schema::connection($connection->getName())->create('model_embeddings', function (Blueprint $table) use ($supports_vector): void {
            $table->id();
            $table->morphs('model', 'embedding_model_IDX');

            if ($supports_vector) {
                $table->vector('embedding', 1536)->nullable(false)->comment('The generated embedding of the model'); // 1536 dimensions for OpenAI
            } else {
                $table->json('embedding')->nullable(false)->comment('The generated embedding of the model');
            }
            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
            );
        });

        if ($driver === 'pgsql' && $supports_vector) {
            DB::connection($connection->getName())->statement('CREATE INDEX idx_model_embeddings_embedding ON model_embeddings USING ivfflat (embedding vector_cosine_ops);');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $connection = new ModelEmbedding()->getConnection();

        Schema::connection($connection->getName())->dropIfExists('model_embeddings');
        Schema::dropIfExists('model_embeddings');
    }
};

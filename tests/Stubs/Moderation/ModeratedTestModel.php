<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Moderation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\Concerns\HasApprovals;

/**
 * A model whose writes go through Core's approval flow, standing in for whatever module
 * content AI moderates. AI tests use it so they need no other module.
 */
final class ModeratedTestModel extends Model
{
    use HasApprovals;

    public const string TABLE = 'test_moderated_models';

    protected $table = self::TABLE;

    protected $fillable = ['body'];

    public static function createTable(): void
    {
        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    public static function dropTable(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}

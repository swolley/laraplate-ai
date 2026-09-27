<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Services\ModerationAdapterRegistry;

/**
 * Per-entity switches for AI moderation, one boolean per model that has a registered
 * moderation adapter. The AI module declares them without knowing which module owns
 * each entity: the adapter registry is the only source.
 */
final readonly class ModerationEntitySettings
{
    public const string NAME_PREFIX = 'ai.features.moderation.entities.';

    public function __construct(
        private ModerationAdapterRegistry $registry,
    ) {}

    public static function nameFor(Model $model): string
    {
        return self::NAME_PREFIX . $model->getTable();
    }

    public static function enabledFor(Model $model): bool
    {
        return (bool) config(self::nameFor($model), false);
    }

    /**
     * @return list<array{name: string, value: bool, encrypted: bool, choices: null, type: SettingTypeEnum, group_name: string, description: string}>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->registry->modelClasses() as $model_class) {
            $model = new $model_class();

            $definitions[] = [
                'name' => self::nameFor($model),
                'value' => false,
                'encrypted' => false,
                'choices' => null,
                'type' => SettingTypeEnum::Boolean,
                'group_name' => 'moderation',
                'description' => "AI moderation for {$model->getTable()}",
            ];
        }

        return $definitions;
    }
}

<?php

declare(strict_types=1);

namespace Modules\AI\Database\Seeders;

use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Models\Setting;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Seeding\SeedDefinition;
use Modules\Core\Seeding\SeedReconciler;

class AIDatabaseSeeder extends Seeder
{
    /**
     * @return array<int, array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}>
     */
    public static function runtimeSettingDefinitions(): array
    {
        return [
            self::setting('ai.features.embeddings.enabled', true, SettingTypeEnum::Boolean, 'ai', 'Enable embeddings generation'),
            self::setting('ai.features.translation.enabled', true, SettingTypeEnum::Boolean, 'ai', 'Enable automatic translation'),
            self::setting('ai.features.chat.enabled', true, SettingTypeEnum::Boolean, 'ai', 'Enable AI chat'),
            self::setting('ai.features.chat.max_context_messages', 50, SettingTypeEnum::Integer, 'ai', 'Maximum chat context messages'),
            self::setting('ai.features.chat.enable_summary', false, SettingTypeEnum::Boolean, 'ai', 'Enable chat summarization'),
            self::setting('ai.features.faq.enabled', true, SettingTypeEnum::Boolean, 'ai', 'Enable FAQ/RAG answers'),
            self::setting('ai.features.faq.max_documents', 5, SettingTypeEnum::Integer, 'ai', 'Maximum FAQ documents to retrieve'),
            self::setting('ai.features.faq.min_similarity', 0.7, SettingTypeEnum::Float, 'ai', 'Minimum FAQ similarity score'),
            self::setting('ai.features.faq.question_detection.enabled', true, SettingTypeEnum::Boolean, 'ai', 'Enable FAQ question detection'),
            self::setting('ai.features.faq.format_citations', true, SettingTypeEnum::Boolean, 'ai', 'Append citations to FAQ answers'),
            self::setting('ai.features.faq.splitter.driver', 'markdown_aware', SettingTypeEnum::String, 'ai', 'FAQ document splitter driver', ['markdown_aware', 'sentence', 'delimiter']),
            self::setting('ai.features.faq.splitter.max_words', 250, SettingTypeEnum::Integer, 'ai', 'Maximum words per FAQ chunk'),
            self::setting('ai.features.faq.splitter.overlap_words', 0, SettingTypeEnum::Integer, 'ai', 'FAQ chunk overlap words'),
            self::setting('ai.features.faq.splitter.prepend_heading_breadcrumb', true, SettingTypeEnum::Boolean, 'ai', 'Prepend heading breadcrumb to FAQ chunks'),
            self::setting('ai.features.contextual_suggestions.enabled', false, SettingTypeEnum::Boolean, 'ai', 'Enable contextual suggestions'),
            self::setting('ai.features.contextual_suggestions.cooldown_minutes', 5, SettingTypeEnum::Integer, 'ai', 'Contextual suggestions cooldown minutes'),
            self::setting('ai.features.contextual_suggestions.cache_ttl', 3600, SettingTypeEnum::Integer, 'ai', 'Contextual suggestions cache TTL seconds'),
            self::setting('ai.features.moderation.enabled', true, SettingTypeEnum::Boolean, 'ai', 'Enable AI moderation'),
            self::setting('ai.features.moderation.approval_mode', 'threshold', SettingTypeEnum::String, 'ai', 'AI moderation approval mode', ['threshold', 'dual']),
            self::setting('ai.features.moderation.ai_participates_in_approvals', true, SettingTypeEnum::Boolean, 'ai', 'Allow AI votes in approval workflow'),
            self::setting('ai.features.moderation.approve_confidence_threshold', 0.85, SettingTypeEnum::Float, 'ai', 'AI moderation approval confidence threshold'),
            self::setting('ai.features.moderation.reject_confidence_threshold', 0.85, SettingTypeEnum::Float, 'ai', 'AI moderation rejection confidence threshold'),
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $outcome = app(SeedReconciler::class)->reconcile(
            SeedDefinition::for(Setting::class)
                ->identity(['name'])
                ->structural(['type', 'group_name', 'description', 'choices'])
                ->initial(['value'])
                ->ownedBy('AI')
                ->rows(self::runtimeSettingDefinitions()),
        );

        $this->command?->line(
            '    - created ' . count($outcome->created) . ', realigned ' . count($outcome->realigned) . ", unchanged {$outcome->unchanged}",
        );
    }

    /**
     * @return array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}
     */
    private static function setting(string $name, mixed $value, SettingTypeEnum $type, string $group, string $description, ?array $choices = null): array
    {
        return [
            'name' => $name,
            'value' => $value,
            'encrypted' => false,
            'choices' => $choices,
            'type' => $type,
            'group_name' => $group,
            'description' => $description,
        ];
    }
}

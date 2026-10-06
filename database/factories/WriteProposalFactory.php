<?php

declare(strict_types=1);

namespace Modules\AI\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\WriteProposal;

/**
 * @extends Factory<WriteProposal>
 */
final class WriteProposalFactory extends Factory
{
    /**
     * @var class-string<WriteProposal>
     */
    protected $model = WriteProposal::class;

    public function definition(): array
    {
        return [
            'user_id' => user_class()::factory(),
            'conversation_id' => static fn (array $attributes): mixed => Conversation::query()->create(['user_id' => $attributes['user_id']])->getKey(),
            'tool' => 'crud_update_core_setting',
            'module' => 'core',
            'entity' => 'setting',
            'operation' => 'update',
            'payload' => ['id' => '1', 'attributes' => ['value' => 'x']],
            'summary' => ['changes' => ['value' => 'x']],
            'requires_approval' => false,
            'status' => WriteProposalStatus::Proposed->value,
            'outcome' => null,
            'expires_at' => now()->addMinutes(30),
            'resolved_at' => null,
        ];
    }

    public function expired(): self
    {
        return $this->state(fn (array $attributes): array => ['expires_at' => now()->subMinute()]);
    }

    public function withStatus(WriteProposalStatus $status): self
    {
        return $this->state(fn (array $attributes): array => ['status' => $status->value]);
    }
}

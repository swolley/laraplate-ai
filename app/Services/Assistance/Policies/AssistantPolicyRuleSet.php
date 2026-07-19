<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use InvalidArgumentException;

final readonly class AssistantPolicyRuleSet
{
    /**
     * @param list<string> $allowedCorpora
     * @param list<string> $allowedTools
     * @param list<string> $allowedFields
     * @param list<string> $deniedCorpora
     * @param list<string> $deniedTools
     * @param list<string> $deniedFields
     */
    public function __construct(
        public string $instruction,
        public array $allowedCorpora,
        public array $allowedTools,
        public array $allowedFields,
        public array $deniedCorpora = [],
        public array $deniedTools = [],
        public array $deniedFields = [],
    ) {
        if (trim($instruction) === '') {
            throw new InvalidArgumentException('Assistant policy instruction cannot be blank.');
        }

        foreach ([
            $allowedCorpora,
            $allowedTools,
            $allowedFields,
            $deniedCorpora,
            $deniedTools,
            $deniedFields,
        ] as $values) {
            foreach ($values as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException('Assistant policy sets require non-empty string identifiers.');
                }
            }
        }
    }

    /** @param list<self> $sets */
    public static function union(array $sets): self
    {
        if ($sets === []) {
            throw new InvalidArgumentException('Cannot union an empty assistant policy set.');
        }

        return new self(
            instruction: implode("\n", array_map(static fn (self $set): string => $set->instruction, $sets)),
            allowedCorpora: self::merge(...array_map(static fn (self $set): array => $set->allowedCorpora, $sets)),
            allowedTools: self::merge(...array_map(static fn (self $set): array => $set->allowedTools, $sets)),
            allowedFields: self::merge(...array_map(static fn (self $set): array => $set->allowedFields, $sets)),
            deniedCorpora: self::merge(...array_map(static fn (self $set): array => $set->deniedCorpora, $sets)),
            deniedTools: self::merge(...array_map(static fn (self $set): array => $set->deniedTools, $sets)),
            deniedFields: self::merge(...array_map(static fn (self $set): array => $set->deniedFields, $sets)),
        );
    }

    public function intersect(self $specific): self
    {
        $denied_corpora = self::merge($this->deniedCorpora, $specific->deniedCorpora);
        $denied_tools = self::merge($this->deniedTools, $specific->deniedTools);
        $denied_fields = self::merge($this->deniedFields, $specific->deniedFields);

        return new self(
            instruction: $this->instruction . "\n" . $specific->instruction,
            allowedCorpora: self::without(array_values(array_intersect($this->allowedCorpora, $specific->allowedCorpora)), $denied_corpora),
            allowedTools: self::without(array_values(array_intersect($this->allowedTools, $specific->allowedTools)), $denied_tools),
            allowedFields: self::without(array_values(array_intersect($this->allowedFields, $specific->allowedFields)), $denied_fields),
            deniedCorpora: $denied_corpora,
            deniedTools: $denied_tools,
            deniedFields: $denied_fields,
        );
    }

    /**
     * @param list<string> ...$sets
     * @return list<string>
     */
    private static function merge(array ...$sets): array
    {
        $values = array_values(array_unique(array_merge(...$sets)));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $denied
     * @return list<string>
     */
    private static function without(array $allowed, array $denied): array
    {
        $values = array_values(array_diff($allowed, $denied));
        sort($values, SORT_STRING);

        return $values;
    }
}

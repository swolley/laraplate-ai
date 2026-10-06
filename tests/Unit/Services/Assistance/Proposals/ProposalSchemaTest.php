<?php

declare(strict_types=1);

use Modules\AI\Services\Assistance\Proposals\ProposalSchema;

it('supports the declared subset and ignores annotations', function (array $schema): void {
    expect(ProposalSchema::isSupported($schema))->toBeTrue();
})->with([
    'enum' => [['type' => 'string', 'enum' => ['table', 'cards'], 'title' => 'Layout', 'description' => 'How lists look', 'default' => 'table']],
    'integer range' => [['type' => 'integer', 'minimum' => 5, 'maximum' => 100]],
    'const' => [['const' => 'cards']],
    'list of values' => [['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 20], 'maxItems' => 5]],
    'object' => [['type' => 'object', 'properties' => ['sort' => ['type' => 'string']], 'required' => ['sort'], 'additionalProperties' => false]],
    'several types' => [['type' => ['string', 'null']]],
]);

it('does not support a schema that is outside the subset or constrains nothing', function (array $schema): void {
    expect(ProposalSchema::isSupported($schema))->toBeFalse();
})->with([
    'empty' => [[]],
    'annotations only' => [['title' => 'Anything']],
    'a pattern' => [['type' => 'string', 'pattern' => '^(a+)+$']],
    'a reference' => [['$ref' => '#/definitions/x']],
    'a combinator' => [['oneOf' => [['type' => 'string'], ['type' => 'integer']]]],
    'a format' => [['type' => 'string', 'format' => 'uri']],
    'an unknown type' => [['type' => 'color']],
    'an empty enum' => [['enum' => []]],
    'a nested keyword outside the subset' => [['type' => 'array', 'items' => ['type' => 'string', 'pattern' => 'x']]],
    'too deep' => [['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'string']]]]]],
    'too large' => [['type' => 'string', 'enum' => array_map(static fn (int $i): string => str_repeat('x', 60) . $i, range(1, 40))]],
]);

it('accepts what the schema allows and nothing else', function (array $schema, mixed $value, bool $accepted): void {
    expect(ProposalSchema::accepts($schema, $value))->toBe($accepted);
})->with([
    'enum member' => [['type' => 'string', 'enum' => ['table', 'cards']], 'cards', true],
    'not an enum member' => [['type' => 'string', 'enum' => ['table', 'cards']], 'grid', false],
    'wrong type' => [['type' => 'string'], 12, false],
    'integer as a whole float' => [['type' => 'integer'], 12.0, true],
    'a fraction is not an integer' => [['type' => 'integer'], 12.5, false],
    'below minimum' => [['type' => 'integer', 'minimum' => 5], 4, false],
    'at maximum' => [['type' => 'integer', 'maximum' => 5], 5, true],
    'above exclusive maximum' => [['type' => 'number', 'exclusiveMaximum' => 5], 5, false],
    'string too long' => [['type' => 'string', 'maxLength' => 3], 'abcd', false],
    'string too short' => [['type' => 'string', 'minLength' => 2], 'a', false],
    'const match' => [['const' => 'cards'], 'cards', true],
    'const mismatch' => [['const' => 'cards'], 'table', false],
    'null allowed by type list' => [['type' => ['string', 'null']], null, true],
    'list item rejected' => [['type' => 'array', 'items' => ['type' => 'string']], ['a', 3], false],
    'list too long' => [['type' => 'array', 'maxItems' => 1], ['a', 'b'], false],
    'list ok' => [['type' => 'array', 'items' => ['type' => 'string']], ['a', 'b'], true],
    'object missing a required key' => [['type' => 'object', 'required' => ['sort']], ['dir' => 'asc'], false],
    'object with an extra key refused' => [['type' => 'object', 'properties' => ['sort' => ['type' => 'string']], 'additionalProperties' => false], ['sort' => 'a', 'dir' => 'b'], false],
    'object property rejected' => [['type' => 'object', 'properties' => ['sort' => ['type' => 'string']]], ['sort' => 3], false],
    'object ok' => [['type' => 'object', 'properties' => ['sort' => ['type' => 'string']], 'required' => ['sort']], ['sort' => 'name'], true],
    'a list is not an object' => [['type' => 'object'], ['a'], false],
    'an unsupported schema accepts nothing' => [['type' => 'string', 'pattern' => '.*'], 'x', false],
]);

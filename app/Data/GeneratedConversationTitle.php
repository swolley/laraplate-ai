<?php

declare(strict_types=1);

namespace Modules\AI\Data;

use NeuronAI\StructuredOutput\SchemaProperty;
use NeuronAI\StructuredOutput\Validation\Rules\Length;
use NeuronAI\StructuredOutput\Validation\Rules\NotBlank;
use NeuronAI\StructuredOutput\Validation\Rules\Regex;
use NeuronAI\StructuredOutput\Validation\Rules\WordsCount;

/**
 * The structured output the model is asked for when it titles a conversation. Neuron sends the schema
 * to the model, validates what comes back against the rules below and, when a rule fails, asks the
 * model again once with the list of what was wrong. A title that still breaks a rule is not used.
 *
 * The pattern keeps the title plain: no quote, markdown or line break anywhere, and no punctuation at
 * its end.
 */
final class GeneratedConversationTitle
{
    public const int MIN_WORDS = 2;

    public const int MAX_WORDS = 5;

    public const int MAX_LENGTH = 40;

    #[SchemaProperty(
        description: 'A short title for the conversation: 2 to 5 words, at most 40 characters, plain text in the language of the question, with no quotes, markdown or ending punctuation.',
        required: true,
        minLength: 3,
        maxLength: self::MAX_LENGTH,
    )]
    #[NotBlank]
    #[Length(min: 3, max: self::MAX_LENGTH)]
    #[WordsCount(min: self::MIN_WORDS, max: self::MAX_WORDS)]
    #[Regex('/^[^"“”„«»`*_#>~|\[\]{}\r\n]*[^\s"“”„«»`*_#>~|\[\]{}.,;:!?…–—-]$/u')]
    public string $title;
}

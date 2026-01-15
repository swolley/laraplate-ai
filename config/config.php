<?php

declare(strict_types=1);

return [
    // Rimappato automaticamente come ai.* quando il modulo è attivo
    // Se il modulo è disattivato, questa config non è disponibile

    'features' => [
        'embeddings' => [
            'enabled' => env('AI_EMBEDDINGS_ENABLED', true),
            // NOTE: Future - attivazione per modulo specifico
            // 'modules' => ['cms'], // Se abilitato, permette di attivare embeddings solo per certi moduli
        ],
        'translation' => [
            'enabled' => env('AI_TRANSLATION_ENABLED', true),
            // NOTE: Future - attivazione per modulo specifico
            // 'modules' => ['cms'], // Se abilitato, permette di attivare traduzione solo per certi moduli
        ],
    ],

    'default' => env('AI_PROVIDER', 'ollama'),

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'api_url' => env('OPENAI_API_URL'),
            'model' => env('OPENAI_MODEL'),
        ],

        'ollama' => [
            'api_url' => env('OLLAMA_API_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'llama3.2:3b'),
        ],

        'voyageai' => [
            'api_key' => env('VOYAGEAI_API_KEY'),
            'model' => env('VOYAGEAI_MODEL', 'voyage-3-lite'),
        ],

        'mistral' => [
            'api_key' => env('MISTRAL_API_KEY'),
            'model' => env('MISTRAL_MODEL', 'mistral-large-latest'),
        ],

        'sentence_transformers' => [
            'url' => env('SENTENCE_TRANSFORMERS_URL'),
            'api_key' => env('SENTENCE_TRANSFORMERS_API_KEY'),
        ],

        'deepl' => [
            'api_key' => env('DEEPL_API_KEY'),
        ],
    ],
];

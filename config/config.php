<?php

declare(strict_types=1);

return [
    // Rimappato automaticamente come ai.* quando il modulo è attivo
    // Se il modulo è disattivato, questa config non è disponibile

    // TODO: Future - User/Tenant-selectable AI provider
    // Implement a system where users or tenants can select their preferred AI provider.
    // This would involve:
    // 1. Storing provider preferences in user/tenant settings
    // 2. Creating a ProviderResolver service that checks user preferences
    // 3. Allowing per-conversation provider override
    // 4. Implementing provider capability detection (not all providers support tools, streaming, etc.)
    //
    // Example future config structure:
    // 'user_selectable_provider' => [
    //     'enabled' => env('AI_USER_SELECTABLE_PROVIDER', false),
    //     'allowed_providers' => ['ollama', 'openai', 'mistral', 'anthropic'],
    //     'default_for_new_users' => 'ollama', // Privacy-first default (local)
    // ],

    'features' => [
        'embeddings' => [
            'enabled' => env('AI_EMBEDDINGS_ENABLED', true),
            'default_provider' => env('AI_EMBEDDINGS_PROVIDER', 'sentence_transformers'),
            // NOTE: Future - attivazione per modulo specifico
            // 'modules' => ['cms'], // Se abilitato, permette di attivare embeddings solo per certi moduli
        ],
        'translation' => [
            'enabled' => env('AI_TRANSLATION_ENABLED', true),
            'default_provider' => env('AI_TRANSLATION_PROVIDER', 'deepl'),
            // NOTE: Future - attivazione per modulo specifico
            // 'modules' => ['cms'], // Se abilitato, permette di attivare traduzione solo per certi moduli
        ],
        'chat' => [
            'enabled' => env('AI_CHAT_ENABLED', true),
            'default_provider' => env('AI_CHAT_PROVIDER', 'ollama'),
            'max_context_messages' => env('AI_CHAT_MAX_CONTEXT', 50),
            'enable_summary' => env('AI_CHAT_ENABLE_SUMMARY', false), // Step 5
        ],
        'faq' => [
            'enabled' => env('AI_FAQ_ENABLED', true),
            // Optional extra root for app-level custom docs. Default scan always includes `docs/rag` and active `Modules/*/docs/rag` (see `docs/README.md`).
            'documentation_path' => env('AI_FAQ_DOCS_PATH'),
            'vector_store' => env('AI_FAQ_VECTOR_STORE', 'filesystem'), // memory (testing only), filesystem
            'vector_store_path' => env('AI_FAQ_VECTOR_STORE_PATH'), // null = storage_path('app/ai/faq-vectorstore.json')
            'max_documents' => (int) env('AI_FAQ_MAX_DOCS', 5),
            'min_similarity' => (float) env('AI_FAQ_MIN_SIMILARITY', 0.7),
            'question_detection' => [
                'enabled' => env('AI_FAQ_QUESTION_DETECTION', true),
                // Custom question words per locale (optional override)
                // 'words' => [
                //     'it' => ['cosa', 'come', 'perché', ...],
                //     'en' => ['what', 'how', 'why', ...],
                // ],
            ],
            'format_citations' => env('AI_FAQ_FORMAT_CITATIONS', true), // Append markdown citations to answers
            'splitter' => [
                // Driver options: markdown_aware (default, preserves mermaid/code/tables), sentence, delimiter
                'driver' => env('AI_FAQ_SPLITTER', 'markdown_aware'),
                'max_words' => (int) env('AI_FAQ_SPLITTER_MAX_WORDS', 250),
                'overlap_words' => (int) env('AI_FAQ_SPLITTER_OVERLAP', 0),
                'prepend_heading_breadcrumb' => env('AI_FAQ_SPLITTER_HEADING_BREADCRUMB', true),
            ],
        ],
        'tools' => [
            'enabled' => env('AI_TOOLS_ENABLED', true),
            // Tool definitions with risk levels
            'definitions' => [
                // Example tool definitions (register actual tools via ToolRegistry::register())
                // 'get_user_info' => ['risk_level' => 'low'],
                // 'update_record' => ['risk_level' => 'medium'],
                // 'delete_record' => ['risk_level' => 'high'],
            ],
        ],
        'guardrails' => [
            'enabled' => env('AI_GUARDRAILS_ENABLED', false),
            'prompt_injection_detection' => env('AI_GUARDRAILS_PROMPT_INJECTION', false),
            'lakera_api_key' => env('LAKERA_API_KEY'),
            'lakera_endpoint' => env('LAKERA_ENDPOINT', 'https://api.lakera.ai/'),
            'json_validation' => env('AI_GUARDRAILS_JSON_VALIDATION', false),
            'retry_on_failure' => env('AI_GUARDRAILS_RETRY', true),
        ],
        'search_orchestration' => [
            'enabled' => env('AI_SEARCH_ORCHESTRATION_ENABLED', true),
            'default_provider' => env('AI_SEARCH_ORCHESTRATION_PROVIDER'),
        ],

        'contextual_suggestions' => [
            'enabled' => env('AI_CONTEXTUAL_SUGGESTIONS_ENABLED', false),
            'cooldown_minutes' => (int) env('AI_CONTEXTUAL_SUGGESTIONS_COOLDOWN', 5), // Min minutes between suggestions
            'cache_ttl' => (int) env('AI_CONTEXTUAL_SUGGESTIONS_CACHE_TTL', 3600), // Cache duration in seconds
        ],
        'moderation' => [
            'enabled' => env('AI_MODERATION_ENABLED', env('AI_COMMENT_MODERATION_ENABLED', true)),
            'approval_mode' => env('AI_MODERATION_APPROVAL_MODE', env('AI_COMMENT_APPROVAL_MODE', 'threshold')),
            'ai_participates_in_approvals' => env('AI_MODERATION_AI_VOTES', env('AI_COMMENT_AI_VOTES', true)),
            'approve_confidence_threshold' => (float) env('AI_MODERATION_APPROVE_THRESHOLD', env('AI_COMMENT_MOD_APPROVE_THRESHOLD', 0.85)),
            'reject_confidence_threshold' => (float) env('AI_MODERATION_REJECT_THRESHOLD', env('AI_COMMENT_MOD_REJECT_THRESHOLD', 0.85)),
            'queue' => env('AI_MODERATION_QUEUE', env('AI_COMMENT_MOD_QUEUE', 'default')),
            'provider' => env('AI_MODERATION_PROVIDER', env('AI_COMMENT_MOD_PROVIDER')),
        ],
    ],

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

        'anthropic' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514'),
        ],

        'sentence_transformers' => [
            'url' => env('SENTENCE_TRANSFORMERS_URL'),
            'api_key' => env('SENTENCE_TRANSFORMERS_API_KEY'),
        ],

        'cross_encoder' => [
            'endpoint' => env('CROSS_ENCODER_ENDPOINT', 'http://127.0.0.1:8001/score'),
        ],

        'deepl' => [
            'api_key' => env('DEEPL_API_KEY'),
        ],
    ],
];

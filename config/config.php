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
            //     'enabled' => env('AI_EMBEDDINGS_ENABLED', true),
            'default_provider' => env('AI_EMBEDDINGS_PROVIDER', 'sentence_transformers'),

            // Optional per-module allowlist. Empty = every module (default).
            // When non-empty, only models whose owning module is listed are embedded,
            // e.g. ['cms']. Matched case-insensitively against the model's Modules\{Name}\ namespace.
            'modules' => [],
        ],
        'translation' => [
            //     'enabled' => env('AI_TRANSLATION_ENABLED', true),
            'default_provider' => env('AI_TRANSLATION_PROVIDER', 'deepl'),

            // Optional per-module allowlist. Empty = every module (default).
            // When non-empty, only models whose owning module is listed are translated,
            // e.g. ['cms']. Matched case-insensitively against the model's Modules\{Name}\ namespace.
            'modules' => [],
        ],
        'chat' => [
            //     'enabled' => env('AI_CHAT_ENABLED', true),
            'default_provider' => env('AI_CHAT_PROVIDER', 'ollama'),
            //     'max_context_messages' => env('AI_CHAT_MAX_CONTEXT', 50),
            //     'enable_summary' => env('AI_CHAT_ENABLE_SUMMARY', false),
        ],
        'faq' => [
            // 'enabled' => env('AI_FAQ_ENABLED', true),
            // Optional extra root for app-level custom docs. Default scan always includes `docs/rag` and active `Modules/*/docs/rag` (see `docs/README.md`).
            'documentation_path' => env('AI_FAQ_DOCS_PATH'),
            'vector_store' => env('AI_FAQ_VECTOR_STORE', 'elasticsearch'), // memory (testing only), filesystem, elasticsearch
            'vector_store_path' => env('AI_FAQ_VECTOR_STORE_PATH'), // null = storage_path('app/ai/faq-vectorstore.json')
            'elasticsearch' => [
                'developer_index' => env(
                    'AI_FAQ_DEVELOPER_ES_INDEX',
                    env('AI_FAQ_ES_INDEX', Str::slug(config('app.name')) . '_rag_docs'),
                ),
                'user_index' => env('AI_FAQ_USER_ES_INDEX', Str::slug(config('app.name')) . '_rag_user_docs'),
                // Deprecated alias retained only for migration compatibility.
                'index' => env('AI_FAQ_ES_INDEX', Str::slug(config('app.name')) . '_rag_docs'),
                // Must match the active embeddings provider output dimensionality.
                'embedding_dims' => (int) env('AI_FAQ_ES_EMBEDDING_DIMS', 384),
            ],
            'policy_classification_version' => env('AI_FAQ_POLICY_CLASSIFICATION_VERSION', 'in-app-docs-v1'),
            // 'max_documents' => (int) env('AI_FAQ_MAX_DOCS', 5),
            // 'min_similarity' => (float) env('AI_FAQ_MIN_SIMILARITY', 0.7),
            // 'question_detection' => [
            //     'enabled' => env('AI_FAQ_QUESTION_DETECTION_ENABLED', true),
            //     // Custom question words per locale (optional override)
            //     // 'words' => [
            //     //     'it' => ['cosa', 'come', 'perché', ...],
            //     //     'en' => ['what', 'how', 'why', ...],
            //     // ],
            // ],
            //    'format_citations' => env('AI_FAQ_FORMAT_CITATIONS', true), // Append markdown citations to answers
            // 'splitter' => [
            //     // Driver options: markdown_aware (default, preserves mermaid/code/tables), sentence, delimiter
            //     'driver' => env('AI_FAQ_SPLITTER', 'markdown_aware'),
            //     'max_words' => (int) env('AI_FAQ_SPLITTER_MAX_WORDS', 250),
            //     'overlap_words' => (int) env('AI_FAQ_SPLITTER_OVERLAP', 0),
            //     'prepend_heading_breadcrumb' => env('AI_FAQ_SPLITTER_HEADING_BREADCRUMB', true),
            // ],
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

            // Default CRUD tools exposed to the in-app assistant, opt-in per entity.
            // Empty = no CRUD tools (default). Each key is "module.entity"; the value
            // lists the allowed operations. A tool is exposed only for an operation
            // the acting user is actually permitted to perform (otherwise it is not
            // offered at all). Every call is authorized and ACL-scoped by Core's
            // CrudService as the acting user. Moderation belongs to the model:
            // HasApprovals entities capture writes for approval on save unless the
            // writer holds the `approve` credit — the provider does not add its own
            // approval step. list/search accept structured `filters` and `sort`
            // (the CRUD request format), and every result echoes the executed
            // `request` (verb/module/entity/filters/sort/page/limit) so a client can
            // reapply the same filters to its tables. The `view` operation is
            // "configure mode": it returns only the request spec (apply=true) and
            // does NOT fetch data — the UI applies the filters and loads them itself.
            // Operations: view, list, detail, search, summarize, export, create,
            // update, delete, plus the approval verbs pending_approvals,
            // approve, disapprove (gated by the `approve` permission).
            // `summarize` (gated by `select`) groups records and returns
            // per-group counts plus optional sum/avg/min/max metrics. `export`
            // (gated by `select`) returns an ACL-scoped, filtered recordset as
            // an inline base64 CSV or PDF file.
            'crud' => [
                'entities' => [
                    // 'cms.content' => ['list', 'detail', 'search', 'create', 'update', 'delete'],
                ],
            ],
        ],
        'guardrails' => [
            'enabled' => env('AI_GUARDRAILS_ENABLED', false),
            'prompt_injection_detection' => env('AI_GUARDRAILS_PROMPT_INJECTION', false),
            'lakera_api_key' => env('LAKERA_API_KEY'),
            'lakera_endpoint' => env('LAKERA_ENDPOINT', 'https://api.lakera.ai/'),
            'json_validation' => env('AI_GUARDRAILS_JSON_VALIDATION', false),
            'retry_on_failure' => env('AI_GUARDRAILS_RETRY', true),
            // In-app assistance policies are mandatory and do not use the optional flags above.
            'in_app_policy_version' => 'in-app-v1',
            'in_app_max_input_length' => 4000,
            'in_app_max_output_length' => 8000,
        ],
        'search_orchestration' => [
            'enabled' => env('AI_SEARCH_ORCHESTRATION_ENABLED', true),
            'default_provider' => env('AI_SEARCH_ORCHESTRATION_PROVIDER'),
        ],

        // 'contextual_suggestions' => [
        //     'enabled' => env('AI_CONTEXTUAL_SUGGESTIONS_ENABLED', false),
        //     'cooldown_minutes' => (int) env('AI_CONTEXTUAL_SUGGESTIONS_COOLDOWN', 5), // Min minutes between suggestions
        //     'cache_ttl' => (int) env('AI_CONTEXTUAL_SUGGESTIONS_CACHE_TTL', 3600), // Cache duration in seconds
        // ],
        'moderation' => [
            // 'enabled' => env('AI_MODERATION_ENABLED', env('AI_COMMENT_MODERATION_ENABLED', true)),
            // 'approval_mode' => env('AI_MODERATION_APPROVAL_MODE', env('AI_COMMENT_APPROVAL_MODE', 'threshold')),
            // 'ai_participates_in_approvals' => env('AI_MODERATION_AI_VOTES', env('AI_COMMENT_AI_VOTES', true)),
            // 'approve_confidence_threshold' => (float) env('AI_MODERATION_APPROVE_THRESHOLD', env('AI_COMMENT_MOD_APPROVE_THRESHOLD', 0.85)),
            // 'reject_confidence_threshold' => (float) env('AI_MODERATION_REJECT_THRESHOLD', env('AI_COMMENT_MOD_REJECT_THRESHOLD', 0.85)),
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

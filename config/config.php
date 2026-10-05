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
            // Optional per-module allowlist. Empty = every module (default).
            // When non-empty, only models whose owning module is listed are embedded,
            // e.g. ['cms']. Matched case-insensitively against the model's Modules\{Name}\ namespace.
            'modules' => [],

            // The model choice is the setting `features.embeddings.model` (overlaid as
            // `ai.features.embeddings.model`); the model that serves search is Core's managed
            // `search.vector.model`. EmbeddingModelRegistry falls back to the first configured profile below.
            // Profiles are keyed `provider:service_model`, split at the first colon. Each
            // declares the dimensions of its vectors; Core's `core.search.vector.*` follows.
            'models' => [
                'sentence_transformers:intfloat/multilingual-e5-small' => [
                    'dimensions' => 384,
                    'query_prefix' => 'query: ',
                    'passage_prefix' => 'passage: ',
                    'normalize' => true,
                ],
                'sentence_transformers:all-MiniLM-L6-v2' => [
                    'dimensions' => 384,
                    'query_prefix' => '',
                    'passage_prefix' => '',
                    'normalize' => true,
                ],
            ],
        ],
        // Media LLM analysis (M1-M21 in the media spec). The master switch is the
        // setting `features.media_analysis.enabled` and the models are the settings
        // `features.media_analysis.{vision,transcription}.model`; only the
        // per-module allowlist is config.
        'media_analysis' => [
            // Optional per-module allowlist. Empty = every module (default).
            // Matched by FeatureModuleGate against the model's Modules\{Name}\ namespace.
            'modules' => [],
        ],

        'translation' => [
            // Optional per-module allowlist. Empty = every module (default).
            // When non-empty, only models whose owning module is listed are translated,
            // e.g. ['cms']. Matched case-insensitively against the model's Modules\{Name}\ namespace.
            'modules' => [],
        ],
        // Optional one-shot text generation answering Core's
        // AiTextGenerationRequested event (e.g. SAO ownership-suggestion
        // phrasing). Opt-in: off unless explicitly enabled. Any failure or a
        // guard tripping leaves the request unfulfilled so the caller falls back.
        'text_generation' => [
            'enabled' => env('AI_TEXT_GENERATION_ENABLED', false),

            // Hard cap on the returned text; longer generations are truncated on
            // a word boundary rather than rejected.
            'max_output_chars' => env('AI_TEXT_GENERATION_MAX_CHARS', 500),

            // Optional short-TTL cache keyed by (purpose, prompt hash). 0 = off.
            'cache_ttl_seconds' => env('AI_TEXT_GENERATION_CACHE_TTL', 0),

            // Per-purpose rate limit; when exhausted the listener no-ops so a
            // burst cannot run up cost.
            'rate_limit' => [
                'max' => env('AI_TEXT_GENERATION_RATE_MAX', 60),
                'per_seconds' => env('AI_TEXT_GENERATION_RATE_WINDOW', 60),
            ],
        ],
        'faq' => [
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
                // The vector length is the active embedding profile's `dimensions`
                // (ai.features.embeddings.models); a model switch rebuilds these indexes.
            ],
            'policy_classification_version' => env('AI_FAQ_POLICY_CLASSIFICATION_VERSION', 'in-app-docs-v1'),
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
            // update, delete, bulk_update, bulk_delete, plus the approval verbs
            // pending_approvals, approve, disapprove (gated by the `approve`
            // permission). `summarize` (gated by `select`) groups records and
            // returns per-group counts plus optional sum/avg/min/max metrics.
            // `export` (gated by `select`) returns an ACL-scoped, filtered
            // recordset as an inline base64 CSV or PDF file. `bulk_update`/
            // `bulk_delete` change many filter-matched records at once; they are
            // gated by `select` plus the write ability (`update`/`forceDelete`),
            // always preview first (confirm=false returns the match count and a
            // sample without changing anything), and refuse to apply above a
            // hard cap of 200 records.
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
        ],
        'moderation' => [
            'queue' => env('AI_MODERATION_QUEUE', env('AI_COMMENT_MOD_QUEUE', 'default')),
        ],
    ],

    'providers' => [
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'api_url' => env('OPENAI_API_URL'),
            'model' => env('OPENAI_MODEL'),
        ],

        'ollama' => [
            'api_url' => env('OLLAMA_API_URL'),
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
        ],

        'sentence_transformers' => [
            'url' => env('SENTENCE_TRANSFORMERS_URL'),
            'api_key' => env('SENTENCE_TRANSFORMERS_API_KEY'),
            // Per-request HTTP timeout (seconds) and documents per /embed batch.
            // A CPU-bound embedding service is slow (~0.4s per long chunk), so keep
            // the batch small enough that one batch completes within the timeout.
            'timeout' => (int) env('SENTENCE_TRANSFORMERS_TIMEOUT', 30),
            'batch_size' => (int) env('SENTENCE_TRANSFORMERS_BATCH_SIZE', 32),
        ],

        // Self-hosted Whisper transcription service (Task 8). Unset URL = the
        // transcriber is a no-op (media index without a transcript). See
        // Modules/AI/docs/WHISPER_INSTALLATION.md to stand up the service.
        'whisper' => [
            'url' => env('WHISPER_URL'),
            'api_key' => env('WHISPER_API_KEY'),
            'timeout' => (int) env('WHISPER_TIMEOUT', 120),
        ],

        // Base URL of the service exposing POST /score (the client adds the path). It falls back to the
        // embedding service, which can serve /score too, and so does its key. No built-in address.
        'cross_encoder' => [
            'url' => env('CROSS_ENCODER_URL', env('SENTENCE_TRANSFORMERS_URL')),
            'api_key' => env('CROSS_ENCODER_API_KEY', env('SENTENCE_TRANSFORMERS_API_KEY')),
        ],

        'deepl' => [
            'api_key' => env('DEEPL_API_KEY'),
        ],
    ],
];

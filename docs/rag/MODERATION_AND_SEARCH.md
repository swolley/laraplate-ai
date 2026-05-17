# AI module — search pre-processing and modification moderation

## Purpose

The AI module listens to **Core events** for optional pre-processing before search indexing and before human approval workflows complete. It does **not** import CMS, ERP, or other domain modules.

Canonical bus documentation: `Modules/Core/docs/rag/EVENT_ORCHESTRATION.md`.

## Capabilities

| Pipeline | Core event | AI entrypoint | Outcome |
|----------|------------|---------------|---------|
| Embeddings | `ModelRequiresIndexing` | `HandleModelIndexingListener` | Vectors in DB, then `ModelPreProcessingCompleted('embeddings')` |
| Translation (with indexing) | `TranslatedModelSaved` / indexing cache | `HandleModelTranslationListener` | `TranslateModelJob` |
| Moderation | `ModificationRequiresModeration` | `HandleModificationModerationListener` | `ApproveModificationJob` → approval/disapproval rows |
| Post-approve translate | `ModificationApproved` | `HandleModificationApprovedTranslationListener` | `TranslateModelJob` |

## InternalFlow — moderation (AI only)

### Listener: `HandleModificationModerationListener`

Runs when all are true:

1. `config('ai.features.moderation.enabled')`
2. `config('ai.features.moderation.system_user_id')` set
3. `Modification` is active
4. `ModerationContextBuilderRegistry::supports($modification)`
5. Modifiable model: `aiModerationEnabledBySettings()` (`ai_moderation_{table}`)

Actions: `addRequiredPreProcessing('ai_approval')`, cache `modification_moderation:{id}`, dispatch `ApproveModificationJob`, `markAsHandled()`.

### Job: `ApproveModificationJob`

1. `ModerationContextBuilderRegistry::build($modification)` → `ModerationContext`
2. `ModerationService::analyze()` (LLM)
3. System user votes on `Modification` (approve/disapprove + `meta` audit JSON)
4. `ModificationPreProcessingCompleted('ai_approval')`

Humans can still approve/reject in Filament after the AI vote.

## InternalFlow — search (AI only)

### `HandleModelIndexingListener`

When embeddings enabled and model has `$embed`: register `embeddings` pre-processing, dispatch `GenerateEmbeddingsJob`, `markAsHandled()`.

### `HandleModelTranslationListener`

On `TranslatedModelSaved`: if `auto_translate_{table}` enabled, may attach `translation` to pending `model_indexing:*` cache or run standalone `TranslateModelJob`.

If AI does not handle indexing, Core `IndexModelFallbackListener` still runs `IndexInSearchJob`.

## Configuration

| Key | Env (legacy) | Meaning |
|-----|--------------|---------|
| `ai.features.moderation.enabled` | `AI_MODERATION_ENABLED` | Master switch |
| `ai.features.moderation.system_user_id` | `AI_MODERATOR_USER_ID` | Actor for votes |
| `ai.features.moderation.threshold_*` | `AI_COMMENT_*` | Score thresholds (comments) |
| `ai.features.embeddings.enabled` | — | Embedding pipeline |
| `ai.features.faq.enabled` | — | Documentation RAG assistant |

Per-model flags live in settings (`ai_moderation_{table}`, `auto_translate_{table}`), resolved by `PerModelSettingResolver`.

## HowToUse — documentation RAG

Index FAQ corpus (not Elasticsearch model index):

```bash
php artisan ai:index-docs
php artisan ai:index-docs --full
php artisan ai:laraplate-help --question="How does comment AI moderation work?"
```

Corpus roots: `docs/rag/`, active `Modules/*/docs/rag/` (see `docs/rag/README.md`). Technical docs under `Modules/*/docs/` without `rag/` are **not** scanned unless passed via `--path` or `AI_FAQ_DOCS_PATH`.

## ErrorsAndTroubleshooting

| Symptom | Check |
|---------|--------|
| Job never queued | Feature flags, queue worker, `system_user_id` |
| BindingResolutionException on moderation | Builder registered in domain `ServiceProvider` |
| AI votes but comment still hidden | Human `approvers_required` not satisfied |
| RAG answers wrong on moderation | Re-run `ai:index-docs` after updating `docs/rag` files |

## FAQPrompts

- What config enables AI comment moderation?
- What does ApproveModificationJob do?
- How does AI relate to ModificationRequiresModeration?
- Difference between ai:index-docs and Elasticsearch indexing?
- Which env vars control AI_MODERATION?

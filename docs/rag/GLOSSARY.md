# AI module glossary

Canonical English names for AI entities in this module. Use these terms in code, APIs, and cross-module documentation.

## Chat and conversations


| Term                     | Meaning                                                                                  |
| ------------------------ | ---------------------------------------------------------------------------------------- |
| **Conversation**         | Persistent chat session owned by a user; holds ordered `Message` rows.                   |
| **Message**              | Single user or assistant utterance within a `Conversation`.                            |
| **ConversationSummary**  | Condensed history used to keep context windows manageable.                               |
| **ChatService**          | Orchestrates message send, streaming, tool calls, and persistence.                       |
| **ChatController**       | HTTP layer for chat endpoints (`streamMessage`, `insertMessage`).                        |
| **streamMessage**        | Primary SSE streaming path for interactive UIs.                                          |
| **insertMessage**        | Non-streaming JSON response path (jobs, integrations, tests).                            |
| **ChatAgent**            | NeuronAI agent wrapper used by `ChatService` for LLM calls.                              |


## Tool system (ActionRequest)


| Term                       | Meaning                                                                    |
| -------------------------- | -------------------------------------------------------------------------- |
| **ActionRequest**          | Proposed AI tool invocation awaiting approval or execution.                |
| **ActionRequestService**   | Creates, confirms, rejects, and tracks `ActionRequest` lifecycle.          |
| **RiskClassifier**         | Maps `tool_name` to risk level (`low`, `medium`, `high`).                  |
| **ExecuteActionRequestJob**| Queue job that runs an approved tool handler.                                |
| **sendMessageWithTools**   | `ChatService` entry point that may create `ActionRequest` rows from LLM tool calls. |


### Risk levels


| Level      | Initial status                | User action      |
| ---------- | ----------------------------- | ---------------- |
| **low**    | `approved`                    | Auto-execute     |
| **medium** | `pending_user_confirmation`   | User confirms    |
| **high**   | `pending_admin_approval`      | Admin approves   |


## RAG and documentation intelligence


| Term                       | Meaning                                                                                       |
| -------------------------- | --------------------------------------------------------------------------------------------- |
| **DocumentationAgent**     | NeuronAI `RAG` subclass: chunking, embedding, retrieval, answer synthesis.                    |
| **DocumentationService**   | Application service for ingest, reindex, and query over module docs.                            |
| **EmbeddingService**       | Implements `IEmbeddingService`; produces vectors for models and doc chunks.                     |
| **rag_paths()**            | Helper returning documentation roots and source prefixes for indexing.                          |
| **FileVectorStore**        | Filesystem-backed vector store for RAG persistence.                                             |
| **MemoryVectorStore**      | In-memory vector store (tests / ephemeral).                                                   |
| **MarkdownAwareSplitter**  | Default `SplitterInterface`; keeps fenced blocks (e.g. Mermaid) intact.                       |
| **SplitterFactory**        | Resolves the active document splitter implementation.                                         |
| **reindexBySource**        | Incremental RAG update per logical source prefix.                                               |
| **indexDocuments**         | Artisan-driven full or incremental documentation indexing pipeline.                           |


## Core search orchestration (AI bindings)


| Term                                  | Meaning                                                                                  |
| ------------------------------------- | ---------------------------------------------------------------------------------------- |
| **HandleModelIndexingListener**       | Listens to `ModelRequiresIndexing`; dispatches `GenerateEmbeddingsJob` when enabled.     |
| **GenerateEmbeddingsJob**             | Embeds searchable model content; emits `ModelPreProcessingCompleted('embeddings')`.      |
| **HandleModelTranslationListener**    | Listens to `TranslatedModelSaved`; dispatches `TranslateModelJob`.                       |
| **TranslateModelJob**                 | Auto-translates translatable models when configured.                                     |
| **CrossEncoderService**               | Optional `IReranker` binding for search result reranking.                                |
| **SearchOrchestratorAgent**           | Optional `ISearchPlanner` binding for multi-step search plans.                           |
| **LlmQueryIntentParser**              | Optional `IQueryIntentParser` binding.                                                   |
| **SearchEmbedder**                    | Optional `ITextEmbedder` binding for query/document embeddings in Core search.             |


## Moderation (approval workflow)


| Term                                       | Meaning                                                                                  |
| ------------------------------------------ | ---------------------------------------------------------------------------------------- |
| **ModerationService**                      | LLM analysis of pending `Modification` records; returns structured verdict.              |
| **ApproveModificationJob**                 | Applies AI vote on a `Modification` as the configured system user.                       |
| **HandleModificationModerationListener**   | Entry listener on `ModificationRequiresModeration`.                                    |
| **ModerationContextBuilderRegistry**       | Core registry; AI resolves context without importing domain modules.                     |
| **ModerationResult**                       | Structured approve / reject / uncertain outcome from `ModerationService`.              |
| **ModerationApprovalMode**                 | Policy enum: threshold, dual, uncertain-fallback variants.                               |
| **ai_moderation_{table}**                  | Per-model setting toggling AI moderation via `HasApprovals`.                             |


## Contextual suggestions


| Term                       | Meaning                                                              |
| -------------------------- | -------------------------------------------------------------------- |
| **ContextualSuggestion**   | Stored suggestion tied to UI context for proactive assistant hints.  |
| **SuggestionController**   | HTTP layer for contextual suggestion endpoints.                      |


## LLM providers


| Term                          | Meaning                                                    |
| ----------------------------- | ---------------------------------------------------------- |
| **EmbeddingsProviderFactory** | Resolves embedding provider from `config('ai.*')`.         |
| **LLPhant**                   | Underlying library for OpenAI, Ollama, Mistral, Anthropic chat adapters. |


## Related reading

- `docs/ARCHITECTURE.md` — module layout and message flows
- `docs/DESIGN_DECISIONS.md` — streaming vs non-streaming, tool system rationale
- `docs/SEARCH_AND_TRANSLATION.md` — indexing and translation listeners
- `docs/MODERATION.md` — moderation pipeline and approval modes
- `Modules/Core/docs/EVENT_ORCHESTRATION.md` — Core event bus contracts

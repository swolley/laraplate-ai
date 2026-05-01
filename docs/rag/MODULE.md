# AI module — RAG, tools, and assistant orchestration

## Purpose

`AI` provides documentation intelligence for Laraplate: ingest docs, index them for semantic retrieval, answer user questions with RAG, and orchestrate tool-assisted conversations with approval controls.

## Core capabilities

### RAG ingestion and indexing lifecycle

- Reads documentation files from roots resolved by `rag_paths()` (or explicit CLI `--path`).
- Splits documents into chunks and stores vectors in configured vector store backend.
- Supports incremental reindex-by-source and full rebuild (`--full`) modes.
- Keeps source prefixes to improve citation traceability by module/path.

### Question answering

- Uses retrieval + LLM response generation through `DocumentationService::answerQuestion`.
- Returns normalized output with answer + structured citations.
- Can append formatted citation section in final answer.

### Chat orchestration

- `ChatService` handles normal conversation, RAG-triggered responses, and stream mode.
- Question-detection rules can auto-route suitable prompts through FAQ/RAG path.
- Memory and guardrails services participate when enabled by configuration.

### Tools ecosystem

- Tool registry exposes executable tools to the AI agent.
- Tools are wrapped with risk-aware policy logic.
- Agent can choose tool execution or direct response depending on prompt and available capabilities.

### Approvals flow for tools

- Medium/high-risk tool calls can be converted to `ActionRequest` items instead of immediate execution.
- Pending requests are returned to caller as structured metadata.
- Approval outcome controls whether tool execution proceeds or remains blocked.

## Developer-facing CLI

### Documentation indexing

- `php artisan ai:index-docs`
- `php artisan ai:index-docs --path=/some/path`
- `php artisan ai:index-docs --full`

### Terminal help assistant

- `php artisan ai:laraplate-help` opens interactive REPL chat over RAG.
- `php artisan ai:laraplate-help --question="..."` executes one-shot query.

## Configuration surfaces

Important groups include:

- `ai.features.faq.*` for RAG enablement, max docs, vector store behavior.
- `ai.features.tools.*` for tools and approval pipeline.
- `ai.features.guardrails.*` for prompt-injection and input hardening behavior.
- `ai.features.search_orchestration.*` for AI-driven search planner/reranking bindings.
- `AI_FAQ_DOCS_PATH` / `ai.features.faq.documentation_path` for extra documentation roots.

## Operational guidance

### When to reindex

- Reindex after major docs updates, module feature changes, or terminology redesign.
- Use `--full` when source mapping changed significantly or stale vectors are suspected.

### Common failure modes

- RAG unavailable: index missing or vector store path not initialized.
- Empty/weak answers: low-quality docs, missing sections, wrong root coverage.
- Tool calls pending forever: approval workflow not completed in caller layer.
- Slow responses: high top-k settings, large context, or provider latency.

## Security boundaries

- Do not expose sensitive credentials or internals in indexed docs.
- Treat tool execution as privileged: keep approval and risk policies enabled in production.
- Keep guardrails enabled where user input can be untrusted.

## FAQ prompts for RAG

- How does `ai:index-docs --full` differ from incremental indexing?
- How does the system decide between direct answer and tool invocation?
- What happens when a tool call requires approval?
- How do I add extra docs roots with `AI_FAQ_DOCS_PATH` safely?
- Why is the assistant saying RAG is unavailable?
- How do I use `ai:laraplate-help` in REPL versus one-shot mode?


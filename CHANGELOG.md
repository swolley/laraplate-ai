# Changelog

All notable changes to this project will be documented in this file.

## [2.20.0] - 2026-09-29

### 🚀 Features

- *(ai)* Make Sentence Transformers timeout and batch size configurable
- *(models)* Add IDE helper mixins to ActionRequest, ContextualSuggestion, Conversation, ConversationSummary, and Message models
- *(ai)* Evaluate the developer documentation index
- *(ai)* Send the active embedding model per request (multi-model service)
- *(ai)* Add a min-similarity floor so documentation retrieval can abstain
- *(ai)* Adaptive batching for the Sentence Transformers embedding calls
- *(ai)* Batch model embedding calls across models in one request
- *(ai)* Batch-embed the bulk indexing chunk in one pass
- *(ai)* Single-locale re-embeds join a deferred indexing run
- *(ai)* CRUD tools report writes sent for approval
- *(ai)* Per-feature model choice with defaults in code
- *(ai)* List the models of OpenAI, Anthropic, Mistral and Ollama
- *(ai)* Build per-feature model choices from the configured providers
- *(ai)* Seed model settings and refresh their choices from the providers
- *(ai)* Every chat-family feature uses its own model setting
- *(ai)* Media analysis reads its settings; seeded master switch; whisper_local becomes whisper
- *(ai)* Translation picks DeepL or an AI model, with no fallback; failures retry
- *(ai)* Embed an identical text once and reuse its vectors per model (M15)
- *(ai)* Reindex the media owner once its analysis completes (media M9, M14)
- *(docs)* Update README with PHP version badge and adjust logo size
- *(ai)* Refcount the shared media analysis by content hash (M19)
- *(filament)* AI media analysis panel + re-analyze action (media analysis Task 13d, M22)

### 🐛 Bug Fixes

- *(docs)* Restore the test seam Pint's protected_to_private removed
- *(ai)* Apply the e5 query prefix in documentation retrieval
- *(rag)* A validator that could not reject, and two mixed values cast blind
- *(ai)* Do not announce completion on the bulk embedding path
- *(ai)* Implement embedDocumentsBatch on the documentation test stub
- *(ai)* Mock embedDocumentsBatch in RepairEmbeddingsTest
- *(ai)* An unset Ollama URL means Ollama is not configured
- *(ai)* Review fixes for model selection and translation

### 💼 Other

- Add media-analysis config, model registry and master-switch gate

Task 1 of the media AI analysis plan (M18, M21): a features.media_analysis config
block mirroring embeddings (runtime master switch default + per-capability model
registry), MediaAnalysisModelProfile/MediaAnalysisModelRegistry resolving the
active vision/transcription profile, and MediaAnalysisGate reading the runtime
Settings row media_analysis.enabled with config fallback plus the per-module
FeatureModuleGate. Unit + feature tests (7).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Add AI-owned media analysis table keyed by content hash

Task 3 of the media AI analysis plan (M3b, M15): the ai_media_analyses table and
MediaAnalysis model, keyed uniquely by content_hash so duplicated media share one
analysis row (entities/idea/intent/ocr/transcript, analysis bag, provenance,
status, model version). Soft deletes are forced off on this model (not the
dynamic per-model setting) because a soft-deleted row would keep the unique index
occupied; refcount cleanup purges outright. Factory with pending/failed states.
Feature tests (5).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Wire media analysis into the indexing pipeline (M6, M11, M12, M14)

Task 6. HandleMediaAnalysisListener intercepts ModelRequiresIndexing for a
claimed Media when the master switch allows: registers the media_analysis pre
-processing step, dispatches AnalyzeMediaJob, and marks the event handled so
Core's fallback stands down and finalize indexes once analysis completes; off/
draft/non-media are no-ops so the deterministic fallback indexes (M12).
HandleModelIndexingListener now skips Media so the generic embeddings path does
not race the media pipeline. AnalyzeMediaJob is a skeleton emitting the pre
-processing completion (Task 7 fills the analysis + embeddings chaining). Tests.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Implement AnalyzeMediaJob and media search contribution (M6, M11, M15, M20, M21)

Task 7. AnalyzeMediaJob analyzes a claimed media behind the MediaVisionAnalyzer /
MediaTranscriber contracts (neuron-ai vision default, null transcriber until
Task 8), keyed by content_hash with lookup-before-work reuse; per-mime handling
for image/audio/video/pdf; writes the AI-owned MediaAnalysis row with provenance,
fills empty Core custom_properties (M3c), chains GenerateEmbeddingsJob after the
analysis persists (M11), and emits/degrades the pre-processing completion (M12).
MediaAnalysisSearchContributor surfaces the analysis (idea/intent/entities +
transcript/OCR) into the media document and vector via Core's contributor seam,
looked up by content_hash. Contracts, DTO, registry bindings, and fakes; feature
tests.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Add self-hosted Whisper transcription backend (M21, Task 8)

WhisperTranscriber posts the media file to a self-hosted Whisper service
(ai.providers.whisper.url/api_key/timeout), bound over the null default; a no-op
when unconfigured or on error so media still index without a transcript (M12).
The standalone service (faster-whisper + FastAPI + Docker) lives at the stack
root whisper-service/. Http::fake feature tests.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Document the self-hosted Whisper service instead of vendoring it

Per operator preference (mirrors sentence-transformers): no service code in the
repo. Modules/AI/docs/WHISPER_INSTALLATION.md gives the HTTP contract, a
reference faster-whisper + FastAPI implementation, and Docker/systemd install
steps for a Proxmox guest. Config comment points to the doc.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
- Point Whisper docs at the canonical repo and port 8001

The service is deployed on the ai host on :8001 (kept in the 800x range next to
embeddings on :8000) from github.com/swolley/whisper-api (Flask + faster-whisper,
systemd, shared /opt/ai-env). Doc now references that repo and the integration +
sizing note rather than a divergent inline implementation.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>

### 🚜 Refactor

- *(ai)* Remove the superseded chat message path
- *(docs)* Inject the rag_paths resolver instead of exposing a seam
- Narrow findOrFail to a single record with whereKey()->firstOrFail()
- *(ai)* Extract ModelEmbeddingSynchronizer from GenerateEmbeddingsJob
- *(ai)* Own the per-entity moderation switches and follow the core.* setting keys
- *(ai)* Moderation switches named without the module prefix
- *(ai)* Domain-first moderation setting names

### 📚 Documentation

- *(rag)* Describe how the module is released from the application
- *(ai)* Add self-hosted Sentence Transformers installation guide
- *(ai)* Document the module perimeters and correct the superseded references
- Name the embedding providers this module actually uses
- *(ai)* Developer RAG retrieval eval dataset + vector-only report
- *(ai)* Link external sentence-transformers-api repo instead of inlining service code
- Approvals cover deletes and restores
- *(ai)* Per-feature model selection and translation without fallback
- *(ai)* Media analysis user and developer guides
- Media analysis Filament panel + re-analyze action (media analysis Task 14)

### ⚡ Performance

- *(migrations)* Index all foreign-key and row-scoping columns
- *(ai)* Skip re-embedding unchanged locales in GenerateEmbeddingsJob
- *(ai)* Reuse eager-loaded embeddings on the bulk path

### 🧪 Testing

- *(stubs)* Declare the search contract on the stubs that use the Core trait
- *(ai)* Commit the Core developer documentation retrieval baseline
- *(ai)* Make the stub embedder prefix-agnostic after the query-prefix fix
- *(ai)* Modification fixtures carry an operation

### ⚙️ Miscellaneous Tasks

- Rimuove docblock ide-helper generati dai model
- *(ai)* Drop commented config entries and env docs moved to runtime settings

## [2.19.0] - 2026-09-15

### 🚀 Features

- *(eval)* Add precision@k, recall@k, nDCG@k to application-content evaluation
- *(module)* Add color attribute to module.json for improved UI representation
- *(eval)* Add per-strategy retrieval ranking evaluation service
- *(ai)* Add per-strategy retrieval evaluation command
- *(embeddings)* Add embedding-model registry and active profile
- *(embeddings)* Apply query/passage prefixes from the active model profile
- *(jobs)* Generate embeddings per locale, stamped with locale + model_key
- *(events)* Listen for TranslationRequiresReembedding
- *(embeddings)* Repair per-locale with model_key stamp and /health cross-check

### 🐛 Bug Fixes

- *(embeddings)* Survive rate-limit releases with retryUntil + maxExceptions
- *(ai)* Per-strategy denominator in retrieval strategy eval metrics
- *(ai)* Namespace engine ids before comparing per-strategy hits
- *(listeners)* Gate TranslationRequiresReembedding dispatch behind embeddings feature flags
- *(console)* Point ai:help at the command that exists

### 🚜 Refactor

- *(ai)* Extract IR @k metric math into shared IrMetrics helper

### 📚 Documentation

- *(eval)* Document application-content @k ranking metrics and baselines
- *(ai)* Document per-strategy retrieval quality breakdown (R3 Phase 2)
- *(changelog)* Regenerate with the corrected git-cliff configuration
- Releases are run from the application

### 🎨 Styling

- Format with the application's Pint configuration
- Apply the module's own mb_str_functions rule

### 🧪 Testing

- *(eval)* Assert @k metrics in evaluate-application-content command
- *(eval)* Cover ground-truth larger than k in @k metrics

### ⚙️ Miscellaneous Tasks

- The module carries functionality, not the toolchain

## [2.18.0] - 2026-09-09

### 🚀 Features

- *(ai)* Assistant evaluation case value object
- *(ai)* Assistant evaluation dataset loader
- *(ai)* Assistant evaluation scoring service
- *(embeddings)* Add ai:embeddings:repair to backfill missing embeddings

### 🐛 Bug Fixes

- *(ai)* Degrade to a soft deadline when POSIX signals are unsafe
- *(ai)* Call parent constructor in ChatAgent so NeuronAI workflow executor is initialised
- *(ai)* Degrade search intent/plan to the raw query when the LLM fails instead of breaking retrieval
- *(ai)* Use model isEmbeddable() in the indexing listener so embeddings are actually generated (was blocked by protected $embed / private check)
- *(embeddings)* Degrade to keyword-only on permanent embed failure + idempotent regeneration

### 🚜 Refactor

- *(ai)* Centralize module-key regex + document dataAccess seam

### 📚 Documentation

- *(ai)* Assistant evaluation guide
- *(ai)* Mark ai:evaluate-assistant command as deferred (Level 2)
- *(ai)* Correct assistant-evaluation guide (module_key, slug pattern, gate name, thresholds, metrics)
- *(embeddings)* Document keyword-only degrade, late-retry recovery, ai:embeddings:repair

### 🧪 Testing

- *(ai)* Scripted assistant runner fixture over real respond()
- *(ai)* CMS assistant baseline regression gate

## [2.17.0] - 2026-08-25

### 🚀 Features

- *(ai)* Per-module allowlist for embeddings and translation
- *(ai)* Default CRUD tools for the in-app assistant
- *(ai)* Structured filters/sort and request echo for CRUD tools
- *(ai)* Configure-mode `view` CRUD tool (propose filters without fetching)
- *(ai)* Approval verbs (pending_approvals/approve/disapprove) for CRUD tools
- *(ai)* Summarize CRUD tool (group-by count + sum/avg/min/max)
- *(ai)* Export CRUD tool (CSV/PDF) via Core tabular exporters
- *(ai)* Bulk_update/bulk_delete CRUD tools with mandatory preview and cap
- *(ai)* Optional listener answering Core's AI text-generation request
- *(ai)* Production-ready live text generation behind its flag
- *(ai)* Bind text generation to a configurable model

### 🐛 Bug Fixes

- *(ai)* No-module in-app assistance stays generic (data still available)
- *(ai)* Gate CRUD tools by permission, drop approval-for-unpermitted

### 📚 Documentation

- *(ai)* Assistant scope model + cross_cutting_user marker convention
- *(ai)* Record verified HasApprovals moderation behavior for CRUD tools
- *(ai)* Add user RAG guide for assistant CRUD data tools

## [2.16.0] - 2026-08-07

### 🚀 Features

- *(ai)* Integrate AssistantScopeResolver for enhanced documentation retrieval and add tests for module-scoped assistance

### 🧪 Testing

- *(ai)* Fixture honors module-or-cross-cutting doc scope

## [2.15.0] - 2026-08-06

### 🚀 Features

- *(ai)* Documentation evaluation scoring service
- *(ai)* Ai:evaluate-documentation command
- *(ai)* Assistant scope value object (module/dataAccess/docScope)
- *(ai)* Resolve assistant scope from profile + module context
- *(ai)* Module-scope documentation retrieval via AssistantScope

### 🐛 Bug Fixes

- *(ai)* Unique eval test helper name + assert zero unavailable in gate

### 📚 Documentation

- *(ai)* Document ai:evaluate-documentation baseline
- *(ai)* User + developer guides for documentation evaluation baseline

### 🧪 Testing

- *(ai)* Deterministic documentation retrieval fixtures
- *(ai)* Exercise module-mismatch guard in evaluate-documentation
- *(ai)* Add baseline evaluation tests for Core user documentation

## [2.14.0] - 2026-08-05

### 🚀 Features

- *(ai)* Documentation evaluation case value object
- *(ai)* Documentation evaluation dataset loader

### 🐛 Bug Fixes

- *(tests)* Use distinct author for approve-modification fixtures
- *(ai)* Correct slice length cap and refusal test in documentation case

## [2.13.1] - 2026-08-03

### 🐛 Bug Fixes

- *(testing/ai)* Extract inline test providers into PSR-4 stubs
- *(ai)* Exclude guest from in-app assistance

### 🚜 Refactor

- *(ai)* Seed definitions via SeedReconciler, stamped AI-owned

### 🧪 Testing

- *(ai)* Add route override tests for module-specific handling

### ⚙️ Miscellaneous Tasks

- *(ai)* Mark module as laraplate_owned

## [2.13.0] - 2026-07-21

### 🚀 Features

- *(ai)* Add server-owned assistant profiles
- *(ai)* Isolate developer and user RAG corpora
- *(ai)* Enforce scoped in-app documentation retrieval
- *(ai)* Enforce fail-closed in-app guardrails
- *(ai)* Add contextual read-only graph tools
- *(ai)* Integrate protected in-app assistance
- *(ai)* Add authenticated application content tool
- *(ai)* Ground in-app answers in module evidence
- *(ai)* Evaluate application content retrieval

### 🐛 Bug Fixes

- *(ai)* Clarify ambiguous content requests

### 🚜 Refactor

- *(ai)* Integrate HasModuleTablesUtils into AITables enum

### 📚 Documentation

- *(ai)* Lock evidence-gated RAG retrieval strategy
- *(ai)* Document module evidence retrieval

### 🧪 Testing

- *(ai)* Align developer help profile mocks
- *(ai)* Gate application content retrieval security

## [2.12.8] - 2026-07-13

### 🚜 Refactor

- *(ai)* Update command signatures and descriptions for clarity
- *(ai)* Optimize translation commands for efficiency

## [2.12.7] - 2026-07-09

### 🚜 Refactor

- *(ai)* Improve helper roots logic and add tests for indexing behavior

### 🧪 Testing

- *(ai)* Enhance moderation listener tests and add caching logic
- *(ai)* Add ApproveModificationJob and LaraplateHelp coverage

## [2.12.6] - 2026-07-09

### 🐛 Bug Fixes

- *(ai)* Align ActionRequest tool_args validation and extend coverage tests

### 📚 Documentation

- *(ai)* Clarify RAG audiences and FAQ configuration in README

## [2.12.5] - 2026-07-07

### 🚜 Refactor

- *(ai)* Enhance translation job method checks

## [2.12.4] - 2026-07-01

### 🧪 Testing

- *(ai)* Streamline translation job and documentation splitter logic

## [2.12.3] - 2026-06-30

### 🐛 Bug Fixes

- *(config)* Update default vector store to elasticsearch and adjust index naming convention
- *(ai)* Harden suggestion flow and translation job error handling

### 📚 Documentation

- *(ai)* Update RAG module documentation

## [2.12.2] - 2026-06-27

### 🧪 Testing

- *(ai)* Add elasticsearch response test helper bootstrap
- *(ai)* Simplify elasticsearch RAG test setup

## [2.12.1] - 2026-06-27

### 🚀 Features

- *(ai)* Add RAG elasticsearch index command and coverage

## [2.12.0] - 2026-06-27

### 🚀 Features

- *(ai)* Expand AI services and introduce domain exceptions

## [2.11.1] - 2026-06-23

### 🚜 Refactor

- *(ai)* Update Core model concern imports

## [2.11.0] - 2026-06-11

### 🚀 Features

- *(docs)* Added swagger documentation definition inside the module

### 📚 Documentation

- *(docs)* Add glossaries for AI module and RAG documentation

### 🧪 Testing

- *(integration)* Enable vector search in fallbackPlan test

## [2.10.0] - 2026-05-28

### 🚀 Features

- *(config)* Update AIServiceProvider and configuration settings

### 🚜 Refactor

- *(tests)* Add integration tests and enhance testing structure for AI module

## [2.9.0] - 2026-05-17

### 🚀 Features

- Implement AI moderation and translation workflows

## [2.8.1] - 2026-05-15

### 🚜 Refactor

- Improve string handling and condition checks in helper and splitter classes

## [2.7.0] - 2026-05-15

### 🚀 Features

- Enhance AI module with new enums, migrations, and updates to models and routes

## [2.6.0] - 2026-05-09

### 🚀 Features

- Enhance HandleModelIndexingListener to handle sync events differently based on context

### 📚 Documentation

- Enhance MODULE.md with detailed module boundaries and RAG workflows

## [2.5.0] - 2026-05-08

### 🚀 Features

- Introduce Markdown-aware splitter and update documentation services

### 🐛 Bug Fixes

- Update module priority for improved configuration

### 🚜 Refactor

- Remove unused email verification configuration method

### ⚙️ Miscellaneous Tasks

- Remove deprecated AI module rules and add module context file

## [2.4.0] - 2026-05-01

### 🚀 Features

- Implement translatable model class names interface and update translation commands

## [2.3.0] - 2026-05-01

### 🚀 Features

- Add Laraplate help command for interactive RAG assistance

## [2.2.0] - 2026-05-01

### 🚀 Features

- Introduce chat and embedding service contracts with implementations

## [2.1.1] - 2026-04-23

### 🚜 Refactor

- Enhance models and migrations for improved structure and functionality

## [2.1.0] - 2026-04-16

### 🚀 Features

- Implement AI-powered search orchestration and related services

### 💼 Other

- Update README.md

### 🚜 Refactor

- Enhance ActionRequest model with validation rules

## [2.0.1] - 2026-04-01

### 🐛 Bug Fixes

- Align summary snapshot signatures across service and tests

### 🚜 Refactor

- Reorganize test structure and introduce stubs for improved testing
- Update arrow function syntax for consistency and clarity

## [2.0.0] - 2026-03-16

### 🚀 Features

- Introduce comprehensive rules for AI module development

### 💼 Other

- Set composer package type as laravel-module

### 🚜 Refactor

- Enhance command structure and improve response handling
- Update Pest test case references to use core module
- Replace LLPhant with Neuron AI v3 and achieve 100% coverage
- Streamline model factory usage in AI module

### 📚 Documentation

- Add comprehensive usage example for sendMessageWithTools

### ⚙️ Miscellaneous Tasks

- Update license to AGPL-3.0 and add compliance checker
- Add .gitignore file to exclude unnecessary files and directories
- Add Filament package to composer.json

## [1.3.0] - 2026-01-30

### 🚀 Features

- Introduce Conversation and Message models with migrations for AI module. Implement relationships and methods for managing conversations and messages, enhancing the chat functionality.
- *(chat)* Add chat system with streaming and conversation management
- *(tools)* Add tool system with risk-based ActionRequest workflow
- *(suggestions)* Add contextual AI suggestions service
- *(memory)* Add conversation memory and summarization
- *(rag)* Add RAG/FAQ documentation service
- *(guardrails)* Add input validation and prompt injection detection
- Enhance AI Module with new features and architecture documentation

### 🚜 Refactor

- Remove AIController and enhance EmbeddingService

### 🧪 Testing

- Add feature tests for chat endpoints

### ⚙️ Miscellaneous Tasks

- Bump version to v1.2.0 in composer.json to reflect the latest updates and improvements in the AI module.
- *(db)* Add database migrations for AI features
- Update routes and configuration for AI features

## [1.2.0] - 2026-01-22

### 📚 Documentation

- Update README.md to enhance caution notice with emoji for better visibility. Clarified the work in progress status of the package.

## [1.1.0] - 2026-01-21

### 💼 Other

- Add README.md for AI Module: Include comprehensive documentation covering installation, configuration, features, and architecture. Highlight AI capabilities such as embeddings generation and automatic translation, along with usage instructions and contribution guidelines.

### 🚜 Refactor

- Remove ModelEmbedding and related migration: Deleted the ModelEmbedding model, its migration file, and the MoveEmbeddingTable command to streamline the AI module. This change simplifies the embedding management process.

## [1.0.1] - 2026-01-15

### 💼 Other

- Enhance version update process in version.sh: clarify comments regarding staging files, ensure single commit for version and changelog updates, and improve error handling for git push operations.

## [1.0.0] - 2026-01-15

### 💼 Other

- Repo initialization
- Refactor AI module: Update composer.json, add embedding and translation services, implement commands for model translation and embedding generation, and create necessary database migrations. Enhance configuration for AI features and integrate event listeners for model processing.
- Update version handling in composer.json and improve version script logic. Change version field to execute a script for dynamic versioning and ensure proper file handling in version.sh for composer.json updates.

<!-- generated by git-cliff -->

# Media analysis: developer and operator guide

## What it does

When a media is claimed onto a real owner and indexed, the AI module analyses the file and adds what it
finds to search:

- images: caption, entities, idea, intent, OCR (vision model);
- audio and video: transcript (self-hosted Whisper, see `../WHISPER_INSTALLATION.md`);
- PDF: extracted text.

Core indexes media on its deterministic layer with or without AI; the analysis only enriches it.

## Switch and models

- Master switch: the setting `features.media_analysis.enabled` (group `ai`, off by default), read by
  `MediaAnalysisGate::enabled()`. No env variable. The per-module allowlist
  `ai.features.media_analysis.modules` stays in config.
- Models: the settings `features.media_analysis.vision.model` (`provider:model`, default
  `anthropic:claude-sonnet-5`) and `features.media_analysis.transcription.model` (`whisper`), resolved by
  `MediaAnalysisModelRegistry`. See `AI_MODEL_SELECTION_DEVELOPER.md`.

## Pipeline

1. `HandleMediaAnalysisListener` (on `ModelRequiresIndexing` for a claimed `Media`, when the gate allows
   it) registers the `media_analysis` pre-processing and dispatches `AnalyzeMediaJob` on the
   `media_analysis` queue.
2. `AnalyzeMediaJob` looks up an `ai_media_analyses` row by the file's `content_hash`: a completed row at the
   current model version is reused without calling any model (a duplicated file, or a metadata-only edit).
   Otherwise it analyses the file and writes the row, with a provenance map of what AI generated.
3. It fills empty Core display fields (`description`, `keywords`) from the analysis, never over a human
   edit, dispatches `GenerateEmbeddingsJob`, reindexes the media's owner, and signals completion.
4. `MediaAnalysisSearchContributor` adds `idea`, `intent` and `entities` to the media document, and those
   plus transcript and OCR text to the media vector. The owner's document gets only the compact part
   (`media_surrogate`, built by Core).

The analysis model version stored on a row is the full choice (`anthropic:claude-sonnet-5`); changing the
vision model makes existing analyses stale, and they are redone on the next analysis of each file.

## Sharing and cleanup

- One analysis per file (`content_hash`), shared by every media row of that file.
- Embedding vectors of an identical text are reused by `ModelEmbeddingSynchronizer` (same `content_hash`
  and embedding model), while each media still gets its own `ModelEmbedding` rows.
- `MediaAnalysisRefcountObserver` deletes an analysis only when the last media with its `content_hash` is
  force-deleted; a soft-deleted media still counts.

## Failure

`AnalyzeMediaJob::failed()` signals completion anyway, so the media is still indexed on its deterministic
layer. A Whisper service that is unset or down yields no transcript; the rest of the analysis runs.

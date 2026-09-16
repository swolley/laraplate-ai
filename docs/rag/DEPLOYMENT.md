# RAG vector store — deployment guide

Documentation RAG (`ai:index-rag-docs`) persists chunk embeddings in a **vector store** selected by `AI_FAQ_VECTOR_STORE` (`ai.features.faq.vector_store`).

## Drivers

| Driver | Storage | Multi-instance |
|--------|---------|----------------|
| `filesystem` (default) | Local file (`AI_FAQ_VECTOR_STORE_PATH` or `storage/app/ai/faq-vectorstore.store`) | **Only** if every replica mounts the **same read-write path** (shared PVC/NFS). |
| `memory` | In-process RAM | **Not for production.** Test/CLI only; data is lost on process restart. |
| `elasticsearch` | Shared Elasticsearch index (`AI_FAQ_ES_INDEX`) | **Recommended** when Elasticsearch is already in the stack. All app instances read the same index. |

## Filesystem on Kubernetes (interim)

Run indexing from **one** job or pod, or ensure all pods share the store file:

```yaml
env:
  - name: AI_FAQ_VECTOR_STORE
    value: filesystem
  - name: AI_FAQ_VECTOR_STORE_PATH
    value: /shared/ai/faq-vectorstore.store
volumeMounts:
  - name: rag-vector-store
    mountPath: /shared/ai
volumes:
  - name: rag-vector-store
    persistentVolumeClaim:
      claimName: laraplate-rag-pvc
```

Without a shared volume, each replica has its own index and FAQ answers differ per pod.

## Elasticsearch (recommended for production)

1. Set embedding dimensions to match your embeddings provider (`AI_FAQ_ES_EMBEDDING_DIMS`, e.g. `384` for many Sentence Transformers models).
2. Create the index:

```bash
php artisan ai:create-rag-es-index
```

3. Configure:

```env
AI_FAQ_VECTOR_STORE=elasticsearch
AI_FAQ_ES_INDEX=laraplate_rag_docs
AI_FAQ_ES_EMBEDDING_DIMS=384
```

4. Index documentation (from any single instance or CI job):

```bash
php artisan ai:index-rag-docs
```

All replicas then share the same corpus via Elasticsearch.

### Embedding dimension changes

If you change the embeddings model and vector size, create a **new** index (or drop and recreate) with updated `AI_FAQ_ES_EMBEDDING_DIMS`, then run `ai:index-rag-docs --full`.

### Embedding service timeout and batch size

`ai:index-rag-docs` calls the Sentence Transformers service in batches. A CPU-bound service is slow (~0.4s per long chunk), so a large batch can exceed the HTTP timeout and abort indexing (`cURL error 28 ... /embed`). Both are configurable:

```env
SENTENCE_TRANSFORMERS_TIMEOUT=120   # seconds per /embed request (default 30)
SENTENCE_TRANSFORMERS_BATCH_SIZE=16 # documents per batch (default 32)
```

Keep `batch_size` small enough that one batch completes within `timeout`; on a GPU service the defaults are fine.

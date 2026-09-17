# Self-hosted Sentence Transformers for embeddings

Laraplate does not run Sentence Transformers inside PHP. The AI module calls a **self-hosted HTTP API** on a separate host (VM, bare metal, or container). This guide covers that sidecar service and the Laraplate configuration that points to it.

---

## What uses the embeddings provider

Embeddings are selected **per feature**, not by a global `AI_PROVIDER` variable (that env name is not read by the application).

| Config key (`ai.*`) | Purpose | Default provider id |
|---------------------|---------|---------------------|
| `features.embeddings.default_provider` | Search indexing (`GenerateEmbeddingsJob`), vector search, RAG chunk vectors | `sentence_transformers` |
| `features.chat.default_provider` | Interactive chat LLM | `ollama` |
| `features.translation.default_provider` | Automatic model translation | `deepl` |

For Sentence Transformers you only need the **embeddings** provider and its URL.

---

## Architecture

```text
[Laravel + Horizon]  --POST /embed-->  [Python host: FastAPI + sentence-transformers]
     .env: SENTENCE_TRANSFORMERS_URL=http://HOST:PORT
```

The Laravel application needs outbound HTTP access to the embedding host. Restrict inbound access on the embedding host to trusted clients (VPN, private network, or firewall rules).

---

## HTTP contract (required)

Laraplate expects a REST endpoint at `{base_url}/embed` (no trailing slash on the base URL).

### Single text

```http
POST /embed
Content-Type: application/json

{
  "text": "hello world",
  "truncation": true,
  "normalize_embeddings": true,
  "max_length": 512
}
```

### Batch (up to 128 texts per request)

```json
{
  "texts": ["first", "second"],
  "truncation": true,
  "normalize_embeddings": true,
  "max_length": 512,
  "model": "intfloat/multilingual-e5-small"
}
```

### Response

```json
{
  "model": "intfloat/multilingual-e5-small",
  "embeddings": [[0.1, 0.2, "..."], [0.3, 0.4, "..."]]
}
```

### Model selection (multi-model, request-driven)

The `model` field is **optional**. Laraplate sends the active embedding profile's `service_model` (`ai.features.embeddings.models.<active>.service_model`) on every request, so the **Laravel config is the single source of truth** and the service never drifts from what the application expects. When `model` is omitted, the service uses its own `EMBEDDING_MODEL` default.

The service loads models **lazily** and keeps up to `EMBEDDING_MODEL_CACHE` of them resident (LRU), so switching the active model — or embedding with two models during a migration window — needs no service restart. `/health` reports the default and the currently loaded models. All models served concurrently must share the configured vector dimension (384); a model with a different output size needs its own index (see *Choose a model*).

Optional authentication: if you configure an API key on the Python service, Laraplate sends `Authorization: Bearer {key}` (env `SENTENCE_TRANSFORMERS_API_KEY`).

Implementation reference:

- `Modules/AI/app/Ai/Embeddings/SentenceTransformersEmbeddingsProvider.php`
- `Modules/AI/app/Ai/Embeddings/EmbeddingsProviderFactory.php`

---

## Choose a port

Before starting the service, list listening ports on the **embedding host**:

```bash
ss -tlnp
# or, for one port:
ss -tlnp | grep ':8003'
sudo lsof -iTCP:8003 -sTCP:LISTEN
```

| Port | Typical use |
|------|-------------|
| 8000 | Documented default for `SENTENCE_TRANSFORMERS_URL`; often also used by `php artisan serve` |
| 8001 | Cross-encoder reranker (`CROSS_ENCODER_ENDPOINT`), separate optional service |
| 8003+ | Example when 8000–8002 are taken; pick any free port |

Use the same port in the Python process and in `SENTENCE_TRANSFORMERS_URL`.

---

## Choose a model (384 dimensions)

Laraplate defaults assume **384-dimensional** vectors:

- Core setting `search.vector_search.dimension` (default `384`)
- `AI_FAQ_ES_EMBEDDING_DIMS` (default `384`)
- Elasticsearch `dense_vector` mappings for search and RAG

Recommended models (384-d output):

| Hugging Face model | Dims | Notes |
|--------------------|------|-------|
| `intfloat/multilingual-e5-small` | 384 | **Default** (`ai.features.embeddings.active`). Multilingual; requires the `query:` / `passage:` prefixes, which Laraplate adds from the model profile. |
| `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` | 384 | Alternative multilingual model; no prefixes. |
| `sentence-transformers/all-MiniLM-L6-v2` | 384 | Lighter; English-centric; no prefixes. |

The service is **multi-model**: `EMBEDDING_MODEL` is only the default served when a request omits `model`. Keep the default equal to the active profile's `service_model` so `/health` matches what `ai:embeddings:repair` expects. If you switch to a model with a different output size, realign Laraplate and Elasticsearch (new index mapping, update dimension settings, full re-embed).

`max_length: 512` in the API request is the **token truncation limit** for model input, not the embedding vector size.

---

## Install on the embedding host (Linux)

### System packages

```bash
sudo apt update
sudo apt install -y python3 python3-venv python3-pip git
```

Optional: NVIDIA driver + CUDA-compatible PyTorch for GPU inference under load.

### Python environment

```bash
mkdir -p ~/laraplate-embeddings && cd ~/laraplate-embeddings
python3 -m venv .venv
source .venv/bin/activate
pip install --upgrade pip
pip install sentence-transformers flask torch
```

Model weights download from Hugging Face on first use (~hundreds of MB per model). The service downloads each requested model the first time it is asked for and caches it.

### Example API server (multi-model)

Create `sentence-api.py`. It serves any model on demand: it keeps the `EMBEDDING_MODEL` default warm, lazy-loads any other model named in a request, and keeps up to `EMBEDDING_MODEL_CACHE` models resident (LRU).

```python
import os

from flask import Flask, jsonify, request
from sentence_transformers import SentenceTransformer

app = Flask(__name__)

# Default model, and how many models to keep resident. Laraplate sends the
# active profile's service_model in each request, so this default is only used
# when a request omits "model".
DEFAULT_MODEL = os.environ.get("EMBEDDING_MODEL", "intfloat/multilingual-e5-small")
MODEL_CACHE = max(1, int(os.environ.get("EMBEDDING_MODEL_CACHE", "2")))

# name -> SentenceTransformer, ordered oldest..newest (simple LRU by re-insert).
_models = {}


def load_model(name):
    name = name or DEFAULT_MODEL
    model = _models.pop(name, None)
    if model is None:
        model = SentenceTransformer(name)
    _models[name] = model  # mark as most-recently used
    while len(_models) > MODEL_CACHE:
        _models.pop(next(iter(_models)))
    return name, model


# Warm the default so the first embed request is not a cold load.
load_model(DEFAULT_MODEL)


@app.route("/health", methods=["GET"])
def health():
    return jsonify({
        "status": "healthy",
        "model": DEFAULT_MODEL,
        "default_model": DEFAULT_MODEL,
        "loaded_models": list(_models.keys()),
        "model_cache": MODEL_CACHE,
    })


@app.route("/embed", methods=["POST"])
def embed():
    try:
        data = request.get_json(silent=True) or {}

        if "texts" in data:
            texts = data["texts"]
        elif "text" in data:
            texts = [data["text"]]
        else:
            return jsonify({"error": "No text or texts provided"}), 400

        name, model = load_model(data.get("model"))
        normalize = bool(data.get("normalize_embeddings", True))
        embeddings = model.encode(texts, normalize_embeddings=normalize)

        return jsonify({
            "model": name,
            "embeddings": [embedding.tolist() for embedding in embeddings],
        })
    except Exception as exc:  # noqa: BLE001
        return jsonify({"error": str(exc)}), 500


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=8000)
```

### Run manually

```bash
source .venv/bin/activate
export EMBEDDING_MODEL=intfloat/multilingual-e5-small
export EMBEDDING_MODEL_CACHE=2
python sentence-api.py
```

### systemd unit (production)

```ini
# /etc/systemd/system/sentence-transformers.service
[Unit]
Description=Sentence Transformers API Service
After=network.target
Wants=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt
Environment=PATH=/opt/ai-env/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
Environment=EMBEDDING_MODEL=intfloat/multilingual-e5-small
Environment=EMBEDDING_MODEL_CACHE=2
ExecStart=/opt/ai-env/bin/python /opt/sentence-api.py
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl restart sentence-transformers
```

Keep `EMBEDDING_MODEL` equal to the active Laraplate profile's `service_model`. On a first request for a new model the service downloads and loads it (a few seconds to a minute); subsequent requests are fast while it stays in the LRU cache.

---

## Verify the embedding host

```bash
curl -s "http://127.0.0.1:8000/health"
# {"status":"healthy","model":"intfloat/multilingual-e5-small",
#  "default_model":"intfloat/multilingual-e5-small",
#  "loaded_models":["intfloat/multilingual-e5-small"],"model_cache":2}

# Default model (dimension check — expect 384):
curl -s -X POST "http://127.0.0.1:8000/embed" \
  -H "Content-Type: application/json" \
  -d '{"text":"hello","normalize_embeddings":true}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['model'], len(d['embeddings'][0]))"

# Explicit model (lazy-loads it, then reports it in the response and /health):
curl -s -X POST "http://127.0.0.1:8000/embed" \
  -H "Content-Type: application/json" \
  -d '{"text":"hello","model":"sentence-transformers/all-MiniLM-L6-v2"}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print(d['model'], len(d['embeddings'][0]))"
```

From the Laraplate application server, repeat the same checks against the remote IP/hostname and port.

---

## Configure Laraplate

Add or update `.env` on the Laravel host:

```env
AI_EMBEDDINGS_ENABLED=true
AI_EMBEDDINGS_PROVIDER=sentence_transformers

SENTENCE_TRANSFORMERS_URL=http://EMBEDDING_HOST:8000
SENTENCE_TRANSFORMERS_API_KEY=

AI_FAQ_ES_EMBEDDING_DIMS=384
```

The active embedding model is chosen in `config/` (`ai.features.embeddings.active`, default `multilingual-e5-small`), not in `.env`; Laraplate sends that profile's `service_model` to the service per request. Keep the service's `EMBEDDING_MODEL` default equal to it.

Notes:

- `AI_EMBEDDINGS_PROVIDER` accepts `sentence_transformers` or `sentence-transformers`.
- If omitted, the default is already `sentence_transformers`; the URL must still be reachable.
- Chat and translation use separate env vars (`AI_CHAT_PROVIDER`, `AI_TRANSLATION_PROVIDER`).

Ensure Core search vector settings match (`search.vector_search.dimension` = `384`). Enable Scout/vector search as in [SEARCH_AND_TRANSLATION.md](SEARCH_AND_TRANSLATION.md).

---

## After go-live

### Queue workers

Embedding generation uses `GenerateEmbeddingsJob`. Horizon or `queue:work` must run on the Laraplate host.

### Backfill existing content

```bash
php artisan ai:embeddings:repair "Modules\\CMS\\Models\\Content"
```

Replace the FQCN with each searchable model that defines `$embed`.

After dimension or model changes for RAG, run `php artisan ai:index-rag-docs --full`.

---

## Optional: cross-encoder reranker

Hybrid search can call a **separate** HTTP service for reranking (default `http://127.0.0.1:8001/score`, env `CROSS_ENCODER_ENDPOINT`). Not required for embeddings; if down, search returns unreranked results. Do not share a port with the embedding API.

---

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| Connection refused from Laravel | Wrong URL/port, firewall, or service not listening on `0.0.0.0` |
| HTTP 401 | `SENTENCE_TRANSFORMERS_API_KEY` mismatch with Python `API_KEY` |
| ES mapping / vector search errors | Embedding dimension ≠ index `dense_vector` dims |
| Keyword-only search documents | Embeddings job failed; check Horizon; run `ai:embeddings:repair` |
| `Embeddings count mismatch` | API returned fewer vectors than texts |

---

## Related documentation

| Document | Topic |
|----------|--------|
| [README.md](../README.md) | AI module env reference |
| [SEARCH_AND_TRANSLATION.md](SEARCH_AND_TRANSLATION.md) | Indexing flow and repair |
| [rag/DEPLOYMENT.md](rag/DEPLOYMENT.md) | RAG vector store |
| [Core EVENT_ORCHESTRATION.md](../../Core/docs/EVENT_ORCHESTRATION.md) | Search pre-processing |

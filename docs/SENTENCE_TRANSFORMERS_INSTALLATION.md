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
  "max_length": 512
}
```

### Response

```json
{
  "embeddings": [[0.1, 0.2, "..."], [0.3, 0.4, "..."]]
}
```

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
| `sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2` | 384 | Good default for multilingual CMS content |
| `sentence-transformers/all-MiniLM-L6-v2` | 384 | Lighter; English-centric |

If you switch to a model with a different output size, realign Laraplate and Elasticsearch (new index mapping, update dimension settings, full re-embed).

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
pip install sentence-transformers fastapi uvicorn[standard] torch
```

Model weights download from Hugging Face on first start (~hundreds of MB for MiniLM-class models).

### Example API server

Create `server.py`:

```python
import os
from typing import Optional

from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel
from sentence_transformers import SentenceTransformer

MODEL_NAME = os.getenv(
    "EMBEDDING_MODEL",
    "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2",
)
API_KEY = os.getenv("API_KEY", "")
HOST = os.getenv("HOST", "0.0.0.0")
PORT = int(os.getenv("PORT", "8003"))

app = FastAPI()
model = SentenceTransformer(MODEL_NAME)


class EmbedRequest(BaseModel):
    text: Optional[str] = None
    texts: Optional[list[str]] = None
    truncation: bool = True
    normalize_embeddings: bool = True
    max_length: int = 512


def check_auth(authorization: Optional[str]) -> None:
    if not API_KEY:
        return
    if not authorization or authorization != f"Bearer {API_KEY}":
        raise HTTPException(status_code=401, detail="Unauthorized")


@app.post("/embed")
def embed(req: EmbedRequest, authorization: Optional[str] = Header(default=None)):
    check_auth(authorization)

    if req.texts is not None:
        inputs = req.texts
    elif req.text is not None:
        inputs = [req.text]
    else:
        raise HTTPException(status_code=422, detail="Provide text or texts")

    embeddings = model.encode(
        inputs,
        normalize_embeddings=req.normalize_embeddings,
        truncate=req.truncation,
        batch_size=min(len(inputs), 128),
    )

    return {"embeddings": embeddings.tolist()}


@app.get("/health")
def health():
    return {
        "status": "ok",
        "model": MODEL_NAME,
        "dims": model.get_sentence_embedding_dimension(),
    }
```

### Run manually

```bash
source .venv/bin/activate
export EMBEDDING_MODEL=sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2
export PORT=8003
# export API_KEY=your-secret   # optional
uvicorn server:app --host 0.0.0.0 --port "$PORT"
```

### systemd unit (production)

```ini
# /etc/systemd/system/laraplate-embeddings.service
[Unit]
Description=Laraplate Sentence Transformers API
After=network.target

[Service]
User=embeddings
WorkingDirectory=/home/embeddings/laraplate-embeddings
Environment=EMBEDDING_MODEL=sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2
Environment=PORT=8003
Environment=API_KEY=
ExecStart=/home/embeddings/laraplate-embeddings/.venv/bin/uvicorn server:app --host 0.0.0.0 --port 8003
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now laraplate-embeddings
```

---

## Verify the embedding host

```bash
curl -s "http://127.0.0.1:8003/health"
# Expect dims: 384 for the recommended multilingual MiniLM model

curl -s -X POST "http://127.0.0.1:8003/embed" \
  -H "Content-Type: application/json" \
  -d '{"text":"hello","truncation":true,"normalize_embeddings":true,"max_length":512}' \
  | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['embeddings'][0]))"
```

From the Laraplate application server, repeat the same checks against the remote IP/hostname and port.

---

## Configure Laraplate

Add or update `.env` on the Laravel host:

```env
AI_EMBEDDINGS_ENABLED=true
AI_EMBEDDINGS_PROVIDER=sentence_transformers

SENTENCE_TRANSFORMERS_URL=http://EMBEDDING_HOST:8003
SENTENCE_TRANSFORMERS_API_KEY=

AI_FAQ_ES_EMBEDDING_DIMS=384
```

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

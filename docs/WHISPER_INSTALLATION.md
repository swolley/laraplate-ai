# Self-hosted Whisper for media transcription

Laraplate does not transcribe audio/video inside PHP. The AI module calls a
**self-hosted HTTP API** on a separate host (VM, LXC container, or bare metal —
e.g. a Proxmox guest). This guide covers that sidecar service and the Laraplate
configuration that points to it. It mirrors
[`SENTENCE_TRANSFORMERS_INSTALLATION.md`](SENTENCE_TRANSFORMERS_INSTALLATION.md).

---

## What uses the transcriber

Transcription is part of **media analysis** and is selected through the media
model registry, not a global provider variable.

| Config key (`ai.*`) | Purpose | Default |
|---------------------|---------|---------|
| `features.media_analysis.capabilities.transcription.active` | Which transcription profile runs | `whisper-local` |
| `providers.whisper.url` | Base URL of the Whisper service | *(unset = transcription off)* |
| `providers.whisper.api_key` | Optional Bearer token | *(unset = no auth)* |
| `providers.whisper.timeout` | Per-request timeout (seconds) | `120` |

With `WHISPER_URL` unset the `WhisperTranscriber` is a no-op: audio/video media
still index (deterministic layer + any other analysis), just without a transcript.

---

## Architecture

```text
[Laravel + Horizon]  --POST /transcribe (multipart file)-->  [Whisper host: FastAPI + faster-whisper]
     .env: WHISPER_URL=http://HOST:9000
```

The Laravel application needs outbound HTTP access to the Whisper host. Restrict
inbound access on the Whisper host to trusted clients (VPN, private network, or
firewall rules), and set `WHISPER_API_KEY` for a shared-secret Bearer check.

---

## HTTP contract (required)

Laraplate posts to `{base_url}/transcribe` (no trailing slash on the base URL).

```http
POST /transcribe
Authorization: Bearer <WHISPER_API_KEY>   # only when a key is configured
Content-Type: multipart/form-data

file=<the media bytes>        # form field name MUST be "file"
```

Response:

```json
{ "text": "the transcript in the spoken language", "language": "en", "duration": 12.3 }
```

Only `text` is required by Laraplate; `language`/`duration` are informational. Any
non-2xx response makes the transcriber return `null` (the media still indexes).

---

## Reference service (faster-whisper + FastAPI)

[faster-whisper](https://github.com/SYSTRAN/faster-whisper) (CTranslate2) runs
well on CPU and scales to GPU. Create these three files on the Whisper host.

`app.py`:

```python
import os, tempfile
from faster_whisper import WhisperModel
from fastapi import FastAPI, File, Header, HTTPException, UploadFile

MODEL_SIZE = os.getenv("WHISPER_MODEL", "base")
DEVICE = os.getenv("WHISPER_DEVICE", "cpu")
COMPUTE_TYPE = os.getenv("WHISPER_COMPUTE_TYPE", "int8")
API_KEY = os.getenv("WHISPER_API_KEY", "")

model = WhisperModel(MODEL_SIZE, device=DEVICE, compute_type=COMPUTE_TYPE)
app = FastAPI(title="Laraplate Whisper service")

@app.get("/health")
def health() -> dict:
    return {"status": "ok", "model": MODEL_SIZE, "device": DEVICE}

@app.post("/transcribe")
async def transcribe(file: UploadFile = File(...), authorization: str = Header(default=""),
                     language: str | None = None) -> dict:
    if API_KEY and authorization != f"Bearer {API_KEY}":
        raise HTTPException(status_code=401, detail="unauthorized")
    suffix = os.path.splitext(file.filename or "")[1] or ".bin"
    with tempfile.NamedTemporaryFile(suffix=suffix) as tmp:
        tmp.write(await file.read()); tmp.flush()
        segments, info = model.transcribe(tmp.name, language=language)
        text = " ".join(s.text.strip() for s in segments).strip()
    return {"text": text, "language": info.language, "duration": info.duration}
```

`requirements.txt`:

```text
fastapi==0.115.*
uvicorn[standard]==0.32.*
faster-whisper==1.0.*
python-multipart==0.0.*
```

`Dockerfile`:

```dockerfile
FROM python:3.12-slim
RUN apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py .
ENV WHISPER_MODEL=base WHISPER_DEVICE=cpu WHISPER_COMPUTE_TYPE=int8
EXPOSE 9000
CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "9000"]
```

---

## Install on Proxmox

### Option A — Docker (in an LXC or VM)

```bash
docker build -t laraplate-whisper .
docker run -d --name whisper --restart unless-stopped -p 9000:9000 \
  -e WHISPER_MODEL=base -e WHISPER_API_KEY=change-me \
  -v whisper-models:/root/.cache/huggingface \
  laraplate-whisper
```

The model downloads on first start and is cached in the `whisper-models` volume.
For GPU passthrough, run with `--gpus all -e WHISPER_DEVICE=cuda -e WHISPER_COMPUTE_TYPE=float16`.

### Option B — systemd (no Docker, e.g. a Debian LXC)

```bash
apt install -y python3-venv ffmpeg
python3 -m venv /opt/whisper/.venv && /opt/whisper/.venv/bin/pip install -r requirements.txt
# copy app.py to /opt/whisper/
```

`/etc/systemd/system/whisper.service`:

```ini
[Unit]
Description=Laraplate Whisper service
After=network.target

[Service]
WorkingDirectory=/opt/whisper
Environment=WHISPER_MODEL=base
Environment=WHISPER_API_KEY=change-me
ExecStart=/opt/whisper/.venv/bin/uvicorn app:app --host 0.0.0.0 --port 9000
Restart=always

[Install]
WantedBy=multi-user.target
```

```bash
systemctl enable --now whisper
curl -s -H "Authorization: Bearer change-me" -F file=@sample.mp3 http://localhost:9000/transcribe
```

---

## Model sizes

| `WHISPER_MODEL` | Notes |
|-----------------|-------|
| `tiny` / `base` | Fast, low RAM; `base` is the default and transcribes faster-than-realtime on a modern CPU core. |
| `small` / `medium` | Better accuracy, higher CPU/RAM cost. |
| `large-v3` | Best accuracy; prefer GPU. |

Pick the model for your latency/quality budget; change it with `WHISPER_MODEL` and restart.

---

## Wire it into Laraplate

`laraplate/.env`:

```dotenv
WHISPER_URL=http://HOST:9000
WHISPER_API_KEY=change-me
AI_MEDIA_TRANSCRIPTION_MODEL=whisper-local
```

Verify: analyze an audio/video media and confirm the `ai_media_analyses` row for its
`content_hash` has a non-empty `transcript`. If `WHISPER_URL` is wrong or the service
is down, transcription silently degrades to `null` and the rest of the analysis still runs.

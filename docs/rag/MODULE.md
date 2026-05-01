# Modulo AI — ricerca intelligente, conversazione e supporto semantico

## In parole semplici

Il modulo **AI** aggiunge a Laraplate capacità legate a **modelli linguistici**, **embedding** (rappresentazioni vettoriali del testo) e **ricerca avanzata**. Non “sostituisce” il database relazionale: lo affianca per compiti come capire l’intento di una query, riordinare i risultati per rilevanza (reranking) o offrire **chat** contestuali con allegato supporto documentale (RAG) dove configurato.

Il modulo è registrato con **priorità alta** (`module.json`, priorità 999) così da poter **sovrascrivere** i binding di ricerca definiti nel Core quando la funzionalità è attiva.

## A chi serve

- **Sviluppatore**: integra provider LLM, gestisce conversazioni, messaggi, job di embedding e orchestrazione ricerca; consulta `Modules/AI/docs/ARCHITECTURE.md` per diagrammi di flusso e dettagli API.
- **Utente tecnico**: abilita/disabilita feature via configurazione (`config/ai.php` e flag come `ai.features.search_orchestration.enabled`), monitora code e costi dei provider esterni.
- **Utente business**: interagisce con assistenti o ricerca “intelligente” nell’interfaccia; non deve preoccuparsi dei dettagli di streaming SSE o dei token, ma deve sapere che le risposte sono **probabilistiche** e vanno validate per decisioni critiche.

## Funzionalità principali

### Arricchimento del motore di ricerca (integrazione Core)

Nel `AIServiceProvider`, se l’orchestrazione di ricerca è abilitata, Laravel riceve implementazioni concrete per i contratti definiti nel Core:

| Contratto Core | Implementazione AI (tipica) | Ruolo |
|----------------|-----------------------------|--------|
| `ITextEmbedder` | `SearchEmbedder` | Trasforma testo e query in vettori per similarità semantica |
| `IQueryIntentParser` | `LlmQueryIntentParser` | Interpreta la domanda dell’utente (es. filtri impliciti) |
| `ISearchPlanner` | `SearchOrchestratorAgent` | Pianifica passi di ricerca (query classiche + semantica) |
| `IReranker` | `CrossEncoderService` | Riordina i candidati per rilevanza fine |

Effetto pratico: le schermate o le API che usano il sistema di ricerca unificato del Core possono **migliorare qualità e recall** senza riscrivere i consumer: cambiano i binding dietro le quinte.

### Chat, messaggi e (opzionalmente) RAG

Il modulo gestisce **conversazioni** e **messaggi** persistiti, con supporto a:

- Risposte **in streaming** (SSE) per UX fluida nelle UI moderne.
- Risposte **non streaming** per job, test o integrazioni che richiedono JSON completo.

Quando il modulo RAG/documentazione è disponibile e la domanda è classificabile come tale, il flusso può arricchire la risposta con **citazioni** a frammenti documentali: utile per help desk interno o knowledge base.

### Tool e azioni strutturate (ActionRequest)

È presente un sottosistema per **richieste di azione** orchestrate dal modello (tool calling). La documentazione in `Modules/AI/docs/ARCHITECTURE.md` indica che **parte del percorso non è ancora esposta** come API pubblica stabile: uno sviluppatore deve verificare nel codice lo stato attuale degli endpoint prima di integrare client esterni.

### Traduzione e memoria

Il modulo include servizi per **traduzione automatica** e meccanismi di **memoria / riassunto** conversazionale (vedi indice in `ARCHITECTURE.md`). L’obiettivo è mantenere contesto lungo senza superare i limiti di finestra del modello.

## Come si usa in pratica

1. **Configurazione**: impostare chiavi e endpoint dei provider (OpenAI, Anthropic, Ollama, Mistral, ecc.) nelle variabili d’ambiente previste dal progetto; verificare limiti di rate e logging.
2. **Ricerca**: assicurarsi che `ai.features.search_orchestration.enabled` sia coerente con l’ambiente (in sviluppo si può disattivare per ridurre costi o dipendenze esterne).
3. **Chat in produzione**: eseguire worker di coda per job di embedding e task asincroni; non invocare embedding sincroni nelle richieste HTTP ad alto traffico.
4. **Compliance**: definire policy su dati personali nei prompt, retention conversazioni e anonimizzazione log.

## Dipendenze

- **Core**: fornisce contratti di ricerca (`Modules\Core\Search\Contracts\...`) e infrastruttura comune.
- Provider esterni (LLM, API embedding): necessitano connettività e budget operativo.

## Approfondimenti

Per implementatori, oltre a questo sommario in italiano:

- `Modules/AI/docs/ARCHITECTURE.md` — flussi chat, streaming, RAG, stato delle API.
- `Modules/AI/docs/DESIGN_DECISIONS.md` — scelte progettuali.
- `Modules/AI/docs/TOOLS_USAGE_EXAMPLE.md` — esempi d’uso dei tool dove applicabile.

## Indicizzare la documentazione di tutti i moduli (RAG)

Il comando `php artisan ai:index-docs` indicizza **un albero di file** alla volta. La convenzione del repository è documentata in `docs/README.md`; per copie opzionali sotto `docs/rag/` vedi `docs/rag/README.md`. Senza `--path`, i documenti RAG dei moduli attivi vengono inclusi automaticamente da `Modules/{Nome}/docs/rag/`.

## Limiti da comunicare agli stakeholder

Le risposte generate da modelli AI possono essere **imprecise** o **obsolete**. Per decisioni legali, mediche, di sicurezza o finanziarie materiali, il modulo va considerato un **supporto**, non un’unica fonte di verità.

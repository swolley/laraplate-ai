# Cursor Rules per il modulo AI

Questa cartella contiene le regole di configurazione per Cursor relative **al modulo AI** di `nwidart/laravel-modules`.
Le regole sono pensate per:
- lavorare nel contesto di un **modulo Laravel**, non di un'app completa
- riutilizzare i principi globali definiti a livello di root
- aggiungere il contesto specifico del modulo AI, che si occupa di funzionalità di intelligenza artificiale (chat, embedding, documentazione, ecc.).

## Formato file

I file utilizzano l'estensione `.mdc` (Markdown Configuration) con frontmatter YAML per controllare quando vengono applicati:
- `alwaysApply: true` – applicato sempre (nel contesto del modulo)
- `globs: ["**/*.php"]` – applicato solo quando si lavora con file che rispettano il pattern

## Struttura dei file di regole nel modulo AI

### 00-master.mdc (Always Applied)
- File master leggero che referenzia le altre regole del modulo
- Principi chiave sempre attivi nel contesto del modulo AI

### laravel-boost.mdc (Always Applied, definito a root)
- **Regola principale** – linee guida complete per l'ecosistema Laravel (valide per tutta l'applicazione)
- Copre: Filament, Livewire, Pest, Pint, PHPStan, Tailwind, ecc.
- **Non duplicare contenuti già presenti qui**: i file del modulo AI devono solo specializzare dove necessario.

### 01-php-laravel-standards.mdc (Contextual: PHP files)
- Standard PHP e Laravel specifici
- Convenzioni di naming
- Dichiarazioni di tipo
- Valido per tutti i file PHP del modulo AI
- **Riferimenti a `laravel-boost.mdc` per contenuti duplicati**

### 02-architecture-patterns.mdc (Contextual: Controllers, Services, Models)
- Pattern di design
- Architettura del codice
- Regole pensate per servizi, controller e modelli **all'interno dei moduli**
- **Riferimenti a `laravel-boost.mdc` per best practices Laravel**

### 03-performance-optimization.mdc (Contextual: Models, Services, Jobs, Migrations)
- Strategie di caching
- Ottimizzazione database
- Linee guida per code e job nel contesto modulare
- **Riferimenti a `laravel-boost.mdc` per Eloquent e query builder**

### 04-error-handling-security.mdc (Contextual: Controllers, Middleware, Exceptions, Requests)
- Gestione degli errori (Laravel 12 context)
- Best practices di sicurezza
- Focalizzato su controller, middleware ed eccezioni del modulo
- **Riferimenti a `laravel-boost.mdc` per validazione e sicurezza**

### 05-testing-development.mdc (Contextual: Test files)
- Strategie di testing con Pest
- Organizzazione dei test **all'interno di ogni modulo** (es. `Modules/AI/tests`)
- Strumenti di sviluppo
- **Riferimenti a `laravel-boost.mdc` per Filament, Telescope, ecc.**

### 06-coding-principles.mdc (Always Applied)
- Principi generali di coding (minimali), condivisi tra root e moduli
- Modifiche al codice e bugfix
- Lingua e comunicazione (chat in Italiano, codice e commenti in English)
- **Solo contenuti unici, non duplicati rispetto a `laravel-boost.mdc`**

### 07-laraplate-specific.mdc (Contextual: Module files)
- Regole specifiche per il modulo AI (chat, completions, embedding, documentazione, ecc.)
- Come il modulo AI si appoggia al Core e agli altri moduli
- Standard di sviluppo per funzionalità AI e integrazioni esterne
- **Riferimenti ad altre regole per contenuti generici**

## Linee guida generali

1. **Eliminazione duplicazioni**: preferisci riferimenti a `laravel-boost.mdc` invece di copiare intere sezioni.
2. **Contestualizzazione modulare**: usa `globs` per applicare regole solo quando rilevanti nel modulo.
3. **Master leggero**: `00-master.mdc` serve come entry point minimale per il modulo AI.
4. **Ruolo dell'AI**: ricorda che il modulo AI fornisce funzionalità di intelligenza artificiale riutilizzabili (chat, embedding, suggerimenti, documentazione) costruite sopra i servizi del Core.

## Manutenzione

1. **Per modifiche generali di ecosistema**: modifica `laravel-boost.mdc` a livello root (regola principale).
2. **Per modifiche specifiche del modulo AI**: modifica il file appropriato in `Modules/AI/.cursor/rules/`.
3. **Evita duplicazioni**: se un contenuto esiste già in `laravel-boost.mdc`, fai riferimento a quello invece di duplicare.
4. **Mantieni contestualizzazione modulare**: usa `globs` per applicare regole solo quando rilevanti nel contesto del modulo AI.


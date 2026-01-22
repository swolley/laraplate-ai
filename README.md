<p>&nbsp;</p>
<p align="center">
	<a href="https://github.com/swolley" target="_blank">
		<img src="https://raw.githubusercontent.com/swolley/images/refs/heads/master/logo_laraplate.png?raw=true" width="400" alt="Laraplate Logo" />
    </a>
</p>
<p>&nbsp;</p>

> ⚠️ **Caution**: This package is a **work in progress**. **Don't use this in production or use at your own risk**—no guarantees are provided... or better yet, collaborate with me to create the definitive Laravel boilerplate; that's the right place to instroduce your ideas. Let me know your ideas...

## Table of Contents

-   [Description](#description)
-   [Installation](#installation)
-   [Configuration](#configuration)
-   [Features](#features)
-   [Architecture](#architecture)
-   [Scripts](#scripts)
-   [Contributing](#contributing)
-   [License](#license)

## Description

The AI Module provides artificial intelligence capabilities for embeddings generation, vector search, and automatic translation. This module is **optional** and can be activated/deactivated independently. When disabled, the application continues to function normally without AI features.

**Key Features:**
- ✨ Embeddings generation for vector search
- 🌐 Automatic translation (AI-powered and DeepL)
- 🔄 Event-driven architecture for seamless integration
- 🎯 Zero dependencies from Core/Cms modules (Core never depends on AI)

## Installation

If you want to add this module to your project, you can use the `joshbrw/laravel-module-installer` package.

Add repository to your `composer.json` file:

```json
"repositories": [
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-core.git"
    },
    {
        "type": "composer",
        "url": "https://github.com/swolley/laraplate-ai.git"
    }
]
```

```bash
composer require joshbrw/laravel-module-installer swolley/laraplate-core swolley/laraplate-ai
```

Then, you can install the module by running the following command:

```bash
php artisan module:install Core
php artisan module:install AI
```

## Configuration

The AI module configuration is automatically mapped as `ai.*` when the module is active. Configuration file: `Modules/AI/config/config.php`.

```env
# AI Features
AI_EMBEDDINGS_ENABLED=true          # Enable embeddings generation
AI_TRANSLATION_ENABLED=true          # Enable automatic translation

# AI Provider (default: ollama)
AI_PROVIDER=ollama                   # Options: ollama, openai, voyageai, mistral, sentence-transformers

# OpenAI Configuration
OPENAI_API_KEY=                      # OpenAI API key
OPENAI_API_URL=                      # OpenAI compatible API URL (optional)
OPENAI_MODEL=                        # OpenAI model (e.g., gpt-3.5-turbo, text-embedding-3-small)

# Ollama Configuration
OLLAMA_API_URL=http://localhost:11434  # Ollama API URL
OLLAMA_MODEL=llama3.2:3b            # Ollama model for embeddings/translation

# VoyageAI Configuration
VOYAGEAI_API_KEY=                    # VoyageAI API key
VOYAGEAI_MODEL=voyage-3-lite        # VoyageAI model

# Mistral Configuration
MISTRAL_API_KEY=                     # Mistral API key
MISTRAL_MODEL=mistral-large-latest  # Mistral model

# Sentence Transformers Configuration
SENTENCE_TRANSFORMERS_URL=http://localhost:8000  # Sentence Transformers API URL
SENTENCE_TRANSFORMERS_API_KEY=       # Sentence Transformers API key (optional)

# DeepL Configuration (for automatic translation)
DEEPL_API_KEY=                       # DeepL API key
```

### Module Priority

The AI module has priority **999** (loaded after Core and Cms) to ensure proper event listener registration order.

## Features

### Requirements

-   PHP >= 8.5
-   Laravel 12.0+
-   **Core Module** (mandatory dependency)
-   **PHP Extensions:**
    -   `ext-curl`: For HTTP requests to AI providers
    -   `ext-json`: For JSON serialization

### Installed Packages

The AI Module utilizes several packages to enhance its functionality:

-   **Embeddings:**
    -   [theodo-group/llphant](https://github.com/theodo-group/llphant): Embedding generation for multiple providers (OpenAI, Ollama, VoyageAI, Mistral)

-   **Development and Testing:**
    -   [pestphp/pest](https://github.com/pestphp/pest): Testing framework
    -   [laravel/pint](https://github.com/laravel/pint): Code style fixer

### Supported AI Providers

#### Embeddings Generation
- **OpenAI**: `text-embedding-3-small`, `text-embedding-3-large`, `text-embedding-ada-002`
- **Ollama**: `nomic-embed-text`, `nomic-embed-large` (and custom models)
- **VoyageAI**: `voyage-3`, `voyage-3-large`, `voyage-3-lite`, `voyage-code-2`, `voyage-code-3`, `voyage-finance-2`, `voyage-law-2`
- **Mistral**: Mistral embedding models
- **Sentence Transformers**: Self-hosted Sentence Transformers API

#### Automatic Translation
- **OpenAI**: GPT models for translation
- **Ollama**: Local LLM models for translation
- **Mistral**: Mistral models for translation
- **DeepL**: Professional translation service (considered AI-powered)

### Additional Functionalities

The AI Module includes built-in features such as:

-   **Embeddings Generation:**
    - Automatic embeddings generation for searchable models
    - Multilingual embeddings (concatenates all available translations)
    - Vector search integration with Elasticsearch and Typesense
    - Batch processing for large documents

-   **Automatic Translation:**
    - Automatic translation on model creation/update
    - Support for multiple translation providers
    - Translation caching for performance
    - Fallback mechanisms for failed translations
    - DeepL integration for professional translations

-   **Event-Driven Architecture:**
    - `ModelRequiresIndexing`: Event emitted when a model needs indexing
    - `ModelPreProcessingCompleted`: Event emitted when pre-processing (embeddings/translation) completes
    - `TranslatedModelSaved`: Event emitted when a model with translations is saved
    - Seamless integration with Core module's search functionality

-   **Modular Design:**
    - Zero dependencies from Core/Cms (Core never knows about AI)
    - Can be disabled without breaking application functionality
    - Extensible architecture for future AI features

## Architecture

### Event-Driven Integration

The AI module integrates with Core through a clean event-driven architecture:

1. **Model Indexing Flow:**
   ```
   Searchable trait → ModelRequiresIndexing event
   ↓
   HandleModelIndexingListener (AI) → adds 'embeddings' to required_pre_processing
   ↓
   GenerateEmbeddingsJob → ModelPreProcessingCompleted('embeddings')
   ↓
   FinalizeModelIndexingListener (Core) → checks all pre-processing completed
   ↓
   IndexInSearchJob → model indexed in search engine
   ```

2. **Translation Flow:**
   ```
   HasTranslations trait → TranslatedModelSaved event
   ↓
   HandleModelTranslationListener (AI) → TranslateModelJob
   ↓
   If model is searchable → registers 'translation' in ModelRequiresIndexing
   ↓
   TranslateModelJob → ModelPreProcessingCompleted('translation')
   ↓
   FinalizeModelIndexingListener (Core) → finalizes indexing
   ```

### Decoupling Strategy

- **Core** never imports classes from **AI**
- **AI** listens to events from **Core**
- Configuration-based model class resolution (no hardcoded dependencies)
- Service container bindings for optional features

### Fallback Behavior

When the AI module is disabled:
- Embeddings generation is skipped (vector search disabled)
- Automatic translation is disabled (manual translation still works)
- Core's `IndexModelFallbackListener` handles indexing without pre-processing
- Application continues to function normally

## Scripts

The AI Module provides several useful scripts for development and maintenance:

### Code Quality and Testing

```bash
# Run all tests and quality checks
composer test

# Run specific test suites
composer test:unit          # Run unit tests with coverage
composer test:type-coverage # Check type coverage (target: 100%)
composer test:typos         # Check for typos in code
composer test:lint          # Check code style
composer test:types         # Run PHPStan analysis
composer test:refactor      # Run Rector refactoring
```

### Code Quality Tools

```bash
# Code style and IDE helpers
composer lint               # Fix code style and generate IDE helpers

# Static analysis
composer check              # Run PHPStan analysis
composer fix                # Run PHPStan analysis with auto-fix
composer refactor           # Run Rector refactoring
```

### Version Management

```bash
# Version bumping
composer version:major      # Bump major version
composer version:minor      # Bump minor version
composer version:patch      # Bump patch version
```

### Development Setup

```bash
# Setup Git hooks
composer setup:hooks
```

## Contributing

If you want to contribute to this project, follow these steps:

1. Fork the repository.
2. Create a new branch for your feature or correction.
3. Send a pull request.

## License

AI Module is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## TODO and FIXME

This section tracks all pending tasks and issues that need to be addressed in the AI Module.

### High Priority

- [ ] **AI Agent for FAQ Generation**
  - Implement AI agent that analyzes application code
  - Generate FAQ documentation automatically
  - Use vector storage for FAQ search

### Medium Priority

- [ ] **Per-Module Feature Activation**
  - Implement `features.*.modules` configuration
  - Allow enabling embeddings/translation per specific module
  - Currently commented in config for future implementation

### Low Priority

- [ ] **Additional AI Providers**
  - Support for more embedding providers
  - Support for more translation providers
  - Provider abstraction layer improvements

### Notes

- The module is designed to be extracted as a standalone package
- Future plans include making it installable via Composer
- Consider making it a paid package option
- Architecture supports easy extension with new AI features
